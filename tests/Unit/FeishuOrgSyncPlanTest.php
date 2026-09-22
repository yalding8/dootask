<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace Tests\Unit;

use App\Exceptions\ApiException;
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

    public function test_rejects_invalid_json_with_a_safe_error(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('组织计划 JSON 无效');
        OrgSyncPlan::fromJson('{', new DateTimeImmutable('2026-09-21T06:05:00Z'));
    }

    public function test_requires_the_source_root_to_reuse_target_department_two(): void
    {
        $plan = $this->validPlan();
        $plan['departments'][0]['action'] = 'create';
        $plan['departments'][0]['createKey'] = 'replacement-root';
        $plan['departments'][0]['before'] = null;
        unset($plan['departments'][0]['targetId']);

        $this->expectExceptionMessage('组织计划根部门无效');
        $this->parse($plan);
    }

    public function test_rejects_duplicate_targets(): void
    {
        $plan = $this->validPlan();
        $plan['departments'][] = [
            'sourceId' => 'od-child', 'parentSourceId' => $plan['sourceRoot'], 'action' => 'update',
            'targetId' => 2, 'name' => '重复部门', 'ownerUserId' => 48,
            'before' => ['parent' => 2, 'name' => '重复部门', 'owner' => 48, 'dialog' => 3],
        ];

        $this->expectExceptionMessage('组织计划目标部门无效');
        $this->parse($plan);
    }

    public function test_rejects_missing_parents_and_cycles(): void
    {
        $plan = $this->validPlan();
        $plan['departments'][] = $this->department('od-child', 'od-missing', 3);
        try {
            $this->parse($plan);
            $this->fail('Expected missing parent rejection.');
        } catch (ApiException $e) {
            $this->assertSame('组织计划部门层级无效', $e->getMessage());
        }

        $plan = $this->validPlan();
        $plan['departments'][] = $this->department('od-a', 'od-b', 3);
        $plan['departments'][] = $this->department('od-b', 'od-a', 4);
        $this->expectExceptionMessage('组织计划部门层级无效');
        $this->parse($plan);
    }

    public function test_rejects_a_source_path_deeper_than_the_absolute_five_level_target_limit(): void
    {
        $plan = $this->validPlan();
        $parent = $plan['sourceRoot'];
        foreach (range(1, 4) as $index) {
            $sourceId = 'od-level-' . $index;
            $plan['departments'][] = $this->department($sourceId, $parent, $index + 2);
            $parent = $sourceId;
        }

        $this->expectExceptionMessage('组织计划部门层级超限');
        $this->parse($plan);
    }

    public function test_rejects_unsafe_names(): void
    {
        $plan = $this->validPlan();
        $plan['departments'][0]['name'] = '危险<部门>';

        $this->expectExceptionMessage('组织计划部门名称无效');
        $this->parse($plan);
    }

    public function test_rejects_more_than_ten_final_departments(): void
    {
        $plan = $this->validPlan();
        $plan['members'][] = [
            'userId' => 48,
            'before' => [1],
            'preserve' => range(10, 19),
            'managed' => [[
                'sourceId' => $plan['sourceRoot'],
                'reason' => 'owner_required',
            ]],
        ];

        $this->expectExceptionMessage('组织计划成员部门超限');
        $this->parse($plan);
    }

    public function test_accepts_ancestor_membership_for_every_real_ancestor_of_a_managed_department(): void
    {
        $plan = $this->validPlan();
        $plan['departments'][] = $this->department('od-child', $plan['sourceRoot'], 3);
        $plan['departments'][] = $this->department('od-leaf', 'od-child', 4);
        $plan['members'][] = ['userId' => 62, 'before' => [2], 'preserve' => [], 'managed' => [
            ['sourceId' => 'od-leaf', 'reason' => 'direct'],
            ['sourceId' => 'od-child', 'reason' => 'ancestor'],
            ['sourceId' => $plan['sourceRoot'], 'reason' => 'ancestor'],
        ]];
        $plan['members'][] = ['userId' => 81, 'before' => [2], 'preserve' => [], 'managed' => [
            ['sourceId' => 'od-child', 'reason' => 'owner_required'],
            ['sourceId' => $plan['sourceRoot'], 'reason' => 'ancestor'],
        ]];

        $this->assertCount(2, $this->parse($plan)->members());
    }

    /**
     * @dataProvider invalidAncestorMemberships
     */
    public function test_rejects_ancestor_membership_that_is_not_a_real_ancestor(array $managed): void
    {
        $plan = $this->validPlan();
        $plan['departments'][] = $this->department('od-child', $plan['sourceRoot'], 3);
        $plan['departments'][] = $this->department('od-sibling', $plan['sourceRoot'], 4);
        $plan['members'][] = ['userId' => 62, 'before' => [2], 'preserve' => [], 'managed' => $managed];

        $this->expectExceptionMessage('组织计划成员归属无效');
        $this->parse($plan);
    }

    public function invalidAncestorMemberships(): array
    {
        $root = 'od-046de9ebfea10edd226515e26afa12e0';
        return [
            'descendant tagged as ancestor' => [[
                ['sourceId' => $root, 'reason' => 'direct'],
                ['sourceId' => 'od-child', 'reason' => 'ancestor'],
            ]],
            'sibling tagged as ancestor' => [[
                ['sourceId' => 'od-child', 'reason' => 'direct'],
                ['sourceId' => 'od-sibling', 'reason' => 'ancestor'],
            ]],
            'ancestor without any direct or owner department' => [[
                ['sourceId' => $root, 'reason' => 'ancestor'],
            ]],
            'the same department listed twice' => [[
                ['sourceId' => 'od-child', 'reason' => 'direct'],
                ['sourceId' => 'od-child', 'reason' => 'ancestor'],
            ]],
            'unknown reason' => [[
                ['sourceId' => 'od-child', 'reason' => 'parent'],
            ]],
        ];
    }

    private function parse(array $plan): OrgSyncPlan
    {
        unset($plan['digest']);
        $plan['digest'] = hash('sha256', $this->canonicalJson($plan));
        return OrgSyncPlan::fromJson(
            json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            new DateTimeImmutable('2026-09-21T06:05:00Z')
        );
    }

    private function department(string $sourceId, string $parentSourceId, int $targetId): array
    {
        return [
            'sourceId' => $sourceId,
            'parentSourceId' => $parentSourceId,
            'action' => 'update',
            'targetId' => $targetId,
            'name' => '测试部门' . $targetId,
            'ownerUserId' => 48,
            'before' => ['parent' => 2, 'name' => '测试部门' . $targetId, 'owner' => 48, 'dialog' => $targetId],
        ];
    }

    private function canonicalJson($value): string
    {
        if (is_array($value)) {
            $isList = !$value || array_keys($value) === range(0, count($value) - 1);
            if (!$isList) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = json_decode($this->canonicalJson($item), true);
            }
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
