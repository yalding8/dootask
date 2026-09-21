<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace App\Module\FeishuOrgSync;

use App\Exceptions\ApiException;
use App\Models\FeishuOrgDepartmentMapping;
use App\Models\FeishuOrgSyncBatch;
use App\Models\WebSocketDialog;
use App\Module\Doo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class OrgSyncService
{
    private const LOCK_NAME = 'feishu-org-sync:2';
    private $faultInjector;

    public function __construct(?callable $faultInjector = null)
    {
        $this->faultInjector = $faultInjector;
    }

    public function preview(OrgSyncPlan $plan): array
    {
        return DB::transaction(function () use ($plan) {
            $resolved = $this->verifyPreconditions($plan);
            $counters = $this->emptyCounters($plan);
            $plannedUsers = $this->sortedIds(array_column($plan->members(), 'userId'));
            foreach ($plan->departments() as $department) {
                if ($department['action'] === 'create') {
                    $counters['createdDepartments']++;
                } else {
                    $parent = $department['parentSourceId'] === null
                        ? $department['before']['parent']
                        : $resolved[$department['parentSourceId']];
                    if ($parent === null || $department['before']['parent'] !== $parent
                        || $department['before']['name'] !== $department['name']
                        || $department['before']['owner'] !== $department['ownerUserId']) {
                        $counters['updatedDepartments']++;
                    }
                }
                $desired = $this->desiredGroupUsers($plan, $department);
                if ($department['action'] === 'create') {
                    if ($desired !== [$department['ownerUserId']]) {
                        $counters['updatedGroups']++;
                    }
                    continue;
                }
                $dialog = DB::table('web_socket_dialogs')->where('id', $department['before']['dialog'])->first();
                $existing = DB::table('web_socket_dialog_users')->where('dialog_id', $department['before']['dialog'])
                    ->pluck('userid')->map(fn ($id) => (int) $id)->all();
                $proposed = $this->sortedIds(array_merge(array_diff($existing, $plannedUsers), $desired));
                if (!$dialog || (string) $dialog->name !== $department['name']
                    || (int) $dialog->owner_id !== $department['ownerUserId']
                    || (string) $dialog->group_type !== 'department'
                    || $this->sortedIds($existing) !== $proposed) {
                    $counters['updatedGroups']++;
                }
            }
            foreach ($plan->members() as $member) {
                $final = array_map(fn ($id) => 'id:' . (int) $id, $member['preserve']);
                foreach ($member['managed'] as $managed) {
                    if ($managed['reason'] === 'owner_required') {
                        $counters['ownerRequired']++;
                    }
                    $targetId = $resolved[$managed['sourceId']];
                    $final[] = $targetId === null ? 'source:' . $managed['sourceId'] : 'id:' . $targetId;
                }
                $normalizedFinal = array_values(array_unique($final));
                sort($normalizedFinal, SORT_STRING);
                $before = array_map(fn ($id) => 'id:' . (int) $id, $member['before']);
                sort($before, SORT_STRING);
                if ($normalizedFinal !== $before) {
                    $counters['updatedUsers']++;
                }
            }
            return $counters;
        }, 1);
    }

    public function apply(OrgSyncPlan $plan, string $expectedDigest): FeishuOrgSyncBatch
    {
        if (!hash_equals($plan->digest(), $expectedDigest)) {
            throw new ApiException('组织计划确认摘要不匹配');
        }
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::LOCK_NAME]);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw new ApiException('组织同步正在执行');
        }

        try {
            $batch = DB::transaction(function () use ($plan) {
                $existing = FeishuOrgSyncBatch::wherePlanDigest($plan->digest())->lockForUpdate()->first();
                if ($existing && $existing->status === FeishuOrgSyncBatch::STATUS_APPLIED) {
                    return $existing;
                }

                $resolved = $this->verifyPreconditions($plan);
                $preSnapshot = $this->snapshot($plan, $resolved);
                $counters = $this->emptyCounters($plan);
                $resolved = $this->applyDepartments($plan, $resolved, $counters);
                $this->applyMembers($plan, $resolved, $counters);
                $this->applyGroups($plan, $resolved, $counters);
                $postSnapshot = $this->snapshot($plan, $resolved);

                $batch = FeishuOrgSyncBatch::createInstance([
                    'plan_digest' => $plan->digest(),
                    'status' => FeishuOrgSyncBatch::STATUS_APPLIED,
                    'source_root' => $plan->toArray()['sourceRoot'],
                    'target_root' => $plan->targetRoot(),
                    'pre_snapshot' => $this->encode($preSnapshot),
                    'post_snapshot' => $this->encode($postSnapshot),
                    'counters' => $this->encode($counters),
                    'error_code' => '',
                    'operator_userid' => Doo::userId(),
                ]);
                $batch->save();
                return $batch;
            }, 1);
            Cache::forever('UserDepartment::rand', bin2hex(random_bytes(16)));
            foreach ($plan->departments() as $department) {
                if (isset($department['targetId'])) {
                    Cache::forget('department_info_' . $department['targetId']);
                }
            }
            return $batch;
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        }
    }

    public function restore(int $batchId, string $expectedPostDigest): FeishuOrgSyncBatch
    {
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::LOCK_NAME]);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw new ApiException('组织同步正在执行');
        }
        try {
            $batch = DB::transaction(function () use ($batchId, $expectedPostDigest) {
                $batch = FeishuOrgSyncBatch::where('id', $batchId)->lockForUpdate()->first();
                if (!$batch || $batch->status !== FeishuOrgSyncBatch::STATUS_APPLIED) {
                    throw new ApiException('组织同步批次不可恢复');
                }
                if (!hash_equals(hash('sha256', (string) $batch->post_snapshot), $expectedPostDigest)) {
                    throw new ApiException('组织恢复摘要不匹配');
                }
                $pre = json_decode((string) $batch->pre_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $post = json_decode((string) $batch->post_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $current = $this->snapshotFromTemplate($post, (string) $batch->source_root);
                if ($current !== $post) {
                    throw new ApiException('组织恢复状态冲突');
                }
                foreach ($pre['departments'] as $sourceId => $department) {
                    if ($department === null) {
                        $dialogId = (int) $post['departments'][$sourceId]['dialog'];
                        if (DB::table('web_socket_dialog_msgs')->where('dialog_id', $dialogId)->exists()) {
                            throw new ApiException('组织恢复新群已被使用');
                        }
                    }
                }
                $this->restoreSnapshot($pre, $post, (string) $batch->source_root);
                $batch->status = FeishuOrgSyncBatch::STATUS_RESTORED;
                $batch->save();
                return $batch;
            }, 1);
            Cache::forever('UserDepartment::rand', bin2hex(random_bytes(16)));
            return $batch;
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        }
    }

    public function previewRestore(int $batchId): array
    {
        return DB::transaction(function () use ($batchId) {
            $batch = FeishuOrgSyncBatch::where('id', $batchId)->lockForUpdate()->first();
            if (!$batch || $batch->status !== FeishuOrgSyncBatch::STATUS_APPLIED) {
                throw new ApiException('组织同步批次不可恢复');
            }
            $pre = json_decode((string) $batch->pre_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $post = json_decode((string) $batch->post_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if ($this->snapshotFromTemplate($post, (string) $batch->source_root) !== $post) {
                throw new ApiException('组织恢复状态冲突');
            }
            foreach ($pre['departments'] as $sourceId => $department) {
                if ($department === null) {
                    $dialogId = (int) $post['departments'][$sourceId]['dialog'];
                    if (DB::table('web_socket_dialog_msgs')->where('dialog_id', $dialogId)->exists()) {
                        throw new ApiException('组织恢复新群已被使用');
                    }
                }
            }
            return [
                'postDigest' => hash('sha256', (string) $batch->post_snapshot),
                'counters' => $batch->countersArray(),
            ];
        }, 1);
    }

    private function verifyPreconditions(OrgSyncPlan $plan): array
    {
        $resolved = [];
        foreach ($plan->departments() as $department) {
            if ($department['action'] === 'create') {
                $mapped = FeishuOrgDepartmentMapping::whereSourceRoot($plan->toArray()['sourceRoot'])
                    ->whereSourceDepartmentId($department['sourceId'])->lockForUpdate()->first();
                if ($mapped) {
                    throw new ApiException('组织目标状态已变化');
                }
                $resolved[$department['sourceId']] = null;
                continue;
            }
            $row = DB::table('user_departments')->where('id', $department['targetId'])->lockForUpdate()->first();
            $before = $department['before'];
            if (!$row || (int) $row->parent_id !== $before['parent'] || (string) $row->name !== $before['name']
                || (int) $row->owner_userid !== $before['owner'] || (int) $row->dialog_id !== $before['dialog']) {
                throw new ApiException('组织目标状态已变化');
            }
            $sourceMapping = DB::table('feishu_org_department_mappings')
                ->where('source_root', $plan->toArray()['sourceRoot'])
                ->where('source_department_id', $department['sourceId'])->lockForUpdate()->first();
            $targetMapping = DB::table('feishu_org_department_mappings')
                ->where('dootask_department_id', $department['targetId'])->lockForUpdate()->first();
            if (($sourceMapping && (int) $sourceMapping->dootask_department_id !== (int) $department['targetId'])
                || ($targetMapping && ((string) $targetMapping->source_root !== $plan->toArray()['sourceRoot']
                    || (string) $targetMapping->source_department_id !== $department['sourceId']))) {
                throw new ApiException('组织目标状态已变化');
            }
            $resolved[$department['sourceId']] = (int) $row->id;
        }
        foreach ($plan->members() as $member) {
            $row = DB::table('users')->where('userid', $member['userId'])->lockForUpdate()->first();
            if (!$row || $this->parseDepartments($row->department) !== $this->sortedIds($member['before'])) {
                throw new ApiException('组织目标状态已变化');
            }
        }
        return $resolved;
    }

    private function applyDepartments(OrgSyncPlan $plan, array $resolved, array &$counters): array
    {
        foreach ($plan->departments() as $department) {
            $parentId = $department['parentSourceId'] === null
                ? (int) $department['before']['parent']
                : (int) $resolved[$department['parentSourceId']];
            if ($department['action'] === 'create') {
                $dialog = WebSocketDialog::withoutEvents(fn () => WebSocketDialog::createGroup(
                    $department['name'], [$department['ownerUserId']], 'department', $department['ownerUserId']
                ));
                if (!$dialog) {
                    throw new ApiException('创建部门群失败');
                }
                $id = DB::table('user_departments')->insertGetId([
                    'name' => $department['name'], 'dialog_id' => $dialog->id, 'parent_id' => $parentId,
                    'owner_userid' => $department['ownerUserId'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                $resolved[$department['sourceId']] = (int) $id;
                $counters['createdDepartments']++;
                $this->checkpoint('after_department');
            } else {
                $id = $resolved[$department['sourceId']];
                $changed = DB::table('user_departments')->where('id', $id)->update([
                    'name' => $department['name'], 'parent_id' => $parentId,
                    'owner_userid' => $department['ownerUserId'], 'updated_at' => now(),
                ]);
                $counters['updatedDepartments'] += $changed ? 1 : 0;
            }
            DB::table('feishu_org_department_mappings')->updateOrInsert(
                ['source_root' => $plan->toArray()['sourceRoot'], 'source_department_id' => $department['sourceId']],
                ['dootask_department_id' => $resolved[$department['sourceId']], 'updated_at' => now(), 'created_at' => now()]
            );
            $this->checkpoint('after_mapping');
        }
        return $resolved;
    }

    private function applyMembers(OrgSyncPlan $plan, array $resolved, array &$counters): void
    {
        foreach ($plan->members() as $member) {
            $final = $member['preserve'];
            foreach ($member['managed'] as $managed) {
                $final[] = $resolved[$managed['sourceId']];
                if ($managed['reason'] === 'owner_required') {
                    $counters['ownerRequired']++;
                }
            }
            $final = $this->sortedIds($final);
            $current = $this->parseDepartments(DB::table('users')->where('userid', $member['userId'])->value('department'));
            if ($current !== $final) {
                DB::table('users')->where('userid', $member['userId'])->update([
                    'department' => ',' . implode(',', $final) . ',', 'updated_at' => now(),
                ]);
                $counters['updatedUsers']++;
                $this->checkpoint('after_user');
            }
        }
    }

    private function applyGroups(OrgSyncPlan $plan, array $resolved, array &$counters): void
    {
        $plannedUsers = $this->sortedIds(array_column($plan->members(), 'userId'));
        foreach ($plan->departments() as $department) {
            $departmentId = $resolved[$department['sourceId']];
            $row = DB::table('user_departments')->where('id', $departmentId)->first();
            $dialogId = (int) $row->dialog_id;
            DB::table('web_socket_dialogs')->where('id', $dialogId)->update([
                'name' => $department['name'], 'owner_id' => $department['ownerUserId'],
                'group_type' => 'department', 'updated_at' => now(),
            ]);
            $desired = $this->desiredGroupUsers($plan, $department);
            $existing = DB::table('web_socket_dialog_users')->where('dialog_id', $dialogId)
                ->pluck('userid')->map(fn ($id) => (int) $id)->all();
            $remove = array_values(array_intersect(array_diff($existing, $desired), $plannedUsers));
            if ($remove) {
                DB::table('web_socket_dialog_users')->where('dialog_id', $dialogId)->whereIn('userid', $remove)->delete();
            }
            foreach (array_diff($desired, $existing) as $userId) {
                DB::table('web_socket_dialog_users')->insert([
                    'dialog_id' => $dialogId, 'userid' => $userId, 'bot' => 0, 'important' => 1,
                    'inviter' => 0, 'last_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('web_socket_dialog_users')->where('dialog_id', $dialogId)->whereIn('userid', $desired)
                ->update(['important' => 1, 'updated_at' => now()]);
            if ($remove || array_diff($desired, $existing)) {
                $counters['updatedGroups']++;
                $this->checkpoint('after_group');
            }
        }
    }

    private function snapshot(OrgSyncPlan $plan, array $resolved): array
    {
        $snapshot = ['departments' => [], 'users' => [], 'groups' => [], 'mappings' => []];
        foreach ($plan->departments() as $department) {
            $sourceId = $department['sourceId'];
            $id = $resolved[$sourceId] ?? null;
            $row = $id ? DB::table('user_departments')->where('id', $id)->first() : null;
            $snapshot['departments'][$sourceId] = $row ? [
                'id' => (int) $row->id, 'parent' => (int) $row->parent_id, 'name' => (string) $row->name,
                'owner' => (int) $row->owner_userid, 'dialog' => (int) $row->dialog_id,
            ] : null;
            $mapping = FeishuOrgDepartmentMapping::whereSourceRoot($plan->toArray()['sourceRoot'])
                ->whereSourceDepartmentId($sourceId)->first();
            $snapshot['mappings'][$sourceId] = $mapping ? (int) $mapping->dootask_department_id : null;
            if ($row && $row->dialog_id) {
                $dialog = DB::table('web_socket_dialogs')->where('id', $row->dialog_id)->first();
                $members = DB::table('web_socket_dialog_users')->where('dialog_id', $row->dialog_id)
                    ->orderBy('userid')->get()->map(fn ($member) => [
                        'userId' => (int) $member->userid, 'bot' => (int) $member->bot,
                        'important' => (int) $member->important, 'inviter' => (int) $member->inviter,
                    ])->all();
                $snapshot['groups'][$sourceId] = $dialog ? [
                    'id' => (int) $dialog->id, 'name' => (string) $dialog->name,
                    'owner' => (int) $dialog->owner_id, 'type' => (string) $dialog->type,
                    'groupType' => (string) $dialog->group_type, 'members' => $members,
                ] : null;
            } else {
                $snapshot['groups'][$sourceId] = null;
            }
        }
        foreach ($plan->members() as $member) {
            $snapshot['users'][(string) $member['userId']] = $this->parseDepartments(
                DB::table('users')->where('userid', $member['userId'])->value('department')
            );
        }
        return $snapshot;
    }

    private function desiredGroupUsers(OrgSyncPlan $plan, array $department): array
    {
        $desired = [$department['ownerUserId']];
        foreach ($plan->members() as $member) {
            foreach ($member['managed'] as $managed) {
                if ($managed['sourceId'] === $department['sourceId']) {
                    $desired[] = $member['userId'];
                    break;
                }
            }
        }
        return $this->sortedIds($desired);
    }

    private function emptyCounters(OrgSyncPlan $plan): array
    {
        return [
            'createdDepartments' => 0, 'updatedDepartments' => 0, 'updatedUsers' => 0,
            'updatedGroups' => 0, 'legacyRetained' => count($plan->findings()), 'ownerRequired' => 0,
        ];
    }

    private function snapshotFromTemplate(array $template, string $sourceRoot): array
    {
        $current = ['departments' => [], 'users' => [], 'groups' => [], 'mappings' => []];
        foreach ($template['departments'] as $sourceId => $department) {
            $id = $department['id'] ?? null;
            $row = $id ? DB::table('user_departments')->where('id', $id)->lockForUpdate()->first() : null;
            $current['departments'][$sourceId] = $row ? [
                'id' => (int) $row->id, 'parent' => (int) $row->parent_id, 'name' => (string) $row->name,
                'owner' => (int) $row->owner_userid, 'dialog' => (int) $row->dialog_id,
            ] : null;
            $mapping = DB::table('feishu_org_department_mappings')->where('source_root', $sourceRoot)
                ->where('source_department_id', $sourceId)->lockForUpdate()->first();
            $current['mappings'][$sourceId] = $mapping ? (int) $mapping->dootask_department_id : null;
            if ($row && $row->dialog_id) {
                $dialog = DB::table('web_socket_dialogs')->where('id', $row->dialog_id)->lockForUpdate()->first();
                $members = DB::table('web_socket_dialog_users')->where('dialog_id', $row->dialog_id)
                    ->orderBy('userid')->get()->map(fn ($member) => [
                        'userId' => (int) $member->userid, 'bot' => (int) $member->bot,
                        'important' => (int) $member->important, 'inviter' => (int) $member->inviter,
                    ])->all();
                $current['groups'][$sourceId] = $dialog ? [
                    'id' => (int) $dialog->id, 'name' => (string) $dialog->name,
                    'owner' => (int) $dialog->owner_id, 'type' => (string) $dialog->type,
                    'groupType' => (string) $dialog->group_type, 'members' => $members,
                ] : null;
            } else {
                $current['groups'][$sourceId] = null;
            }
        }
        foreach ($template['users'] as $userId => $_departments) {
            $current['users'][(string) $userId] = $this->parseDepartments(
                DB::table('users')->where('userid', $userId)->lockForUpdate()->value('department')
            );
        }
        return $current;
    }

    private function restoreSnapshot(array $pre, array $post, string $sourceRoot): void
    {
        foreach ($pre['users'] as $userId => $departments) {
            DB::table('users')->where('userid', $userId)->update([
                'department' => ',' . implode(',', $departments) . ',', 'updated_at' => now(),
            ]);
        }
        foreach ($pre['departments'] as $sourceId => $department) {
            if ($department === null) {
                continue;
            }
            DB::table('user_departments')->where('id', $department['id'])->update([
                'parent_id' => $department['parent'], 'name' => $department['name'],
                'owner_userid' => $department['owner'], 'dialog_id' => $department['dialog'], 'updated_at' => now(),
            ]);
            $group = $pre['groups'][$sourceId];
            if ($group) {
                DB::table('web_socket_dialogs')->where('id', $group['id'])->update([
                    'name' => $group['name'], 'owner_id' => $group['owner'], 'type' => $group['type'],
                    'group_type' => $group['groupType'], 'updated_at' => now(),
                ]);
                DB::table('web_socket_dialog_users')->where('dialog_id', $group['id'])->delete();
                foreach ($group['members'] as $member) {
                    DB::table('web_socket_dialog_users')->insert([
                        'dialog_id' => $group['id'], 'userid' => $member['userId'], 'bot' => $member['bot'],
                        'important' => $member['important'], 'inviter' => $member['inviter'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
        foreach (array_reverse(array_keys($pre['departments'])) as $sourceId) {
            if ($pre['departments'][$sourceId] !== null) {
                continue;
            }
            $department = $post['departments'][$sourceId];
            DB::table('web_socket_dialog_users')->where('dialog_id', $department['dialog'])->delete();
            DB::table('user_departments')->where('id', $department['id'])->delete();
            DB::table('web_socket_dialogs')->where('id', $department['dialog'])->delete();
        }
        foreach ($pre['mappings'] as $sourceId => $targetId) {
            $query = DB::table('feishu_org_department_mappings')->where('source_root', $sourceRoot)
                ->where('source_department_id', $sourceId);
            if ($targetId === null) {
                $query->delete();
            } else {
                $query->update(['dootask_department_id' => $targetId, 'updated_at' => now()]);
            }
        }
    }

    private function checkpoint(string $stage): void
    {
        if ($this->faultInjector) {
            ($this->faultInjector)($stage);
        }
    }

    private function parseDepartments($raw): array
    {
        if (is_array($raw)) {
            return $this->sortedIds($raw);
        }
        return $this->sortedIds(array_filter(array_map('intval', explode(',', trim((string) $raw, ',')))));
    }

    private function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
