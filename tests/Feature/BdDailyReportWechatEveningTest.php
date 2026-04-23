<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * Skeleton: body 待 dry-run 阶段完整化.
 * 对应命令: bd-daily-report:wechat-evening
 * 设计: docs/DESIGN_2026-04-23_BD_WECOM_CHECKIN_NOTIFY.md
 */

namespace Tests\Feature;

use Tests\TestCase;

class BdDailyReportWechatEveningTest extends TestCase
{
    /** @test */
    public function complete_at_IS_NULL算未完成()
    {
        $this->markTestIncomplete('skeleton: seed 2 tasks (complete_at=null), artisan call, assert content 含 "未完成 2" 和 2 个昵称');
    }

    /** @test */
    public function complete_at_NOT_NULL算已完成()
    {
        $this->markTestIncomplete('skeleton: seed 3 tasks (1 complete_at=now, 2 null), artisan call, assert "已完成 1, 未完成 2"');
    }

    /** @test */
    public function 全员已完成推庆祝零人版()
    {
        $this->markTestIncomplete('skeleton: seed 3 tasks 全部 complete_at=now, artisan call, assert content 含 🎉 "3/3 日报全员完成"');
    }
}
