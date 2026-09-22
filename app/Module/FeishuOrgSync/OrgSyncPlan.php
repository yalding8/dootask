<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace App\Module\FeishuOrgSync;

use App\Exceptions\ApiException;
use DateTimeImmutable;

final class OrgSyncPlan
{
    private const SOURCE_ROOT = 'od-046de9ebfea10edd226515e26afa12e0';
    private const TARGET_ROOT = 2;
    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromJson(string $json, DateTimeImmutable $now): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('组织计划 JSON 无效');
        }
        self::requireKeys($data, [
            'schemaVersion', 'generatedAt', 'expiresAt', 'sourceRoot', 'targetRoot',
            'departments', 'members', 'findings', 'digest',
        ]);
        if ($data['schemaVersion'] !== 1 || $data['sourceRoot'] !== self::SOURCE_ROOT || $data['targetRoot'] !== self::TARGET_ROOT) {
            throw new ApiException('组织计划范围不受支持');
        }
        if (!is_string($data['digest']) || !preg_match('/^[a-f0-9]{64}$/', $data['digest'])) {
            throw new ApiException('组织计划摘要无效');
        }
        $digestData = $data;
        unset($digestData['digest']);
        $actual = hash('sha256', self::canonicalJson($digestData));
        if (!hash_equals($data['digest'], $actual)) {
            throw new ApiException('组织计划摘要不匹配');
        }
        try {
            $generated = new DateTimeImmutable($data['generatedAt']);
            $expires = new DateTimeImmutable($data['expiresAt']);
        } catch (\Throwable $e) {
            throw new ApiException('组织计划时间无效');
        }
        if ($expires <= $now) {
            throw new ApiException('组织计划已过期');
        }
        if ($generated > $now->modify('+1 minute') || $expires->getTimestamp() - $generated->getTimestamp() !== 900) {
            throw new ApiException('组织计划时间无效');
        }
        self::validateDepartments($data['departments']);
        self::validateMembers($data['members'], $data['departments']);
        self::validateFindings($data['findings']);
        return new self($data);
    }

    public function digest(): string { return $this->data['digest']; }
    public function targetRoot(): int { return $this->data['targetRoot']; }
    public function departments(): array { return $this->data['departments']; }
    public function members(): array { return $this->data['members']; }
    public function findings(): array { return $this->data['findings']; }
    public function toArray(): array { return $this->data; }

    private static function validateDepartments($departments): void
    {
        if (!is_array($departments) || !$departments) throw new ApiException('组织计划部门无效');
        $byId = []; $targets = []; $createKeys = [];
        foreach ($departments as $department) {
            if (!is_array($department)) throw new ApiException('组织计划部门无效');
            $common = ['sourceId', 'parentSourceId', 'action', 'name', 'ownerUserId', 'before'];
            $action = $department['action'] ?? null;
            self::requireKeys($department, array_merge($common, [$action === 'create' ? 'createKey' : 'targetId']));
            $sourceId = $department['sourceId'];
            if (!is_string($sourceId) || isset($byId[$sourceId])) throw new ApiException('组织计划部门标识重复');
            if (!is_string($department['name']) || mb_strlen($department['name']) < 2 || mb_strlen($department['name']) > 20
                || preg_match('/[~!@#$%^&*()+\-_=.:?<>,]/', $department['name'])) {
                throw new ApiException('组织计划部门名称无效');
            }
            if (!is_int($department['ownerUserId']) || $department['ownerUserId'] <= 0) throw new ApiException('组织计划负责人无效');
            if ($action === 'create') {
                if ($department['before'] !== null || !is_string($department['createKey'])
                    || !preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $department['createKey'])
                    || isset($createKeys[$department['createKey']])) throw new ApiException('组织计划新部门无效');
                $createKeys[$department['createKey']] = true;
            } elseif ($action === 'update') {
                if (!is_int($department['targetId']) || $department['targetId'] <= 0 || isset($targets[$department['targetId']])
                    || !is_array($department['before'])) throw new ApiException('组织计划目标部门无效');
                self::requireKeys($department['before'], ['parent', 'name', 'owner', 'dialog']);
                $targets[$department['targetId']] = true;
            } else {
                throw new ApiException('组织计划动作无效');
            }
            $byId[$sourceId] = $department;
        }
        foreach ($byId as $sourceId => $department) {
            $path = []; $cursor = $sourceId;
            while ($cursor !== null) {
                if (isset($path[$cursor]) || !isset($byId[$cursor])) throw new ApiException('组织计划部门层级无效');
                $path[$cursor] = true;
                $cursor = $byId[$cursor]['parentSourceId'];
            }
            if (count($path) > 4) throw new ApiException('组织计划部门层级超限');
        }
        if (!isset($byId[self::SOURCE_ROOT]) || !array_key_exists('parentSourceId', $byId[self::SOURCE_ROOT])
            || $byId[self::SOURCE_ROOT]['parentSourceId'] !== null
            || $byId[self::SOURCE_ROOT]['action'] !== 'update'
            || $byId[self::SOURCE_ROOT]['targetId'] !== self::TARGET_ROOT) {
            throw new ApiException('组织计划根部门无效');
        }
    }

    private static function validateMembers($members, array $departments): void
    {
        if (!is_array($members)) throw new ApiException('组织计划成员无效');
        $sourceIds = array_fill_keys(array_column($departments, 'sourceId'), true); $users = [];
        $parentOf = array_column($departments, 'parentSourceId', 'sourceId');
        foreach ($members as $member) {
            self::requireKeys($member, ['userId', 'before', 'preserve', 'managed']);
            if (!is_int($member['userId']) || $member['userId'] <= 0 || isset($users[$member['userId']])
                || !is_array($member['before']) || !is_array($member['preserve']) || !is_array($member['managed'])) {
                throw new ApiException('组织计划成员无效');
            }
            $users[$member['userId']] = true;
            if (count($member['preserve']) + count($member['managed']) > 10) throw new ApiException('组织计划成员部门超限');
            $explicit = []; $ancestors = []; $seen = [];
            foreach ($member['managed'] as $managed) {
                self::requireKeys($managed, ['sourceId', 'reason']);
                if (!isset($sourceIds[$managed['sourceId']]) || isset($seen[$managed['sourceId']])
                    || !in_array($managed['reason'], ['direct', 'owner_required', 'ancestor'], true)) {
                    throw new ApiException('组织计划成员归属无效');
                }
                $seen[$managed['sourceId']] = true;
                if ($managed['reason'] === 'ancestor') $ancestors[] = $managed['sourceId'];
                else $explicit[] = $managed['sourceId'];
            }
            // 'ancestor' entries keep parent department groups populated (DooTask groups hold direct members only).
            // Each one must be a real ancestor of a direct/owner_required department of the same member.
            foreach ($ancestors as $ancestor) {
                $justified = false;
                foreach ($explicit as $sourceId) {
                    for ($cursor = $parentOf[$sourceId]; $cursor !== null; $cursor = $parentOf[$cursor]) {
                        if ($cursor === $ancestor) { $justified = true; break 2; }
                    }
                }
                if (!$justified) throw new ApiException('组织计划成员归属无效');
            }
        }
    }

    private static function validateFindings($findings): void
    {
        if (!is_array($findings)) throw new ApiException('组织计划保留项无效');
        // The retained ids used to be hard-coded as [17, 18]; both were retired on 2026-09-22, which
        // left the whitelist asserting nothing and blocking any future retention. Shape is checked
        // here, reality (the department exists and this plan does not touch it) in verifyPreconditions().
        $seen = [];
        foreach ($findings as $finding) {
            self::requireKeys($finding, ['code', 'targetId']);
            if ($finding['code'] !== 'LEGACY_RETAINED' || !is_int($finding['targetId']) || $finding['targetId'] <= 0
                || in_array($finding['targetId'], $seen, true)) {
                throw new ApiException('组织计划保留项无效');
            }
            $seen[] = $finding['targetId'];
        }
    }

    private static function requireKeys($value, array $keys): void
    {
        if (!is_array($value)) throw new ApiException('组织计划字段不受支持');
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) throw new ApiException('组织计划字段不受支持');
    }

    private static function canonicalJson($value): string
    {
        $normalized = self::canonicalize($value);
        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) return $value;
        $isList = !$value || array_keys($value) === range(0, count($value) - 1);
        if ($isList) return array_map([self::class, 'canonicalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);
        return $value;
    }
}
