<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace Tests\Unit;

use App\Module\FeishuOrgSync\OrgSyncPlan;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class FeishuOrgSyncPlanTest extends TestCase
{
    private function validPlan(): array
    {
        return [
            'schemaVersion' => 1,
            'generatedAt' => '2026-09-21T06:00:00.000Z',
            'expiresAt' => '2026-09-21T06:15:00.000Z',
            'sourceRoot' => 'od-046de9ebfea10edd226515e26afa12e0',
            'targetRoot' => 2,
            'departments' => [[
                'sourceId' => 'od-046de9ebfea10edd226515e26afa12e0',
                'parentSourceId' => null,
                'action' => 'update',
                'targetId' => 2,
                'name' => '金融推广部',
                'ownerUserId' => 48,
                'before' => ['parent' => 1, 'name' => '金融推广部', 'owner' => 48, 'dialog' => 2],
            ]],
            'members' => [],
            'findings' => [],
            'digest' => 'e9b66cf7c6be8ec65f0deecb09e8f51831f029a2133c524cb00f6ead8e4a088e',
        ];
    }

    public function test_accepts_node_generated_digest_and_exposes_normalized_plan(): void
    {
        $plan = OrgSyncPlan::fromJson(
            json_encode($this->validPlan(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            new DateTimeImmutable('2026-09-21T06:05:00Z')
        );
        $this->assertSame(2, $plan->targetRoot());
        $this->assertSame('e9b66cf7c6be8ec65f0deecb09e8f51831f029a2133c524cb00f6ead8e4a088e', $plan->digest());
        $this->assertCount(1, $plan->departments());
    }

    public function test_rejects_tampered_or_expired_plan(): void
    {
        $tampered = $this->validPlan();
        $tampered['departments'][0]['ownerUserId'] = 69;
        $this->expectExceptionMessage('组织计划摘要不匹配');
        OrgSyncPlan::fromJson(json_encode($tampered, JSON_UNESCAPED_UNICODE), new DateTimeImmutable('2026-09-21T06:05:00Z'));
    }

    public function test_rejects_expired_plan(): void
    {
        $this->expectExceptionMessage('组织计划已过期');
        OrgSyncPlan::fromJson(json_encode($this->validPlan(), JSON_UNESCAPED_UNICODE), new DateTimeImmutable('2026-09-21T06:16:00Z'));
    }

    public function test_rejects_unknown_fields_and_wrong_roots(): void
    {
        $plan = $this->validPlan();
        $plan['unexpected'] = true;
        $this->expectExceptionMessage('组织计划字段不受支持');
        OrgSyncPlan::fromJson(json_encode($plan, JSON_UNESCAPED_UNICODE), new DateTimeImmutable('2026-09-21T06:05:00Z'));
    }
}
