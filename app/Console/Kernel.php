<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();

        // BD 日报自动化（基于 DooTask AGPL-3.0 的扩展，见 docs/DESIGN_2026-04-14_BD_DAILY_REPORT.md）
        $tz = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $schedule->command('bd-daily-report:create')
            ->weekdays()
            ->dailyAt('09:00')
            ->timezone($tz)
            ->withoutOverlapping(30)
            ->onOneServer();
        // 18:00 站内 IM + 邮件文本催交于 2026-05-08 退役（DESIGN_2026-05-08_BD_RITUAL_REVAMP.md）
        // 由 feishu-bridge 18:00 互动晚卡（review card）接管，下面 feishu-evening-review 触发。
        // 历史调度: $schedule->command('bd-daily-report:remind')->weekdays()->dailyAt('18:00')->...
        $schedule->command('bd-daily-report:feishu-evening-review')
            ->weekdays()
            ->dailyAt('18:00')
            ->timezone($tz)
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('bd-daily-report:cleanup --days=7')
            ->weeklyOn(0, '23:00')
            ->timezone($tz)
            ->withoutOverlapping(30)
            ->onOneServer();
        // 工作日 08:55 silent archive 前一日漏卡任务（next-morning archive，DESIGN_2026-05-08）
        // 不发任何最后催交通知；归档仅设 archived_at，不改 complete_at（保留漏卡事实供 audit）
        // 节假日（含调休）由命令内部 HolidayClient guard 跳过
        $schedule->command('bd-daily-report:cleanup --include-incomplete')
            ->weekdays()
            ->dailyAt('08:55')
            ->timezone($tz)
            ->withoutOverlapping(10)
            ->onOneServer();

        // BD 日报企微群三段推送 (设计: docs/DESIGN_2026-04-23_BD_WECOM_CHECKIN_NOTIFY.md)
        // 每个命令内部有 feature flag + isWorkday + 0 任务 guard; 拆 3 个 flag 便于 9:30 单独关闭
        // 注: morning 延后到 09:05, 给 create (09:00) 5 分钟建任务 buffer, 避免 race condition
        $schedule->command('bd-daily-report:wechat-morning')
            ->weekdays()
            ->dailyAt('09:05')
            ->timezone($tz)
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('bd-daily-report:wechat-checkin')
            ->weekdays()
            ->dailyAt('09:30')
            ->timezone($tz)
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('bd-daily-report:wechat-evening')
            ->weekdays()
            ->dailyAt('19:00')
            ->timezone($tz)
            ->withoutOverlapping(10)
            ->onOneServer();

        // 飞书通知: 新分配任务（每 10 分钟）+ 即将逾期（每 30 分钟）
        $schedule->command('bridge:notify-assigned')
            ->everyTenMinutes()
            ->withoutOverlapping(5);
        $schedule->command('bridge:notify-overdue')
            ->everyThirtyMinutes()
            ->withoutOverlapping(10);

        // 飞书通知: 项目每日任务播报（次日 09:00 CST）
        // 显式 Asia/Shanghai，避免容器 UTC 时区导致播报时刻/"昨日"窗口错位
        $schedule->command('bridge:notify-project-digest')
            ->dailyAt('09:00')
            ->timezone('Asia/Shanghai')
            ->withoutOverlapping(10);

        // BD 日报每日快照 (19:30, 给 evening push 后的"补完成" 留 30 分钟 buffer)
        // 用途: P0b 修复效果验证 + P0a 团队看板历史数据基础
        // 数据: storage/app/metrics/bd-daily-snapshots.jsonl (jsonl 追加)
        $schedule->command('bd-daily-report:snapshot')
            ->weekdays()
            ->dailyAt('19:30')
            ->timezone($tz)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/bd-daily-snapshot.log'));

        // M2 KR1 测量: 每周一 00:01 快照 WAU, 自动判定 4 周连续达标
        // 数据源: pre_users.line_at (最后在线时间, 30s 接口刷新)
        // 不需要 middleware + 新表 (PRD §M2 简化方案)
        $schedule->command('metrics:dau-report --snapshot')
            ->weeklyOn(1, '00:01')
            ->timezone($tz)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/metrics-dau.log'));
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
