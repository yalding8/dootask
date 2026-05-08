<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 */

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Module\HolidayClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * 归档 BD 日报主任务（子任务级联归档）。
 *
 * 双路径：
 *   1. 默认路径：归档 N 天前已完成（complete_at NOT NULL）的任务，防止无限积累
 *   2. --include-incomplete：归档前一日仍未完成（complete_at IS NULL）的任务
 *      用于 next-morning silent archive，保留 "未完成" 事实供 audit（不覆盖 complete_at）
 *
 * 调用：
 *   php artisan bd-daily-report:cleanup --days=7
 *   php artisan bd-daily-report:cleanup --days=7 --dry-run
 *   php artisan bd-daily-report:cleanup --include-incomplete           # 仅归档昨日未完成
 *   php artisan bd-daily-report:cleanup --days=7 --include-incomplete  # 两路径都跑
 */
class BdDailyReportCleanup extends Command
{
    protected $signature = 'bd-daily-report:cleanup
        {--days=7 : 归档 N 天前已完成的任务}
        {--include-incomplete : 同时归档前一日 complete_at IS NULL 的任务（next-morning silent archive）}
        {--dry-run : 仅打印待归档任务，不落库}';

    protected $description = '[BD 日报] 归档已完成 / 漏卡的日报任务，防止无限积累';

    public function handle(): int
    {
        $days              = (int) $this->option('days');
        $dryRun            = (bool) $this->option('dry-run');
        $includeIncomplete = (bool) $this->option('include-incomplete');
        $tz                = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $now               = Carbon::now($tz);

        // 解析目标项目
        $projectId   = (int) config('bd_daily_report.project_id', 0);
        $projectName = (string) config('bd_daily_report.project_name');
        if ($projectId > 0) {
            $project = Project::whereId($projectId)->whereNull('archived_at')->first();
        } else {
            $project = Project::where('name', $projectName)->whereNull('archived_at')->first();
        }

        if (!$project) {
            $this->error('[BD cleanup] 项目未找到，检查 BD_REPORT_PROJECT_ID / BD_REPORT_PROJECT 配置');
            return 1;
        }

        $totalArchived = 0;

        // ─── 路径 1：归档已完成超过 N 天的任务 ───
        $cutoff = $now->copy()->subDays($days)->endOfDay();
        $completedTasks = ProjectTask::where('project_id', $project->id)
            ->where('parent_id', 0)
            ->whereNotNull('complete_at')
            ->where('complete_at', '<=', $cutoff)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->get();

        if ($completedTasks->isEmpty()) {
            $this->info("[BD cleanup] 路径 1：无已完成超 {$days} 天的待归档任务（cutoff={$cutoff})");
        } else {
            $this->info("[BD cleanup] 路径 1：已完成超期 {$completedTasks->count()} 条" . ($dryRun ? ' [dry-run]' : ''));
            $totalArchived += $this->archiveTasks($completedTasks, $dryRun, '已完成超期');
        }

        // ─── 路径 2：归档前一日未完成的任务（next-morning silent archive）───
        if ($includeIncomplete) {
            // 节假日跳过：今日如果是节假日（含调休），则不归档（与 BdDailyReportCreate 同策略）
            $offInfo = HolidayClient::isOffDay($now);
            if ($offInfo['is_off']) {
                $this->info("[BD cleanup] 路径 2：今日 {$now->format('Y-m-d')} 为节假日（{$offInfo['reason']}），跳过 incomplete 归档");
            } else {
                // 工作日 08:55 触发，归档"今日 0 点之前创建 + 仍未完成 + 未归档"的所有任务
                // 由于 BdDailyReportCreate 09:00 才创建今日任务，08:55 时今日尚无任务，本查询天然只命中历史漏卡
                $todayStart = $now->copy()->startOfDay();
                $incompleteTasks = ProjectTask::where('project_id', $project->id)
                    ->where('parent_id', 0)
                    ->whereNull('complete_at')
                    ->where('created_at', '<', $todayStart)
                    ->whereNull('archived_at')
                    ->whereNull('deleted_at')
                    ->get();

                if ($incompleteTasks->isEmpty()) {
                    $this->info("[BD cleanup] 路径 2：无前一日未完成待归档任务");
                } else {
                    $this->info("[BD cleanup] 路径 2：前一日未完成 {$incompleteTasks->count()} 条" . ($dryRun ? ' [dry-run]' : ''));
                    $totalArchived += $this->archiveTasks($incompleteTasks, $dryRun, '漏卡未完成');
                }
            }
        }

        if (!$dryRun) {
            $this->info("[BD cleanup] 完成：归档 {$totalArchived} 条主任务（含子任务级联）");
        }

        return 0;
    }

    /**
     * 归档一组主任务 + 级联归档其下未归档子任务。
     * 直接写 DB，绕过 ProjectTask::archivedTask() 的 WebSocket push（CLI 无 Swoole 上下文）。
     * 注意：仅设 archived_at，不改 complete_at——保留"是否真完成"事实供 audit。
     */
    private function archiveTasks($tasks, bool $dryRun, string $reasonTag): int
    {
        $archived = 0;
        $now      = Carbon::now();

        foreach ($tasks as $task) {
            if ($dryRun) {
                $this->line("  [dry-run/{$reasonTag}] task#{$task->id} {$task->name}");
                continue;
            }
            $task->archived_at     = $now;
            $task->archived_userid = 0;   // 0 表示系统自动
            $task->archived_follow = 0;
            $task->save();
            // 级联归档未归档的子任务（BD 日报本期已删子任务，本支防御性保留以兼容历史/其他场景）
            ProjectTask::whereParentId($task->id)
                ->whereNull('archived_at')
                ->update([
                    'archived_at'     => $now,
                    'archived_userid' => 0,
                    'archived_follow' => 0,
                ]);
            $archived++;
        }
        return $archived;
    }
}
