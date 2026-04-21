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
 * 归档 N 天前已完成的 BD 日报主任务（子任务随主任务级联归档）。
 * 每周日 23:00 自动执行，也可手动触发：
 *   php artisan bd-daily-report:cleanup --days=7
 *   php artisan bd-daily-report:cleanup --days=7 --dry-run
 */
class BdDailyReportCleanup extends Command
{
    protected $signature = 'bd-daily-report:cleanup
        {--days=7 : 归档 N 天前已完成的任务}
        {--dry-run : 仅打印待归档任务，不落库}';

    protected $description = '[BD 日报] 归档 N 天前已完成的日报任务，防止无限积累';

    public function handle(): int
    {
        $days     = (int) $this->option('days');
        $dryRun   = (bool) $this->option('dry-run');
        $tz       = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $cutoff   = Carbon::now($tz)->subDays($days)->endOfDay();

        // 查目标项目
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

        // 查待归档主任务：已完成 + complete_at 超过 cutoff + 未归档
        $tasks = ProjectTask::where('project_id', $project->id)
            ->where('parent_id', 0)
            ->whereNotNull('complete_at')
            ->where('complete_at', '<=', $cutoff)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info("[BD cleanup] 无待归档任务（cutoff={$cutoff}）");
            return 0;
        }

        $this->info("[BD cleanup] 待归档 {$tasks->count()} 条主任务（cutoff={$cutoff}）" . ($dryRun ? ' [dry-run]' : ''));

        $archived = 0;
        $now      = Carbon::now();

        foreach ($tasks as $task) {
            if ($dryRun) {
                $this->line("  [dry-run] task#{$task->id} {$task->name}");
                continue;
            }
            // 直接写 DB，绕过 archivedTask() 里的 WebSocket push（CLI 无 Swoole 上下文）
            $task->archived_at     = $now;
            $task->archived_userid = 0;   // 0 表示系统自动
            $task->archived_follow = 0;
            $task->save();
            // 级联归档子任务
            ProjectTask::whereParentId($task->id)->update([
                'archived_at'     => $now,
                'archived_userid' => 0,
                'archived_follow' => 0,
            ]);
            $archived++;
        }

        if (!$dryRun) {
            $this->info("[BD cleanup] 完成：归档 {$archived} 条主任务（含子任务）");
        }

        return $failed > 0 ? 1 : 0;
    }
}
