<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * Skeleton: body 待 dry-run 阶段完整化.
 * 对应命令: bd-daily-report:wechat-checkin
 * 设计: docs/DESIGN_2026-04-23_BD_WECOM_CHECKIN_NOTIFY.md
 */

namespace Tests\Feature;

use Tests\TestCase;

class BdDailyReportWechatCheckinTest extends TestCase
{
    /** @test */
    public function 全员flow_status_start_推N人未打卡()
    {
        $this->markTestIncomplete('skeleton: seed 3 tasks 全部 flow_item.status=start, artisan call, assert content 含 "未打卡 3" 和 3 个昵称');
    }

    /** @test */
    public function 部分flow_status_progress_只推start那批()
    {
        $this->markTestIncomplete('skeleton: seed 3 tasks (2 progress + 1 start), artisan call, assert content 含 "已打卡 2, 未打卡 1" 且只有 start 的那位昵称');
    }

    /** @test */
    public function 全员非start推庆祝零人版()
    {
        $this->markTestIncomplete('skeleton: seed 3 tasks 全部 progress, artisan call, assert content 含 🎉 "9:30 打卡完成" "3/3 全员已打卡"');
    }

    /** @test */
    public function flow_item_id_0孤儿任务归入未打卡_Red_Team场景1()
    {
        $this->markTestIncomplete('skeleton: seed task flow_item_id=0 (无 flow), LEFT JOIN 返回 flow_status=NULL, assert 归入 "未打卡" 清单');
    }
}
