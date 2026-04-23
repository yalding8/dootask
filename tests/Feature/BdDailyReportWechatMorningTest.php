<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * Skeleton: body 待 dry-run 阶段完整化.
 * 对应命令: bd-daily-report:wechat-morning
 * 设计: docs/DESIGN_2026-04-23_BD_WECOM_CHECKIN_NOTIFY.md
 */

namespace Tests\Feature;

use Tests\TestCase;

class BdDailyReportWechatMorningTest extends TestCase
{
    /** @test */
    public function 不在工作日不推送()
    {
        $this->markTestIncomplete('skeleton: mock HolidayClient::isOffDay=true, artisan call, assert WechatBusinessNotifier::send 未被调用');
    }

    /** @test */
    public function 当日任务_count_0_不推送()
    {
        $this->markTestIncomplete('skeleton: seed 空项目, artisan call, assert no push');
    }

    /** @test */
    public function env_flag_false_不推送()
    {
        $this->markTestIncomplete('skeleton: config set bd_daily_report.wechat_morning_enabled=false, artisan call, assert no push');
    }

    /** @test */
    public function dry_run_只打log不调send()
    {
        $this->markTestIncomplete('skeleton: seed 3 tasks, artisan call --dry-run, assert Log::info 调用且 WechatBusinessNotifier::send 未被调用');
    }

    /** @test */
    public function 文案包含所有有任务的BD昵称加粗()
    {
        $this->markTestIncomplete('skeleton: seed 3 tasks (owner 韦刚/朱凌峰/王五), mock send, assert captured content 含 **@韦刚** **@朱凌峰** **@王五**');
    }
}
