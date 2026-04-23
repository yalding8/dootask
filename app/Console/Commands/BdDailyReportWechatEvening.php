<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * 工作日 19:00 汇总: 未完成主任务 (complete_at IS NULL) 人员清单, 公开点名.
 * 设计: docs/DESIGN_2026-04-23_BD_WECOM_CHECKIN_NOTIFY.md
 */

namespace App\Console\Commands;

use App\Module\BdDailyReportWechatHelper;
use App\Module\HolidayClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BdDailyReportWechatEvening extends Command
{
    protected $signature = 'bd-daily-report:wechat-evening
        {--dry-run : 仅打 log, 不真推企微}
        {--date= : 覆盖日期 (YYYY-MM-DD), 默认为今天}';

    protected $description = '[BD 日报] 工作日 19:00 企微汇总 (未完成主任务人员清单)';

    public function handle(): int
    {
        if (!config('bd_daily_report.wechat_evening_enabled', true)) {
            $this->info('BD_WECHAT_EVENING_ENABLED=false, 跳过');
            return 0;
        }

        $tz = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $date = $date->startOfDay();
        $dateStr = $date->format('Y-m-d');

        if (HolidayClient::isOffDay($date)['is_off']) {
            $this->info("[{$dateStr}] 非工作日, 跳过 19:00 汇总");
            return 0;
        }

        $tasks = BdDailyReportWechatHelper::fetchTodayTasks($date);
        if ($tasks->isEmpty()) {
            $this->info("[{$dateStr}] 今日无 BD 日报任务, 跳过 19:00 汇总");
            Log::info("[BdWechat][evening] {$dateStr} no tasks, skipped");
            return 0;
        }

        $n = $tasks->count();
        $unfinished = BdDailyReportWechatHelper::filterUnfinished($tasks);
        $k = $unfinished->count();

        if ($k === 0) {
            $title = '🎉 今日日报全员完成';
            $content = "今日 {$n}/{$n} 日报全员完成, 辛苦各位!";
        } else {
            $title = '🌙 今日日报完成情况';
            $done = $n - $k;
            $ownerMd = BdDailyReportWechatHelper::renderOwnerListMarkdown($unfinished);
            $content = <<<MD
今日 {$n} 位任务: 已完成 {$done}, 未完成 {$k}:
{$ownerMd}

请尽快完成日报, 避免被记录为未完成.
MD;
        }

        $dryRun = (bool) $this->option('dry-run');
        $ok = BdDailyReportWechatHelper::pushOrDryRun($title, $content, $dryRun);

        $tag = $dryRun ? '[DRY-RUN]' : ($ok ? '[sent]' : '[failed]');
        $this->info("[{$dateStr}] {$tag} evening push, N={$n} unfinished={$k}");
        Log::info("[BdWechat][evening] {$dateStr} {$tag} N={$n} unfinished={$k}");

        return 0;
    }
}
