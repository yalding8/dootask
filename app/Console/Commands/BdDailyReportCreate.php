<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 */

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Models\UserDepartment;
use App\Module\BdDailyReportNotifier;
use App\Module\HolidayClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 每个工作日 09:00 自动创建 BD 日报父任务和每人一条子任务。
 * 规则、失败处理见 docs/DESIGN_2026-04-14_BD_DAILY_REPORT.md
 */
class BdDailyReportCreate extends Command
{
    protected $signature = 'bd-daily-report:create
        {--dry-run : 仅打印计划，不落库}
        {--date= : 覆盖日期（YYYY-MM-DD），默认为今天}';

    protected $description = '[BD 日报] 创建当日父任务和所有 BD 的子任务';

    public function handle(): int
    {
        $tz = config('bd_daily_report.timezone', 'Asia/Shanghai');
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)
            : Carbon::now($tz);
        $date = $date->startOfDay();
        $dateStr = $date->format('Y-m-d');

        // 1. 节假日判断（含 API 失败降级）
        $offInfo = HolidayClient::isOffDay($date);
        if ($offInfo['fallback_used']) {
            BdDailyReportNotifier::alert("节假日 API 失败，本日 ({$dateStr}) 降级为周末规则判断。原因：{$offInfo['reason']}");
        }
        if ($offInfo['is_off']) {
            $this->info("[{$dateStr}] 非工作日，跳过（{$offInfo['reason']}）");
            return 0;
        }

        // 2. 查项目
        $projectName = (string) config('bd_daily_report.project_name');
        $project = Project::where('name', $projectName)->whereNull('archived_at')->first();
        if (!$project) {
            BdDailyReportNotifier::alert("项目 '{$projectName}' 不存在或已归档，请检查配置 BD_REPORT_PROJECT");
            return 1;
        }

        // 3. 查项目默认列
        $column = ProjectColumn::whereProjectId($project->id)
            ->orderBy('sort')->orderBy('id')->first();
        if (!$column) {
            BdDailyReportNotifier::alert("项目 '{$projectName}' 无列表，无法建任务");
            return 1;
        }

        // 4. 查父任务负责人（韦刚）
        $parentOwnerEmail = trim((string) config('bd_daily_report.parent_owner_email'));
        if ($parentOwnerEmail === '') {
            BdDailyReportNotifier::alert('配置项 BD_REPORT_PARENT_OWNER_EMAIL 为空');
            return 1;
        }
        $parentOwner = User::where('email', $parentOwnerEmail)->whereNull('disable_at')->first();
        if (!$parentOwner) {
            BdDailyReportNotifier::alert("父任务负责人邮箱 {$parentOwnerEmail} 未找到对应启用用户");
            return 1;
        }

        // 5. 查 BD 部门成员（根部门 + 所有递归子部门，全量含管理成员）
        $deptName = (string) config('bd_daily_report.department_name');
        $dept = UserDepartment::where('name', $deptName)->first();
        if (!$dept) {
            BdDailyReportNotifier::alert("部门 '{$deptName}' 不存在，可能已改名", $parentOwner->userid);
            return 1;
        }
        // 递归收集子部门 id
        $deptIds = [(int) $dept->id];
        $frontier = [(int) $dept->id];
        while (!empty($frontier)) {
            $children = UserDepartment::whereIn('parent_id', $frontier)->pluck('id')->toArray();
            $newIds = array_values(array_diff($children, $deptIds));
            if (empty($newIds)) break;
            $deptIds = array_merge($deptIds, $newIds);
            $frontier = $newIds;
        }
        // users.department 字段为 ",id1,id2," 格式，逐个 id 做 LIKE OR
        $excludeIds = array_map('intval', (array) config('bd_daily_report.exclude_userids', []));
        $query = User::whereNull('disable_at')
            ->where(function ($q) use ($deptIds) {
                foreach ($deptIds as $did) {
                    $q->orWhere('department', 'like', "%,{$did},%");
                }
            })
            ->orderBy('userid');
        if (!empty($excludeIds)) {
            $query->whereNotIn('userid', $excludeIds);
        }
        $bdUsers = $query->get();
        if ($bdUsers->isEmpty()) {
            BdDailyReportNotifier::alert("部门 '{$deptName}' 无启用成员", $parentOwner->userid);
            return 1;
        }

