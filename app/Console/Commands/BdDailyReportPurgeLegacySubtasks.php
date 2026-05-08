<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 */

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectTask;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * 一次性清理 BD 日报项目下的存量 checklist 子任务（parent_id != 0）。
 *
 * 背景：2026-05-08 BD 日报改造为飞书 plan-review 双段 ritual 后，DooTask 子任务
 * 不再使用（一句话产出/复盘改在飞书卡片输入并写入主任务 description）。
 * 改 BD_REPORT_SUBTASK_TITLES="" 只影响新建任务，存量子任务需本命令清除。
 *
 * 软删策略：设 deleted_at = now()，不物理删除——保留可回滚能力。
 *
 * 调用：
 *   php artisan bd-daily-report:purge-legacy-subtasks --dry-run
 *   php artisan bd-daily-report:purge-legacy-subtasks
 */
class BdDailyReportPurgeLegacySubtasks extends Command
{
    protected $signature = 'bd-daily-report:purge-legacy-subtasks
        {--dry-run : 仅打印待清理子任务，不落库}';

    protected $description = '[BD 日报] 一次性软删 BD 日报项目存量 checklist 子任务（plan-review 模型不再使用）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // 解析目标项目
        $projectId   = (int) config('bd_daily_report.project_id', 0);
        $projectName = (string) config('bd_daily_report.project_name');
        if ($projectId > 0) {
            $project = Project::whereId($projectId)->whereNull('archived_at')->first();
        } else {
            $project = Project::where('name', $projectName)->whereNull('archived_at')->first();
        }

        if (!$project) {
            $this->error('[BD purge] 项目未找到，检查 BD_REPORT_PROJECT_ID / BD_REPORT_PROJECT 配置');
            return 1;
        }

        // 拉所有"非主任务 + 未删除"的子任务
        $subtasks = ProjectTask::where('project_id', $project->id)
            ->where('parent_id', '!=', 0)
            ->whereNull('deleted_at')
            ->get();

        if ($subtasks->isEmpty()) {
            $this->info('[BD purge] 无待清理子任务（项目内已无 parent_id != 0 的活跃子任务）');
            return 0;
        }

        $this->info("[BD purge] 待清理子任务 {$subtasks->count()} 条" . ($dryRun ? ' [dry-run]' : ''));

        if ($dryRun) {
            // 抽样打印，避免日志爆炸
            $sample = $subtasks->take(10);
            foreach ($sample as $t) {
                $this->line("  [dry-run] subtask#{$t->id} parent#{$t->parent_id} {$t->name}");
            }
            if ($subtasks->count() > 10) {
                $this->line("  ... 及其余 " . ($subtasks->count() - 10) . " 条");
            }
            return 0;
        }

        // 软删：直接 update DB（绕过 Eloquent delete event 的 WebSocket push）
        $now = Carbon::now();
        $ids = $subtasks->pluck('id')->all();
        ProjectTask::whereIn('id', $ids)->update([
            'deleted_at' => $now,
        ]);

        $this->info("[BD purge] 完成：软删 {$subtasks->count()} 条子任务");
        return 0;
    }
}
