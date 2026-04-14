<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 */

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\User;
use App\Module\BdDailyReportNotifier;
use App\Module\HolidayClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 工作日 18:00 扫当日父任务下所有未完成子任务，对负责人推送站内消息 + 邮件。
 */
class BdDailyReportRemind extends Command
{
    protected $signature = 'bd-daily-report:remind
        {--dry-run : 仅打印待催交名单，不发送}
        {--date= : 覆盖日期（YYYY-MM-DD），默认为今天}';

    protected $description = '[BD 日报] 对未完成子任务的负责人发送站内 + 邮件催交';

    public function handle(): int
    {
        $tz = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $date = $date->startOfDay();
        $dateStr = $date->format('Y-m-d');

        // 非工作日不催
        $offInfo = HolidayClient::isOffDay($date);
        if ($offInfo['is_off']) {
            $this->info("[{$dateStr}] 非工作日，跳过催交");
            return 0;
        }

        $projectName = (string) config('bd_daily_report.project_name');
        $project = Project::where('name', $projectName)->whereNull('archived_at')->first();
        if (!$project) {
            BdDailyReportNotifier::alert("项目 '{$projectName}' 不存在，催交跳过");
            return 1;
        }

        $taskTitle = "{$dateStr} BD 日报";
        $parent = ProjectTask::whereProjectId($project->id)
            ->where('name', $taskTitle)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->first();
        if (!$parent) {
            BdDailyReportNotifier::alert("当日父任务 '{$taskTitle}' 未找到，催交跳过（是否早上 9 点创建步骤失败？）");
            return 1;
        }

        // 拉所有未完成子任务
        $pendingSubs = ProjectTask::where('parent_id', $parent->id)
            ->whereNull('complete_at')
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->get();

        if ($pendingSubs->isEmpty()) {
            $this->info("[{$dateStr}] 全员已提交，无需催交");
            Log::info("[BdDailyReport] {$dateStr} all submitted, no remind");
            return 0;
        }

        // 收集未完成子任务的负责人（owner=1）
        $subIds = $pendingSubs->pluck('id')->toArray();
        $ownerRows = ProjectTaskUser::whereIn('task_id', $subIds)
            ->where('owner', 1)
            ->get();
        $taskOwnerMap = []; // task_id => [userid, ...]
        foreach ($ownerRows as $row) {
            $taskOwnerMap[$row->task_id][] = (int) $row->userid;
        }

        $urlBase = rtrim((string) config('bd_daily_report.task_url_base'), '/');
        $sent = 0;
        $failed = [];

        foreach ($pendingSubs as $sub) {
            $owners = $taskOwnerMap[$sub->id] ?? [];
            foreach ($owners as $uid) {
                $user = User::whereUserid($uid)->whereNull('disable_at')->first();
                if (!$user) continue;

                $taskLink = $urlBase !== ''
                    ? "{$urlBase}/single/task/{$sub->id}"
                    : "（任务 #{$sub->id}）";
                $title = '日报未提交提醒';
                $contentTxt = "今日日报『{$sub->name}』尚未提交，请于 23:59 前完成：{$taskLink}";

                if ($this->option('dry-run')) {
                    $this->line("[DRY-RUN] -> {$user->nickname} ({$user->email}) | sub_id={$sub->id}");
                    continue;
                }

                $dialogOk = BdDailyReportNotifier::sendDialogMsg($user->userid, $title, $contentTxt);
                $emailHtml = '<p>' . htmlspecialchars($contentTxt, ENT_QUOTES) . '</p>';
                $emailOk = BdDailyReportNotifier::sendEmail($user, '【留学渠道】今日日报未提交', $emailHtml);

                if ($dialogOk || $emailOk) {
                    $sent++;
                } else {
                    $failed[] = "{$user->nickname}(uid={$user->userid})";
                }
            }
        }

        $this->info("[{$dateStr}] 催交完成：成功 {$sent} 条，失败 " . count($failed) . " 条");
        Log::info("[BdDailyReport] {$dateStr} remind done: sent={$sent}, failed=" . count($failed));

        if (!empty($failed)) {
            BdDailyReportNotifier::alert(
                "[{$dateStr}] 催交部分失败：" . implode(', ', $failed) . "（详情看 laravel.log）"
            );
        }
        return 0;
    }
}
