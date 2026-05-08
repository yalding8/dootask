<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 */

namespace App\Console\Commands;

use App\Module\BridgeClient;
use App\Module\HolidayClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * 工作日 18:00 触发 feishu-bridge 给当日 BD 推下班复盘卡片（review card）。
 *
 * 设计：DESIGN_2026-05-08_BD_RITUAL_REVAMP.md
 * 替代了 BdDailyReportRemind（DooTask IM + 邮件文本催交，已退役）。
 */
class BdDailyReportFeishuEveningReview extends Command
{
    protected $signature = 'bd-daily-report:feishu-evening-review';

    protected $description = '[BD 日报] 工作日 18:00 触发飞书晚卡（review）推送';

    public function handle(): int
    {
        $tz = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $now = Carbon::now($tz);

        // 节假日跳过
        $offInfo = HolidayClient::isOffDay($now);
        if ($offInfo['is_off']) {
            $this->info("[{$now->format('Y-m-d')}] 非工作日（{$offInfo['reason']}），跳过晚卡推送");
            return 0;
        }

        $ok = BridgeClient::notifyEveningReview();
        if ($ok) {
            $this->info("[{$now->format('Y-m-d')}] 晚卡推送已触发");
            return 0;
        }
        $this->error("[{$now->format('Y-m-d')}] 晚卡推送触发失败（详见 laravel.log）");
        return 1;
    }
}
