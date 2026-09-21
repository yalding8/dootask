<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace Tests\Feature;

use App\Http\Controllers\Api\UsersController;
use App\Services\RequestContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManagedDepartmentProtectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', ManagedDepartmentTestRequest::create('/'));
        DB::table('users')->insert([
            ['userid' => 1, 'nickname' => '管理员', 'email' => 'admin@example.test', 'identity' => ',admin,', 'department' => ',1,', 'bot' => 0],
            ['userid' => 48, 'nickname' => '雷宇航', 'email' => 'owner@example.test', 'identity' => '', 'department' => ',2,9,', 'bot' => 0],
        ]);
        DB::table('user_departments')->insert([
            ['id' => 1, 'parent_id' => 0, 'name' => '留学事业部', 'owner_userid' => 1, 'dialog_id' => 0],
            ['id' => 2, 'parent_id' => 1, 'name' => '金融推广部', 'owner_userid' => 48, 'dialog_id' => 0],
            ['id' => 3, 'parent_id' => 2, 'name' => '欧亚部', 'owner_userid' => 48, 'dialog_id' => 0],
            ['id' => 4, 'parent_id' => 3, 'name' => '天津缴费组', 'owner_userid' => 48, 'dialog_id' => 0],
            ['id' => 5, 'parent_id' => 4, 'name' => '天津王金姣组', 'owner_userid' => 48, 'dialog_id' => 0],
            ['id' => 9, 'parent_id' => 0, 'name' => '人工部门', 'owner_userid' => 1, 'dialog_id' => 0],
            ['id' => 10, 'parent_id' => 0, 'name' => '甲部门', 'owner_userid' => 1, 'dialog_id' => 0],
            ['id' => 11, 'parent_id' => 10, 'name' => '乙部门', 'owner_userid' => 1, 'dialog_id' => 0],
            ['id' => 12, 'parent_id' => 11, 'name' => '丙部门', 'owner_userid' => 1, 'dialog_id' => 0],
        ]);
        foreach ([2, 3, 4, 5] as $id) {
            DB::table('feishu_org_department_mappings')->insert([
                'source_root' => 'od-046de9ebfea10edd226515e26afa12e0',
                'source_department_id' => 'od-' . $id,
                'dootask_department_id' => $id,
            ]);
        }
        RequestContext::save('auth', \App\Models\User::find(1));
    }

    protected function tearDown(): void
    {
        RequestContext::clean();
        parent::tearDown();
    }

    public function test_department_list_exposes_managed_source_through_five_levels(): void
    {
        $response = (new UsersController())->department__list();
        $items = collect($response['data'])->keyBy('id');

        $this->assertSame('od-2', $items[2]->managed_source);
        $this->assertSame('od-5', $items[5]->managed_source);
        $this->assertNull($items[9]->managed_source);
        $this->assertSame(4, (int) $items[5]->parent_id);
    }

    public function test_managed_nodes_reject_create_edit_delete_and_member_sync(): void
    {
        $controller = new UsersController();

        request()->replace(['name' => '人工子部门', 'parent_id' => 2, 'owner_userid' => 48]);
        $this->assertSame('该部门由飞书组织同步管理', $controller->department__add()['msg']);

        request()->replace(['id' => 2, 'name' => '修改名称', 'parent_id' => 1, 'owner_userid' => 48]);
        $this->assertSame('该部门由飞书组织同步管理', $controller->department__add()['msg']);

        request()->replace(['id' => 5]);
        $this->assertSame('该部门由飞书组织同步管理', $controller->department__del()['msg']);
        $this->assertSame('该部门由飞书组织同步管理', $controller->department__sync()['msg']);

        request()->replace(['userid' => 48, 'type' => 'department', 'department' => [9]]);
        $this->assertSame('该部门由飞书组织同步管理', $controller->operation()['msg']);
        $this->assertSame([2, 9], \App\Models\User::find(48)->department);
    }

    public function test_unmanaged_api_still_rejects_depth_four_and_descendant_cycles(): void
    {
        $controller = new UsersController();
        request()->replace(['name' => '第四层部门', 'parent_id' => 12, 'owner_userid' => 48]);
        $this->assertSame('部门层级最多只能创建3级', $controller->department__add()['msg']);

        request()->replace(['id' => 10, 'name' => '甲部门', 'parent_id' => 12, 'owner_userid' => 1]);
        $this->assertSame('不能选择自己的子部门作为上级部门', $controller->department__add()['msg']);
        $this->assertSame(0, (int) DB::table('user_departments')->where('id', 10)->value('parent_id'));
    }
}

class ManagedDepartmentTestRequest extends Request
{
    public function attributes(): void
    {
    }
}
