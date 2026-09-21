<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Module\FeishuOrgSync\OrgSyncService;
use Illuminate\Console\Command;

class FeishuOrgSyncRestore extends Command
{
    protected $signature = 'org-sync:restore
        {batch : 要恢复的同步批次 ID}
        {--confirm-post-digest= : 正式恢复时必须提供完整写后摘要}
        {--dry-run : 强制只预览}';

    protected $description = '预览或恢复飞书组织同步批次（默认只预览）';

    public function handle(): int
    {
        try {
            $batchId = filter_var($this->argument('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$batchId) {
                throw new ApiException('组织同步批次无效');
            }
            $service = new OrgSyncService();
            $preview = $service->previewRestore((int) $batchId);
            $confirm = trim((string) $this->option('confirm-post-digest'));
            if ($this->option('dry-run') || $confirm === '') {
                $this->line('[DRY-RUN] batch=' . $batchId . ' postDigest=' . $preview['postDigest']);
                $this->line($this->formatCounters($preview['counters']));
                return 0;
            }
            if (!hash_equals($preview['postDigest'], $confirm)) {
                throw new ApiException('组织恢复摘要不匹配');
            }
            $batch = $service->restore((int) $batchId, $confirm);
            $this->line('[RESTORED] batch=' . $batch->id . ' postDigest=' . $preview['postDigest']);
            $this->line($this->formatCounters($batch->countersArray()));
            return 0;
        } catch (ApiException $e) {
            $this->error($e->getMessage());
            return $this->apiExitCode($e->getMessage());
        } catch (\Throwable $e) {
            $this->error('组织恢复执行失败');
            return 1;
        }
    }

    private function formatCounters(array $counters): string
    {
        $ordered = [
            'createdDepartments', 'updatedDepartments', 'updatedUsers',
            'updatedGroups', 'legacyRetained', 'ownerRequired',
        ];
        return implode(' ', array_map(fn ($key) => $key . '=' . (int) ($counters[$key] ?? 0), $ordered));
    }

    private function apiExitCode(string $message): int
    {
        foreach (['状态冲突', '正在执行', '新群已被使用'] as $conflict) {
            if (strpos($message, $conflict) !== false) {
                return 3;
            }
        }
        return 2;
    }
}
