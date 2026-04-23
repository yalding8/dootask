<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * 工作日 09:00 晨推: 今日 BD 日报任务已生成, 文字 @ 所有有任务的 BD 督促登录.
 * 设计: docs/DESIGN_2026-04-23_BD_WECOM_CHECKIN_NOTIFY.md
 */

namespace App\Console\Commands;

use App\Module\BdDailyReportWechatHelper;
use App\Module\HolidayClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BdDailyReportWechatMorning extends Command
{
    protected $signature = 'bd-daily-report:wechat-morning
        {--dry-run : 仅打 log, 不真推企微}
        {--date= : 覆盖日期 (YYYY-MM-DD), 默认为今天}';

    protected $description = '[BD 日报] 工作日 09:00 企微晨推 (今日任务已生成 + @ BD 登录)';

    public function handle(): int
    {
        if (!config('bd_daily_report.wechat_morning_enabled', true)) {
            $this->info('BD_WECHAT_MORNING_ENABLED=false, 跳过');
            return 0;
        }

        $tz = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $date = $date->startOfDay();
        $dateStr = $date->format('Y-m-d');

        if (HolidayClient::isOffDay($date)['is_off']) {
            $this->info("[{$dateStr}] 非工作日, 跳过晨推");
            return 0;
        }

        $tasks = BdDailyReportWechatHelper::fetchTodayTasks($date);
        if ($tasks->isEmpty()) {
            $this->info("[{$dateStr}] 今日无 BD 日报任务, 跳过晨推");
            Log::info("[BdWechat][morning] {$dateStr} no tasks, skipped");
            return 0;
        }

        $n = $tasks->count();
        $ownerMd = BdDailyReportWechatHelper::renderOwnerListMarkdown($tasks);

        $title = '📋 今日 BD 日报任务已生成';
        $content = <<<MD
共 {$n} 位同事今日日报已创建, 请登录 task 系统开启处理:
{$ownerMd}

提示: 打开任务后将状态从"待处理"拖到"进行中"即算打卡.
MD;

        $dryRun = (bool) $this->option('dry-run');
        $ok = BdDailyReportWechatHelper::pushOrDryRun($title, $content, $dryRun);

        $tag = $dryRun ? '[DRY-RUN]' : ($ok ? '[sent]' : '[failed]');
        $this->info("[{$dateStr}] {$tag} morning push, N={$n}");
        Log::info("[BdWechat][morning] {$dateStr} {$tag} N={$n}");

        return 0;
    }
}
