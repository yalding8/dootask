<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\FeishuOrgSyncBatch;
use App\Module\FeishuOrgSync\OrgSyncPlan;
use App\Module\FeishuOrgSync\OrgSyncService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FeishuOrgSyncServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_schema_persists_unique_department_mappings_and_restorable_batches(): void
    {
        $this->assertTrue(Schema::hasColumns('feishu_org_department_mappings', [
            'source_root', 'source_department_id', 'dootask_department_id',
        ]));
        $this->assertTrue(Schema::hasColumns('feishu_org_sync_batches', [
            'plan_digest', 'status', 'pre_snapshot', 'post_snapshot', 'counters', 'error_code', 'operator_userid',
        ]));
    }

    public function test_apply_updates_the_tree_members_and_groups_without_messages(): void
    {
        $this->seedOrganization();
        Cache::forever('UserDepartment::rand', 'before-sync');
        // Relative count: the sync must add no message anywhere, on an empty CI database or a populated dev one.
        $messagesBefore = DB::table('web_socket_dialog_msgs')->count();

        $batch = (new OrgSyncService())->apply($this->plan(), $this->plan()->digest());

        $createdId = (int) DB::table('feishu_org_department_mappings')
            ->where('source_department_id', 'od-child')
            ->value('dootask_department_id');
        $this->assertGreaterThan(18, $createdId);
        $this->assertDatabaseHas('user_departments', [
            'id' => $createdId, 'parent_id' => 2, 'name' => '天津缴费组', 'owner_userid' => 81,
        ]);
        $this->assertSame([2, 9], $this->departmentsFor(48));
        // Ancestor membership keeps child-department members inside the parent department and its group.
        $this->assertSame([2, 17, $createdId], $this->departmentsFor(81));
        $this->assertSame([2, 17, $createdId], $this->departmentsFor(62));
        $this->assertSame([18], $this->departmentsFor(100));

        $rootDialog = DB::table('user_departments')->where('id', 2)->value('dialog_id');
        $childDialog = DB::table('user_departments')->where('id', $createdId)->value('dialog_id');
        $this->assertSame([48, 62, 81, 99], $this->dialogUsers((int) $rootDialog));
        $this->assertSame([62, 81], $this->dialogUsers((int) $childDialog));
        $this->assertSame($messagesBefore, DB::table('web_socket_dialog_msgs')->count());
        $this->assertNotSame('before-sync', Cache::get('UserDepartment::rand'));

        $this->assertSame(FeishuOrgSyncBatch::STATUS_APPLIED, $batch->status);
        $this->assertSame(1, $batch->countersArray()['createdDepartments']);
        $this->assertSame(2, $batch->countersArray()['legacyRetained']);
        $snapshots = $batch->pre_snapshot . $batch->post_snapshot;
        $this->assertStringNotContainsString('secret@example.com', $snapshots);
        $this->assertStringNotContainsString('password-value', $snapshots);
        $this->assertStringNotContainsString('identity-value', $snapshots);
    }

    public function test_apply_rejects_target_drift_without_writing_any_rows(): void
    {
        $this->seedOrganization();
        DB::table('user_departments')->where('id', 2)->update(['name' => '已被人工修改']);
        $before = $this->writeCounts();

        try {
            (new OrgSyncService())->apply($this->plan(), $this->plan()->digest());
            $this->fail('Expected target precondition mismatch.');
        } catch (ApiException $e) {
            $this->assertSame('组织目标状态已变化', $e->getMessage());
        }

        $this->assertSame($before, $this->writeCounts());
        $this->assertSame(0, DB::table('feishu_org_sync_batches')->count());
    }

    public function test_apply_rejects_an_owner_who_became_disabled_after_plan_generation(): void
    {
        $this->seedOrganization();
        DB::table('users')->where('userid', 81)->update(['disable_at' => now()]);
        $before = $this->writeCounts();
        $plan = $this->plan();

        try {
            (new OrgSyncService())->apply($plan, $plan->digest());
            $this->fail('Expected owner validation failure.');
        } catch (ApiException $e) {
            $this->assertSame('组织计划负责人状态无效', $e->getMessage());
        }

        $this->assertSame($before, $this->writeCounts());
        $this->assertSame(0, DB::table('feishu_org_sync_batches')->count());
    }

    /**
     * @dataProvider failureStages
     */
    public function test_apply_rolls_back_every_write_when_a_stage_fails(string $stage): void
    {
        $this->seedOrganization();
        $before = $this->writeCounts();
        $service = new OrgSyncService(function (string $checkpoint) use ($stage): void {
            if ($checkpoint === $stage) {
                throw new \RuntimeException('injected failure');
            }
        });

        try {
            $service->apply($this->plan(), $this->plan()->digest());
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected failure', $e->getMessage());
        }

        $this->assertSame($before, $this->writeCounts());
        $this->assertSame(0, DB::table('feishu_org_sync_batches')->count());
    }

    public function failureStages(): array
    {
        return [
            'department create' => ['after_department'],
            'mapping write' => ['after_mapping'],
            'user update' => ['after_user'],
            'group update' => ['after_group'],
        ];
    }

    public function test_repeat_apply_is_idempotent(): void
    {
        $this->seedOrganization();
        $service = new OrgSyncService();
        $plan = $this->plan();
        $first = $service->apply($plan, $plan->digest());
        $afterFirst = $this->writeCounts();

        $second = $service->apply($plan, $plan->digest());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($afterFirst, $this->writeCounts());
        $this->assertSame(1, DB::table('feishu_org_sync_batches')->count());
    }

    public function test_preview_reports_changes_without_writing(): void
    {
        $this->seedOrganization();
        $before = $this->writeCounts();

        $preview = (new OrgSyncService())->preview($this->plan());

        $this->assertSame([
            'createdDepartments' => 1,
            'updatedDepartments' => 0,
            'updatedUsers' => 2,
            'updatedGroups' => 2,
            'legacyRetained' => 2,
            'ownerRequired' => 1,
        ], $preview);
        $this->assertSame($before, $this->writeCounts());
        $this->assertSame(0, DB::table('feishu_org_sync_batches')->count());
    }

    public function test_restore_reproduces_the_pre_apply_snapshot(): void
    {
        $this->seedOrganization();
        $service = new OrgSyncService();
        $plan = $this->plan();
        $before = $this->writeCounts();
        $batch = $service->apply($plan, $plan->digest());

        $restored = $service->restore($batch->id, hash('sha256', $batch->post_snapshot));

        $this->assertSame(FeishuOrgSyncBatch::STATUS_RESTORED, $restored->status);
        $this->assertSame($before, $this->writeCounts());
        $this->assertSame(0, DB::table('feishu_org_department_mappings')->count());
    }

    public function test_restore_forgets_the_cache_for_a_deleted_created_department(): void
    {
        $this->seedOrganization();
        $service = new OrgSyncService();
        $plan = $this->plan();
        $batch = $service->apply($plan, $plan->digest());
        $childId = (int) DB::table('feishu_org_department_mappings')
            ->where('source_department_id', 'od-child')->value('dootask_department_id');
        Cache::forever('department_info_' . $childId, 'stale-created-department');

        $service->restore($batch->id, hash('sha256', $batch->post_snapshot));

        $this->assertNull(Cache::get('department_info_' . $childId));
    }

    public function test_restore_refuses_current_state_drift_without_writes(): void
    {
        $this->seedOrganization();
        $service = new OrgSyncService();
        $plan = $this->plan();
        $batch = $service->apply($plan, $plan->digest());
        DB::table('users')->where('userid', 62)->update(['department' => ',17,18,']);
        $beforeRestore = $this->writeCounts();

        try {
            $service->restore($batch->id, hash('sha256', $batch->post_snapshot));
            $this->fail('Expected restore conflict.');
        } catch (ApiException $e) {
            $this->assertSame('组织恢复状态冲突', $e->getMessage());
        }

        $this->assertSame($beforeRestore, $this->writeCounts());
        $this->assertSame(FeishuOrgSyncBatch::STATUS_APPLIED, $batch->fresh()->status);
    }

    public function test_restore_refuses_to_delete_a_new_group_that_has_messages(): void
    {
        $this->seedOrganization();
        $service = new OrgSyncService();
        $plan = $this->plan();
        $batch = $service->apply($plan, $plan->digest());
        $childId = DB::table('feishu_org_department_mappings')->where('source_department_id', 'od-child')
            ->value('dootask_department_id');
        $dialogId = DB::table('user_departments')->where('id', $childId)->value('dialog_id');
        DB::table('web_socket_dialog_msgs')->insert([
            'dialog_id' => $dialogId, 'userid' => 62, 'type' => 'text', 'msg' => '业务消息',
        ]);
        $beforeRestore = $this->writeCounts();

        try {
            $service->restore($batch->id, hash('sha256', $batch->post_snapshot));
            $this->fail('Expected used group conflict.');
        } catch (ApiException $e) {
            $this->assertSame('组织恢复新群已被使用', $e->getMessage());
        }

        $this->assertSame($beforeRestore, $this->writeCounts());
        $this->assertSame(1, DB::table('web_socket_dialog_msgs')->where('dialog_id', $dialogId)->count());
    }

    public function test_restore_refuses_a_new_group_with_an_unexpected_member(): void
    {
        $this->seedOrganization();
        $service = new OrgSyncService();
        $plan = $this->plan();
        $batch = $service->apply($plan, $plan->digest());
        $childId = DB::table('feishu_org_department_mappings')->where('source_department_id', 'od-child')
            ->value('dootask_department_id');
        $dialogId = DB::table('user_departments')->where('id', $childId)->value('dialog_id');
        DB::table('web_socket_dialog_users')->insert([
            'dialog_id' => $dialogId, 'userid' => 99, 'bot' => 0, 'important' => 0, 'inviter' => 0,
        ]);
        $beforeRestore = $this->writeCounts();

        try {
            $service->restore($batch->id, hash('sha256', $batch->post_snapshot));
            $this->fail('Expected unexpected member conflict.');
        } catch (ApiException $e) {
            $this->assertSame('组织恢复状态冲突', $e->getMessage());
        }

        $this->assertSame($beforeRestore, $this->writeCounts());
    }

    private function seedOrganization(): void
    {
        DB::table('web_socket_dialogs')->insert([
            ['id' => 200, 'type' => 'group', 'group_type' => 'department', 'name' => '金融推广部', 'owner_id' => 48],
            ['id' => 217, 'type' => 'group', 'group_type' => 'department', 'name' => '旧17', 'owner_id' => 69],
            ['id' => 218, 'type' => 'group', 'group_type' => 'department', 'name' => '旧18', 'owner_id' => 48],
        ]);
        DB::table('user_departments')->insert([
            ['id' => 1, 'parent_id' => 0, 'name' => '留学事业部', 'owner_userid' => 1, 'dialog_id' => 0],
            ['id' => 2, 'parent_id' => 1, 'name' => '金融推广部', 'owner_userid' => 48, 'dialog_id' => 200],
            ['id' => 9, 'parent_id' => 1, 'name' => '人工部门', 'owner_userid' => 99, 'dialog_id' => 0],
            ['id' => 17, 'parent_id' => 2, 'name' => '郑州杨传玉组', 'owner_userid' => 69, 'dialog_id' => 217],
            ['id' => 18, 'parent_id' => 2, 'name' => '郑州罗亚杰组', 'owner_userid' => 48, 'dialog_id' => 218],
        ]);
        DB::table('users')->insert([
            ['userid' => 48, 'nickname' => '雷宇航', 'department' => ',2,9,', 'email' => 'secret@example.com', 'password' => 'password-value', 'identity' => 'identity-value', 'bot' => 0],
            ['userid' => 62, 'nickname' => '王中壹', 'department' => ',2,17,', 'email' => null, 'password' => null, 'identity' => null, 'bot' => 0],
            ['userid' => 81, 'nickname' => '王金姣', 'department' => ',2,17,', 'email' => null, 'password' => null, 'identity' => null, 'bot' => 0],
            ['userid' => 99, 'nickname' => '人工群成员', 'department' => ',9,', 'email' => null, 'password' => null, 'identity' => null, 'bot' => 0],
            ['userid' => 100, 'nickname' => '旧组成员', 'department' => ',18,', 'email' => null, 'password' => null, 'identity' => null, 'bot' => 0],
        ]);
        DB::table('web_socket_dialog_users')->insert([
            ['dialog_id' => 200, 'userid' => 99, 'important' => 0, 'bot' => 0],
            ['dialog_id' => 217, 'userid' => 81, 'important' => 1, 'bot' => 0],
            ['dialog_id' => 218, 'userid' => 100, 'important' => 1, 'bot' => 0],
        ]);
    }

    private function plan(): OrgSyncPlan
    {
        $data = [
            'schemaVersion' => 1,
            'generatedAt' => '2026-09-21T06:00:00.000Z',
            'expiresAt' => '2026-09-21T06:15:00.000Z',
            'sourceRoot' => 'od-046de9ebfea10edd226515e26afa12e0',
            'targetRoot' => 2,
            'departments' => [
                [
                    'sourceId' => 'od-046de9ebfea10edd226515e26afa12e0', 'parentSourceId' => null,
                    'action' => 'update', 'targetId' => 2, 'name' => '金融推广部', 'ownerUserId' => 48,
                    'before' => ['parent' => 1, 'name' => '金融推广部', 'owner' => 48, 'dialog' => 200],
                ],
                [
                    'sourceId' => 'od-child', 'parentSourceId' => 'od-046de9ebfea10edd226515e26afa12e0',
                    'action' => 'create', 'createKey' => 'tianjin-payment', 'name' => '天津缴费组',
                    'ownerUserId' => 81, 'before' => null,
                ],
            ],
            'members' => [
                ['userId' => 48, 'before' => [2, 9], 'preserve' => [9], 'managed' => [
                    ['sourceId' => 'od-046de9ebfea10edd226515e26afa12e0', 'reason' => 'direct'],
                ]],
                ['userId' => 62, 'before' => [2, 17], 'preserve' => [17], 'managed' => [
                    ['sourceId' => 'od-child', 'reason' => 'direct'],
                    ['sourceId' => 'od-046de9ebfea10edd226515e26afa12e0', 'reason' => 'ancestor'],
                ]],
                ['userId' => 81, 'before' => [2, 17], 'preserve' => [17], 'managed' => [
                    ['sourceId' => 'od-child', 'reason' => 'owner_required'],
                    ['sourceId' => 'od-046de9ebfea10edd226515e26afa12e0', 'reason' => 'ancestor'],
                ]],
            ],
            'findings' => [
                ['code' => 'LEGACY_RETAINED', 'targetId' => 17],
                ['code' => 'LEGACY_RETAINED', 'targetId' => 18],
            ],
        ];
        $data['digest'] = hash('sha256', $this->canonicalJson($data));
        return OrgSyncPlan::fromJson(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            new DateTimeImmutable('2026-09-21T06:05:00Z')
        );
    }

    private function canonicalJson($value): string
    {
        if (is_array($value)) {
            $isList = !$value || array_keys($value) === range(0, count($value) - 1);
            if (!$isList) ksort($value, SORT_STRING);
            foreach ($value as $key => $item) $value[$key] = json_decode($this->canonicalJson($item), true);
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function departmentsFor(int $userId): array
    {
        $raw = (string) DB::table('users')->where('userid', $userId)->value('department');
        $ids = array_values(array_filter(array_map('intval', explode(',', trim($raw, ',')))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function dialogUsers(int $dialogId): array
    {
        return DB::table('web_socket_dialog_users')->where('dialog_id', $dialogId)
            ->orderBy('userid')->pluck('userid')->map(fn ($id) => (int) $id)->all();
    }

    private function writeCounts(): array
    {
        return [
            'departments' => DB::table('user_departments')->count(),
            'dialogs' => DB::table('web_socket_dialogs')->count(),
            'dialogUsers' => DB::table('web_socket_dialog_users')->count(),
            'mappings' => DB::table('feishu_org_department_mappings')->count(),
            'users' => DB::table('users')->orderBy('userid')->pluck('department', 'userid')->all(),
        ];
    }
}
