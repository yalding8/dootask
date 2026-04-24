<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * 每日 19:30 快照当日 BD 日报完成情况到 jsonl, 用于历史趋势分析.
 * 用途:
 *   1. 验证 P0b 豁免约束修复后打卡/完成率的真实变化
 *   2. P0a 团队进度看板的历史数据基础 (今日 vs 本周 vs 本月)
 *   3. M2 KR 判定的辅助数据 (补充 DAU 的行为外 outcome 指标)
 *
 * 设计取舍 (复用 MetricsDauReport 的简约模式):
 *   - 不新建表, 只追加 storage/app/metrics/bd-daily-snapshots.jsonl
 *   - 不需要 middleware, 直接复用 BdDailyReportWechatHelper 的聚合逻辑
 *   - 19:30 时点是 19:00 evening push 之后, 给 BD "看到催促后补完成" 留 30 分钟 buffer
 *   - 非工作日自动跳过 (与三段推送一致, HolidayClient)
 */

namespace App\Console\Commands;

use App\Module\BdDailyReportWechatHelper;
use App\Module\HolidayClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BdDailyReportSnapshot extends Command
{
    protected $signature = 'bd-daily-report:snapshot
        {--date= : 覆盖日期 (YYYY-MM-DD), 默认为今天; 支持补跑历史日期}
        {--dry-run : 仅打印快照, 不追加到 jsonl}';

    protected $description = '[BD 日报] 每日快照打卡/完成率到 jsonl, 供历史趋势分析';

    public function handle(): int
    {
        $tz = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $date = $date->startOfDay();
        $dateStr = $date->format('Y-m-d');

        if (HolidayClient::isOffDay($date)['is_off']) {
            $this->info("[{$dateStr}] 非工作日, 跳过快照");
            return 0;
        }

        $tasks = BdDailyReportWechatHelper::fetchTodayTasks($date);
        $total = $tasks->count();

        if ($total === 0) {
            $this->info("[{$dateStr}] 今日无 BD 日报任务, 跳过快照");
            Log::info("[BdDailySnapshot] {$dateStr} no tasks, skipped");
            return 0;
        }

        $uncheckedIn = BdDailyReportWechatHelper::filterUncheckedIn($tasks)->count();
        $unfinished = BdDailyReportWechatHelper::filterUnfinished($tasks)->count();
        $checkedIn = $total - $uncheckedIn;
        $completed = $total - $unfinished;

        $snapshot = [
            'date' => $dateStr,
            'total' => $total,
            'checked_in' => $checkedIn,
            'completed' => $completed,
            'check_in_rate' => round($checkedIn / $total, 4),
            'completion_rate' => round($completed / $total, 4),
            'snapshot_at' => Carbon::now($tz)->toIso8601String(),
        ];

        $line = json_encode($snapshot, JSON_UNESCAPED_UNICODE) . "\n";

        if ($this->option('dry-run')) {
            $this->info("[DRY-RUN] " . trim($line));
            return 0;
        }

        $path = storage_path('app/metrics/bd-daily-snapshots.jsonl');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        $this->info("[{$dateStr}] snapshot appended: total={$total} checked_in={$checkedIn} completed={$completed} (check_in_rate=" . $snapshot['check_in_rate'] . " completion_rate=" . $snapshot['completion_rate'] . ")");
        Log::info("[BdDailySnapshot] {$dateStr} total={$total} checked_in={$checkedIn} completed={$completed}");

        return 0;
    }
}
