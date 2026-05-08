<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * Skeleton: body 待 dry-run 阶段完整化.
 * 对应命令: bd-daily-report:cleanup --include-incomplete
 * 设计: docs/DESIGN_2026-05-08_BD_RITUAL_REVAMP.md
 */

namespace Tests\Feature;

use Tests\TestCase;

class BdDailyReportCleanupIncompleteTest extends TestCase
{
    /** @test */
    public function 默认模式不归档未完成()
    {
        $this->markTestIncomplete('skeleton: seed 1 已完成超 7 天 + 1 未完成超 1 天, artisan call --days=7 (无 --include-incomplete), assert 仅已完成那条 archived_at NOT NULL, 未完成保持 archived_at NULL');
    }

    /** @test */
    public function include_incomplete模式归档前一日未完成()
    {
        $this->markTestIncomplete('skeleton: seed 1 昨日 complete_at NULL 任务, artisan call --include-incomplete, assert archived_at 已设, complete_at 仍为 NULL');
    }

    /** @test */
    public function include_incomplete不归档今日未完成()
    {
        $this->markTestIncomplete('skeleton: seed 1 今日 created_at 09:00 + complete_at NULL 任务, artisan call --include-incomplete (08:55), assert archived_at 仍 NULL（today 0 点之前的过滤生效）');
    }

    /** @test */
    public function 不覆盖complete_at字段()
    {
        $this->markTestIncomplete('skeleton: seed 已完成超 7 天任务, artisan call --days=7, assert complete_at 保留原值不被改成 NULL');
    }

    /** @test */
    public function 节假日跳过incomplete归档()
    {
        $this->markTestIncomplete('skeleton: mock HolidayClient::isOffDay 返回 is_off=true, seed 昨日未完成, artisan call --include-incomplete, assert archived_at 仍 NULL, stdout 含 "非节假日，跳过 incomplete 归档"（路径 1 已完成归档不受节假日影响）');
    }

    /** @test */
    public function dry_run模式不落库()
    {
        $this->markTestIncomplete('skeleton: seed 昨日未完成, artisan call --include-incomplete --dry-run, assert archived_at 仍 NULL, stdout 含 [dry-run/漏卡未完成] 行');
    }

    /** @test */
    public function 级联归档子任务但不影响已归档子任务()
    {
        $this->markTestIncomplete('skeleton: seed 主任务 + 1 已归档子任务 + 1 未归档子任务, artisan call, assert 主任务 archived_at 已设, 已归档子任务 archived_at 不被覆盖（保留原归档时间）, 未归档子任务 archived_at 已设');
    }

    /** @test */
    public function 两路径合并执行无重复归档()
    {
        $this->markTestIncomplete('skeleton: seed 1 已完成超期 + 1 昨日未完成, artisan call --days=7 --include-incomplete, assert 共归档 2 条, 各只归档一次（archived_at 唯一）');
    }
}
