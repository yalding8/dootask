<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * BD 日报企微群三段推送共享逻辑:
 *  - 查询当日日报任务 + owner + flow 状态
 *  - 按 flow_item.status 分类 (start=未打卡, progress/test=已打卡, end=已完成)
 *  - 昵称列表渲染为 markdown 假 @ (加粗)
 *  - 推送或 dry-run 分发
 *
 * 真 @ 需 DooTask user ↔ 企微 userid 映射 (M10 Phase 3 OAuth2), 当前未实装.
 * 复用 WechatBusinessNotifier::send(title, content), 接受 features.wechat_webhook_enabled 总开关.
 */

namespace App\Module;

use App\Models\Project;
use App\Models\ProjectTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BdDailyReportWechatHelper
{
    /**
     * 查询当日 BD 日报主任务 (parent_id=0) + owner + flow_item.status.
     *
     * @return Collection<int, object{task_id:int, userid:int, nickname:string, flow_status:?string, complete_at:?string}>
     *   flow_status 可能为 'start' | 'progress' | 'test' | 'end' | null (flow_item_id=0 时)
     */
    public static function fetchTodayTasks(Carbon $date): Collection
    {
        $project = self::resolveProject();
        if (!$project) {
            return collect();
        }

        $dateStr = $date->format('Y-m-d');

        return DB::table('project_tasks as t')
            ->leftJoin('project_flow_items as pfi', 'pfi.id', '=', 't.flow_item_id')
            ->join('project_task_users as ptu', function ($j) {
                $j->on('ptu.task_id', '=', 't.id')->where('ptu.owner', 1);
            })
            ->join('users as u', 'u.userid', '=', 'ptu.userid')
            ->where('t.project_id', $project->id)
            ->where('t.parent_id', 0)
            ->where('t.name', 'like', "%{$dateStr} 日报")
            ->whereNull('t.archived_at')
            ->whereNull('t.deleted_at')
            ->select([
                't.id as task_id',
                'u.userid',
                'u.nickname',
                'pfi.status as flow_status',
                't.complete_at',
            ])
            ->get();
    }

    /**
     * 未打卡 = flow_status 为 'start' 或 NULL (孤儿任务, AI Red Team 场景 1).
     */
    public static function filterUncheckedIn(Collection $tasks): Collection
    {
        return $tasks->filter(fn($t) => $t->flow_status === 'start' || $t->flow_status === null);
    }

    /**
     * 未完成 = complete_at IS NULL.
     */
    public static function filterUnfinished(Collection $tasks): Collection
    {
        return $tasks->filter(fn($t) => $t->complete_at === null);
    }

    /**
     * 昵称 collection -> markdown 假 @ 串: **@韦刚** **@朱凌峰**.
     * 空 collection -> ''.
     */
    public static function renderOwnerListMarkdown(Collection $tasks): string
    {
        return $tasks->pluck('nickname')
            ->filter(fn($n) => is_string($n) && trim($n) !== '')
            ->unique()
            ->map(fn($n) => "**@{$n}**")
            ->implode(' ');
    }

    /**
     * 企微推送分发. dryRun=true 仅打 log, 不真发.
     * 返回 bool: 真实发送时 true 表示至少 1 个 URL 成功, dry-run 恒返回 true.
     */
    public static function pushOrDryRun(string $title, string $content, bool $dryRun): bool
    {
        if ($dryRun) {
            Log::info("[BdWechat][DRY-RUN] title={$title}");
            Log::info("[BdWechat][DRY-RUN] content:\n{$content}");
            return true;
        }
        return WechatBusinessNotifier::send($title, $content);
    }

    /**
     * 项目解析: 复用 bd_daily_report.project_id / project_name 配置.
     * 返回 null 表示项目不存在 (caller 应降级处理).
     */
    private static function resolveProject(): ?Project
    {
        $projectId = (int) config('bd_daily_report.project_id', 0);
        $projectName = (string) config('bd_daily_report.project_name');
        if ($projectId > 0) {
            return Project::whereId($projectId)->whereNull('archived_at')->first();
        }
        return Project::where('name', $projectName)->whereNull('archived_at')->first();
    }
}
