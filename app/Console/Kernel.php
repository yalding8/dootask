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
        $schedule->command('bd-daily-report:remind')
            ->weekdays()
            ->dailyAt('18:00')
            ->timezone($tz)
            ->withoutOverlapping(30)
            ->onOneServer();
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
