<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes task management.
 *
 * 一次性回填历史已完成任务的 complete_userid 字段。
 * 从 project_logs 表反查"标记{任务}{已完成/XXX}"日志条目的 userid 作为完成人。
 * 详见 docs/DESIGN_2026-04-16_TASK_COMPLETE_PERMISSION_AND_OPERATOR.md
 */

namespace App\Console\Commands;

use App\Models\ProjectLog;
use App\Models\ProjectTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillCompleteUserid extends Command
{
    protected $signature = 'task:backfill-complete-userid
        {--dry-run : 仅打印不落库}
        {--limit=0 : 最多处理多少条（0=全部）}';

    protected $description = '为历史已完成任务从 project_logs 回填 complete_userid';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = ProjectTask::query()
            ->whereNotNull('complete_at')
            ->where('complete_userid', 0);

        $total = (clone $query)->count();
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        $this->info(sprintf(
            '[%s] 待回填任务数：%d%s',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $total,
            $limit > 0 ? " (limit={$limit})" : ''
        ));

        if ($total === 0) {
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $filled = 0;
        $missing = 0;

        $query->orderBy('id')
            ->chunkById(500, function ($tasks) use (&$processed, &$filled, &$missing, $bar, $dryRun, $limit) {
                foreach ($tasks as $task) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    $log = ProjectLog::query()
                        ->where('task_id', $task->id)
                        ->where('detail', 'LIKE', '%标记%')
                        ->where('detail', 'NOT LIKE', '%未完成%')
                        ->orderByDesc('id')
                        ->first();

                    if ($log && $log->userid > 0) {
                        if (!$dryRun) {
                            $task->complete_userid = $log->userid;
                            $task->saveQuietly();
                        }
                        $filled++;
                    } else {
                        $missing++;
                    }

                    $processed++;
                    $bar->advance();
                }
                return true;
            });

        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            '处理完成：已处理 %d，成功回填 %d，找不到日志 %d',
            $processed, $filled, $missing
        ));

        if (!$dryRun) {
            Log::info("[BackfillCompleteUserid] processed={$processed} filled={$filled} missing={$missing}");
        }

        return 0;
    }
}
