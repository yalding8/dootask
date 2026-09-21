<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FeishuOrgSyncServiceTest extends TestCase
{
    public function test_schema_persists_unique_department_mappings_and_restorable_batches(): void
    {
        $this->assertTrue(Schema::hasColumns('feishu_org_department_mappings', [
            'source_root', 'source_department_id', 'dootask_department_id',
        ]));
        $this->assertTrue(Schema::hasColumns('feishu_org_sync_batches', [
            'plan_digest', 'status', 'pre_snapshot', 'post_snapshot', 'counters', 'error_code', 'operator_userid',
        ]));
    }
}
