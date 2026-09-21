<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace App\Models;

class FeishuOrgSyncBatch extends AbstractModel
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_RESTORED = 'restored';
    public const STATUS_FAILED = 'failed';

    protected $table = 'feishu_org_sync_batches';

    public function countersArray(): array
    {
        if (is_array($this->counters)) {
            return $this->counters;
        }
        return json_decode((string) $this->counters, true) ?: [];
    }
}
