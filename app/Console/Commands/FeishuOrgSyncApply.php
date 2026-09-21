<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Module\FeishuOrgSync\OrgSyncPlan;
use App\Module\FeishuOrgSync\OrgSyncService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

class FeishuOrgSyncApply extends Command
{
    protected $signature = 'org-sync:apply
        {plan : 组织写计划 JSON 的绝对路径}
        {--confirm-digest= : 正式写入时必须提供完整计划摘要}
        {--dry-run : 强制只预览}';

    protected $description = '预览或受控写入飞书组织计划（默认只预览）';

    public function handle(): int
    {
        try {
            $json = $this->readSecurePlan((string) $this->argument('plan'));
            $plan = OrgSyncPlan::fromJson($json, new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $confirm = trim((string) $this->option('confirm-digest'));
            $service = new OrgSyncService();
            if ($this->option('dry-run') || $confirm === '') {
                $this->line('[DRY-RUN] digest=' . $plan->digest());
                $this->line($this->formatCounters($service->preview($plan)));
                return 0;
            }
            if (!hash_equals($plan->digest(), $confirm)) {
                throw new ApiException('组织计划确认摘要不匹配');
            }
            $batch = $service->apply($plan, $confirm);
            $this->line('[APPLIED] batch=' . $batch->id . ' digest=' . $batch->plan_digest);
            $this->line($this->formatCounters($batch->countersArray()));
            $this->line('postDigest=' . hash('sha256', (string) $batch->post_snapshot));
            return 0;
        } catch (ApiException $e) {
            $this->error($e->getMessage());
            return $this->apiExitCode($e->getMessage());
        } catch (\Throwable $e) {
            $this->error('组织同步执行失败');
            return 1;
        }
    }

    private function readSecurePlan(string $path): string
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
            throw new ApiException('组织计划必须使用绝对路径');
        }
        if (is_link($path) || !is_file($path)) {
            throw new ApiException('组织计划必须是普通文件');
        }
        $stat = stat($path);
        if (!$stat || $stat['size'] > 1024 * 1024) {
            throw new ApiException('组织计划文件大小无效');
        }
        if (($stat['mode'] & 0137) !== 0 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new ApiException('组织计划文件权限不安全');
        }
        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            throw new ApiException('组织计划文件读取失败');
        }
        return $json;
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
        foreach (['状态冲突', '状态已变化', '正在执行', '新群已被使用'] as $conflict) {
            if (strpos($message, $conflict) !== false) {
                return 3;
            }
        }
        return 2;
    }
}