        // 6. 幂等：逐人判断当日个人任务是否已存在，收集待创建列表
        //    任务标题格式：`{昵称} {日期} 日报`（独立主任务，parent_id=0）
        $existingNames = ProjectTask::whereProjectId($project->id)
            ->where('parent_id', 0)
            ->where('name', 'like', "%{$dateStr} 日报")
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->pluck('name')
            ->toArray();

        $toCreate = [];
        foreach ($bdUsers as $u) {
            $taskName = "{$u->nickname} {$dateStr} 日报";
            if (in_array($taskName, $existingNames, true)) {
                continue;
            }
            $toCreate[] = ['user' => $u, 'name' => $taskName];
        }

        if (empty($toCreate)) {
            $this->info("[{$dateStr}] 全体 BD 今日任务均已存在，跳过");
            Log::info("[BdDailyReport] {$dateStr} all tasks already exist, skipped");
            return 0;
        }

        // 7. dry-run：只打印计划
        if ($this->option('dry-run')) {
            $this->info("[DRY-RUN] 日期：{$dateStr}");
            $this->info("[DRY-RUN] 项目：{$project->name} (id={$project->id})");
            $this->info("[DRY-RUN] 列表：{$column->name} (id={$column->id})");
            $this->info("[DRY-RUN] 创建人：{$parentOwner->nickname} (userid={$parentOwner->userid})");
            $this->info("[DRY-RUN] 待创建任务数：" . count($toCreate));
            foreach ($toCreate as $item) {
                $this->line("  - {$item['name']} (owner={$item['user']->nickname}, userid={$item['user']->userid})");
            }
            return 0;
        }

        // 8. 确保所有相关用户是项目成员
        $allUserids = collect($toCreate)->map(fn($i) => (int) $i['user']->userid)
            ->push((int) $parentOwner->userid)->unique()->values();
        foreach ($allUserids as $uid) {
            $exists = ProjectUser::whereProjectId($project->id)->whereUserid($uid)->exists();
            if (!$exists) {
                ProjectUser::createInstance([
                    'project_id' => $project->id,
                    'userid' => (int) $uid,
                    'owner' => 0,
                ])->save();
            }
        }

        // 9. 逐人创建独立主任务（失败不中断其他人）
        $startAt = $date->copy()->setTime(9, 0, 0);
        $endAt = $date->copy()->setTime(23, 59, 0);
        $creatorUserid = (int) $parentOwner->userid;

        $created = 0;
        $failedNames = [];
        foreach ($toCreate as $item) {
            try {
                DB::transaction(function () use ($project, $column, $item, $creatorUserid, $startAt, $endAt) {
                    $sort = (int) ProjectTask::whereColumnId($column->id)->max('sort') + 1;
                    $task = ProjectTask::createInstance([
                        'parent_id' => 0,
                        'project_id' => $project->id,
                        'column_id' => $column->id,
                        'name' => $item['name'],
                        'userid' => $creatorUserid,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                        'p_level' => 0,
                        'p_name' => '',
                        'p_color' => '',
                        'sort' => $sort,
                        'visibility' => 1,
                    ]);
                    $task->save();
                    ProjectTaskUser::createInstance([
                        'project_id' => $project->id,
                        'task_id' => $task->id,
                        'task_pid' => $task->id,
                        'userid' => (int) $item['user']->userid,
                        'owner' => 1,
                    ])->save();
                });
                $created++;
            } catch (\Throwable $e) {
                $failedNames[] = $item['name'];
                Log::error('[BdDailyReport] create task failed for ' . $item['name'] . ': ' . $e->getMessage());
            }
        }

        $this->info("[{$dateStr}] 创建 {$created}/" . count($toCreate));
        Log::info("[BdDailyReport] {$dateStr} created {$created}/" . count($toCreate));
        if (!empty($failedNames)) {
            BdDailyReportNotifier::alert(
                "[{$dateStr}] 部分 BD 任务创建失败：" . implode(', ', $failedNames),
                $parentOwner->userid
            );
        }
        return 0;
    }
}
