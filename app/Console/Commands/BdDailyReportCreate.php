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
        if (!config('bd_daily_report.create_enabled', true)) {
            $this->info('[BD create] BD_REPORT_CREATE_ENABLED=false，已停用每日任务创建，跳过');
            return 0;
        }

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

        // 2. 查项目（优先按 ID，回退按 name，避免改名导致匹配失败）
        $projectId = (int) config('bd_daily_report.project_id', 0);
        $projectName = (string) config('bd_daily_report.project_name');
        if ($projectId > 0) {
            $project = Project::whereId($projectId)->whereNull('archived_at')->first();
        } else {
            $project = Project::where('name', $projectName)->whereNull('archived_at')->first();
        }
        if (!$project) {
            $hint = $projectId > 0 ? "ID={$projectId}" : "name='{$projectName}'";
            BdDailyReportNotifier::alert("项目 {$hint} 不存在或已归档，请检查配置 BD_REPORT_PROJECT_ID / BD_REPORT_PROJECT");
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

        // 5. 查 BD 根部门（优先按 ID，回退按 name）
        $rootIds = [];
        $configIds = (array) config('bd_daily_report.department_ids', []);
        if (!empty($configIds)) {
            $existing = UserDepartment::whereIn('id', $configIds)->pluck('id')->map(fn($v) => (int) $v)->toArray();
            $missing = array_values(array_diff(array_map('intval', $configIds), $existing));
            if (!empty($missing)) {
                BdDailyReportNotifier::alert(
                    '配置的根部门 ID 不存在: ' . implode(',', $missing) . '（已跳过）',
                    $parentOwner->userid
                );
            }
            $rootIds = $existing;
        }
        if (empty($rootIds)) {
            $deptName = (string) config('bd_daily_report.department_name');
            $dept = UserDepartment::where('name', $deptName)->first();
            if (!$dept) {
                BdDailyReportNotifier::alert(
                    "未配置 BD_REPORT_DEPARTMENT_IDS，且按名字 '{$deptName}' 未找到部门。请设置 BD_REPORT_DEPARTMENT_IDS",
                    $parentOwner->userid
                );
                return 1;
            }
            $rootIds = [(int) $dept->id];
        }

        // 递归收集所有子部门 id（支持多根）
        $deptIds = array_values(array_unique(array_map('intval', $rootIds)));
        $frontier = $deptIds;
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

        // 6. 幂等：用 userid 判重（昵称可能改动，名字判重会产生重复任务）
        //    任务标题格式：`{昵称} {日期} 日报`（独立主任务，parent_id=0）
        $existingOwnerIds = DB::table('project_tasks')
            ->join('project_task_users', function ($j) {
                $j->on('project_task_users.task_id', '=', 'project_tasks.id')
                    ->where('project_task_users.owner', 1);
            })
            ->where('project_tasks.project_id', $project->id)
            ->where('project_tasks.parent_id', 0)
            ->where('project_tasks.name', 'like', "%{$dateStr} 日报")
            ->whereNull('project_tasks.archived_at')
            ->whereNull('project_tasks.deleted_at')
            ->pluck('project_task_users.userid')
            ->map(fn($v) => (int) $v)
            ->toArray();

        $toCreate = [];
        foreach ($bdUsers as $u) {
            if (in_array((int) $u->userid, $existingOwnerIds, true)) {
                continue;
            }
            $toCreate[] = ['user' => $u, 'name' => "{$u->nickname} {$dateStr} 日报"];
        }

        if (empty($toCreate)) {
            $this->info("[{$dateStr}] 全体 BD 今日任务均已存在，跳过");
            Log::info("[BdDailyReport] {$dateStr} all tasks already exist, skipped");
            return 0;
        }

        $subtaskTitles = (array) config('bd_daily_report.subtask_titles', []);

        // 7. dry-run：只打印计划
        if ($this->option('dry-run')) {
            $this->info("[DRY-RUN] 日期：{$dateStr}");
            $this->info("[DRY-RUN] 项目：{$project->name} (id={$project->id})");
            $this->info("[DRY-RUN] 列表：{$column->name} (id={$column->id})");
            $this->info("[DRY-RUN] 创建人：{$parentOwner->nickname} (userid={$parentOwner->userid})");
            $this->info("[DRY-RUN] 待创建主任务数：" . count($toCreate));
            if (!empty($subtaskTitles)) {
                $this->info("[DRY-RUN] 每个主任务挂子任务（" . count($subtaskTitles) . " 条）：");
                foreach ($subtaskTitles as $t) {
                    $this->line("    · {$t}");
                }
            } else {
                $this->info("[DRY-RUN] 不挂子任务（BD_REPORT_SUBTASK_TITLES 未配置）");
            }
            $this->info("[DRY-RUN] BD 名单：");
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
        $startAt = $date->copy()->setTime(8, 45, 0); // 2026-06-05 与早卡发卡时间对齐
        $endAt = $date->copy()->setTime(23, 59, 0);
        $creatorUserid = (int) $parentOwner->userid;

        $created = 0;
        $failedNames = [];
        foreach ($toCreate as $item) {
            try {
                DB::transaction(function () use ($project, $column, $item, $creatorUserid, $startAt, $endAt, $subtaskTitles) {
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
                        'visibility' => 2,
                    ]);
                    $task->save();
                    ProjectTaskUser::createInstance([
                        'project_id' => $project->id,
                        'task_id' => $task->id,
                        'task_pid' => $task->id,
                        'userid' => (int) $item['user']->userid,
                        'owner' => 1,
                    ])->save();

                    // 子任务（DooTask checklist），按模板顺序挂在主任务下
                    $subSort = 1;
                    foreach ($subtaskTitles as $subTitle) {
                        $sub = ProjectTask::createInstance([
                            'parent_id' => $task->id,
                            'project_id' => $project->id,
                            'column_id' => $column->id,
                            'name' => $subTitle,
                            'userid' => $creatorUserid,
                            'start_at' => $startAt,
                            'end_at' => $endAt,
                            'p_level' => 0,
                            'p_name' => '',
                            'p_color' => '',
                            'sort' => $subSort++,
                            'visibility' => 1,
                        ]);
                        $sub->save();
                        ProjectTaskUser::createInstance([
                            'project_id' => $project->id,
                            'task_id' => $sub->id,
                            'task_pid' => $task->id,
                            'userid' => (int) $item['user']->userid,
                            'owner' => 1,
                        ])->save();
                    }
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
