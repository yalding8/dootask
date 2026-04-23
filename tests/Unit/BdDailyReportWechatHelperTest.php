<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * Skeleton: body 待 dry-run 阶段完整化.
 * 对应模块: App\Module\BdDailyReportWechatHelper
 * 设计: docs/DESIGN_2026-04-23_BD_WECOM_CHECKIN_NOTIFY.md
 */

namespace Tests\Unit;

use Tests\TestCase;

class BdDailyReportWechatHelperTest extends TestCase
{
    /** @test */
    public function renderOwnerListMarkdown_昵称加粗空格分隔()
    {
        $this->markTestIncomplete('skeleton: collect of objects with nickname 韦刚/朱凌峰, assert result = "**@韦刚** **@朱凌峰**"');
    }

    /** @test */
    public function renderOwnerListMarkdown_空collection返回空串()
    {
        $this->markTestIncomplete('skeleton: empty collect, assert result = ""');
    }

    /** @test */
    public function renderOwnerListMarkdown_去重_dedupe()
    {
        $this->markTestIncomplete('skeleton: 2 objects same nickname 韦刚, assert result 只含一次');
    }

    /** @test */
    public function filterUncheckedIn_正确识别start和null()
    {
        $this->markTestIncomplete('skeleton: 4 tasks (start, progress, end, null), assert filter 返回 start + null 两个');
    }

    /** @test */
    public function filterUnfinished_只看complete_at()
    {
        $this->markTestIncomplete('skeleton: 3 tasks (complete_at=null x2, complete_at=now x1), filter 返回 2 null 的');
    }
}
