<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * 工作日 09:30 汇总: 未打卡 (flow_status='start' 或 null) 人员清单, 公开点名.
 * 设计: docs/DESIGN_2026-04-23_BD_WECOM_CHECKIN_NOTIFY.md
 */

namespace App\Console\Commands;

use App\Module\BdDailyReportWechatHelper;
use App\Module\BridgeClient;
use App\Module\HolidayClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BdDailyReportWechatCheckin extends Command
{
    protected $signature = 'bd-daily-report:wechat-checkin
        {--dry-run : 仅打 log, 不真推企微}
        {--date= : 覆盖日期 (YYYY-MM-DD), 默认为今天}';

    protected $description = '[BD 日报] 工作日 09:30 企微汇总 (未打卡人员清单)';

    public function handle(): int
    {
        if (!config('bd_daily_report.wechat_checkin_enabled', true)) {
            $this->info('BD_WECHAT_CHECKIN_ENABLED=false, 跳过');
            return 0;
        }

        $tz = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $date = $date->startOfDay();
        $dateStr = $date->format('Y-m-d');

        if (HolidayClient::isOffDay($date)['is_off']) {
            $this->info("[{$dateStr}] 非工作日, 跳过 9:30 汇总");
            return 0;
        }

        $tasks = BdDailyReportWechatHelper::fetchTodayTasks($date);
        if ($tasks->isEmpty()) {
            $this->info("[{$dateStr}] 今日无 BD 日报任务, 跳过 9:30 汇总");
            Log::info("[BdWechat][checkin] {$dateStr} no tasks, skipped");
            return 0;
        }

        $n = $tasks->count();
        $unchecked = BdDailyReportWechatHelper::filterUncheckedIn($tasks);
        $m = $unchecked->count();

        if ($m === 0) {
            $title = '🎉 9:30 打卡完成';
            $content = "今日 {$n}/{$n} 全员已打卡, 辛苦!";
        } else {
            $title = '⏰ 9:30 打卡情况';
            $done = $n - $m;
            $ownerMd = BdDailyReportWechatHelper::renderOwnerListMarkdown($unchecked);
            $content = <<<MD
今日 {$n} 位有任务, 已打卡 {$done}, 未打卡 {$m}:
{$ownerMd}

请立即打开任务, 点击"进行中"完成打卡.
MD;
        }

        $dryRun = (bool) $this->option('dry-run');
        $ok = BdDailyReportWechatHelper::pushOrDryRun($title, $content, $dryRun);

        $tag = $dryRun ? '[DRY-RUN]' : ($ok ? '[sent]' : '[failed]');
        $this->info("[{$dateStr}] {$tag} checkin push, N={$n} unchecked={$m}");
        Log::info("[BdWechat][checkin] {$dateStr} {$tag} N={$n} unchecked={$m}");

        if (!$dryRun) {
            BridgeClient::notifyCheckin();
        }

        return 0;
    }
}
