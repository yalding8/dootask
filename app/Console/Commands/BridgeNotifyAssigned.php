<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Triggers feishu-bridge to push task-assigned notifications.
 */

namespace App\Console\Commands;

use App\Module\BridgeClient;
use Illuminate\Console\Command;

class BridgeNotifyAssigned extends Command
{
    protected $signature = 'bridge:notify-assigned';
    protected $description = '[飞书] 推送新分配任务通知（每 10 分钟一次）';

    public function handle(): int
    {
        BridgeClient::notifyAssigned();
        return 0;
    }
}
