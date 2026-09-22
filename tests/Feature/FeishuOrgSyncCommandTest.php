<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace Tests\Feature;

use App\Models\FeishuOrgSyncBatch;
use App\Module\FeishuOrgSync\OrgSyncPlan;
use App\Module\FeishuOrgSync\OrgSyncService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeishuOrgSyncCommandTest extends TestCase
{
    use DatabaseTransactions;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_apply_defaults_to_dry_run_and_writes_only_with_the_full_digest(): void
    {
        $this->seedOrganization();
        [$plan, $path] = $this->planFile();

        $this->assertSame(0, Artisan::call('org-sync:apply', ['plan' => $path]));
        $output = Artisan::output();
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString('createdDepartments=1', $output);
        $this->assertStringNotContainsString('od-046de9ebfea10edd226515e26afa12e0', $output);
        $this->assertStringNotContainsString('secret@example.com', $output);
        $this->assertSame(0, DB::table('feishu_org_sync_batches')->count());

        $this->assertSame(0, Artisan::call('org-sync:apply', [
            'plan' => $path, '--confirm-digest' => $plan->digest(),
        ]));
        $this->assertStringContainsString('APPLIED', Artisan::output());
        $this->assertSame(1, DB::table('feishu_org_sync_batches')->count());
    }

    public function test_apply_rejects_a_wrong_digest_without_writes(): void
    {
        $this->seedOrganization();
        [, $path] = $this->planFile();

        $exit = Artisan::call('org-sync:apply', [
            'plan' => $path, '--confirm-digest' => str_repeat('0', 64),
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('确认摘要不匹配', Artisan::output());
        $this->assertSame(0, DB::table('feishu_org_sync_batches')->count());
    }

    public function test_apply_rejects_relative_and_world_writable_plan_files(): void
    {
        $this->seedOrganization();
        [, $path] = $this->planFile();
        chmod($path, 0666);

        $this->assertSame(2, Artisan::call('org-sync:apply', ['plan' => $path]));
        $this->assertStringContainsString('文件权限不安全', Artisan::output());
        $this->assertSame(2, Artisan::call('org-sync:apply', ['plan' => basename($path)]));
        $this->assertStringContainsString('必须使用绝对路径', Artisan::output());
    }

    public function test_restore_defaults_to_dry_run_and_requires_the_post_digest(): void
    {
        $this->seedOrganization();
        [$plan] = $this->planFile();
        $service = new OrgSyncService();
        $batch = $service->apply($plan, $plan->digest());
        $postDigest = hash('sha256', $batch->post_snapshot);

        $this->assertSame(0, Artisan::call('org-sync:restore', ['batch' => $batch->id]));
        $this->assertStringContainsString('DRY-RUN', Artisan::output());
        $this->assertSame(FeishuOrgSyncBatch::STATUS_APPLIED, $batch->fresh()->status);

        $this->assertSame(2, Artisan::call('org-sync:restore', [
            'batch' => $batch->id, '--confirm-post-digest' => str_repeat('0', 64),
        ]));
        $this->assertSame(FeishuOrgSyncBatch::STATUS_APPLIED, $batch->fresh()->status);

        $this->assertSame(0, Artisan::call('org-sync:restore', [
            'batch' => $batch->id, '--confirm-post-digest' => $postDigest,
        ]));
        $this->assertStringContainsString('RESTORED', Artisan::output());
        $this->assertSame(FeishuOrgSyncBatch::STATUS_RESTORED, $batch->fresh()->status);
    }

    private function planFile(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $data = [
            'schemaVersion' => 1,
            'generatedAt' => $now->format('Y-m-d\TH:i:s.000\Z'),
            'expiresAt' => $now->modify('+15 minutes')->format('Y-m-d\TH:i:s.000\Z'),
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
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = tempnam(sys_get_temp_dir(), 'org-plan-');
        file_put_contents($path, $json);
        chmod($path, 0600);
        $this->temporaryFiles[] = $path;
        return [OrgSyncPlan::fromJson($json, $now), $path];
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
            ['userid' => 48, 'nickname' => '雷宇航', 'department' => ',2,9,', 'email' => 'secret@example.com', 'password' => null, 'identity' => null, 'bot' => 0],
            ['userid' => 62, 'nickname' => '王中壹', 'department' => ',2,17,', 'email' => null, 'password' => null, 'identity' => null, 'bot' => 0],
            ['userid' => 81, 'nickname' => '王金姣', 'department' => ',2,17,', 'email' => null, 'password' => null, 'identity' => null, 'bot' => 0],
            ['userid' => 99, 'nickname' => '人工群成员', 'department' => ',9,', 'email' => null, 'password' => null, 'identity' => null, 'bot' => 0],
        ]);
        DB::table('web_socket_dialog_users')->insert([
            ['dialog_id' => 200, 'userid' => 99, 'important' => 0, 'bot' => 0],
            ['dialog_id' => 217, 'userid' => 81, 'important' => 1, 'bot' => 0],
        ]);
    }
}
