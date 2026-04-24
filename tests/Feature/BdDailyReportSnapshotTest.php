<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * Skeleton: body 待 dry-run 阶段完整化.
 * 对应命令: bd-daily-report:snapshot
 */

namespace Tests\Feature;

use Tests\TestCase;

class BdDailyReportSnapshotTest extends TestCase
{
    /** @test */
    public function dry_run模式只打印不追加jsonl()
    {
        $this->markTestIncomplete('skeleton: seed 3 tasks (2 progress + 1 start 无 complete_at), artisan call --dry-run, assert jsonl 文件不变, stdout 含 total=3 checked_in=2 completed=0');
    }

    /** @test */
    public function 正常追加模式写入jsonl一行()
    {
        $this->markTestIncomplete('skeleton: seed tasks, artisan call, assert jsonl 文件追加一行, json_decode 后 date/total/checked_in/completed/check_in_rate/completion_rate/snapshot_at 字段齐全');
    }

    /** @test */
    public function 非工作日跳过不写入()
    {
        $this->markTestIncomplete('skeleton: mock HolidayClient::isOffDay 返回 true, artisan call, assert jsonl 不变, stdout 含 "非工作日, 跳过"');
    }

    /** @test */
    public function 今日无日报任务跳过不写入()
    {
        $this->markTestIncomplete('skeleton: 不 seed 任何任务, artisan call, assert jsonl 不变, stdout 含 "今日无 BD 日报任务, 跳过"');
    }

    /** @test */
    public function date选项支持补跑历史日期()
    {
        $this->markTestIncomplete('skeleton: seed tasks name 含 "2026-04-20 日报", artisan call --date=2026-04-20, assert 聚合命中这批, jsonl 追加一行 date=2026-04-20');
    }
}
