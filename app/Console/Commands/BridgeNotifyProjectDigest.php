<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Triggers feishu-bridge to push the daily project task digest to a Feishu group.
 */

namespace App\Console\Commands;

use App\Module\BridgeClient;
use Illuminate\Console\Command;

class BridgeNotifyProjectDigest extends Command
{
    protected $signature = 'bridge:notify-project-digest';
    protected $description = '[飞书] 次日 09:00 推送项目每日任务播报到飞书群';

    public function handle(): int
    {
        BridgeClient::notifyProjectDigest();
        return 0;
    }
}
