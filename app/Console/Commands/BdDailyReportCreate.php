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
        $bdUsers = User::whereNull('disable_at')
            ->where(function ($q) use ($deptIds) {
                foreach ($deptIds as $did) {
                    $q->orWhere('department', 'like', "%,{$did},%");
                }
            })
            ->orderBy('userid')
            ->get();
        if ($bdUsers->isEmpty()) {
            BdDailyReportNotifier::alert("部门 '{$deptName}' 无启用成员", $parentOwner->userid);
            return 1;
        }

        // 6. 幂等：当日任务已存在则跳过
        $taskTitle = "{$dateStr} BD 日报";
        $exists = ProjectTask::whereProjectId($project->id)
            ->where('name', $taskTitle)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->exists();
        if ($exists) {
            $this->info("[{$dateStr}] 任务已存在，跳过");
            Log::info("[BdDailyReport] {$dateStr} task already exists, skipped");
            return 0;
        }

        // 7. dry-run：只打印计划
        if ($this->option('dry-run')) {
            $this->info("[DRY-RUN] 日期：{$dateStr}");
            $this->info("[DRY-RUN] 项目：{$project->name} (id={$project->id})");
            $this->info("[DRY-RUN] 列表：{$column->name} (id={$column->id})");
            $this->info("[DRY-RUN] 父任务：{$taskTitle}");
            $this->info("[DRY-RUN] 父任务负责人：{$parentOwner->nickname} (userid={$parentOwner->userid})");
            $this->info("[DRY-RUN] 子任务数：" . $bdUsers->count());
            foreach ($bdUsers as $u) {
                $this->line("  - {$u->nickname} 日报 (userid={$u->userid}, email={$u->email})");
            }
            return 0;
        }

        // 8. 确保所有相关用户是项目成员
        $allUserids = $bdUsers->pluck('userid')->push($parentOwner->userid)->unique()->values();
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

        // 9. 创建父任务 + 子任务（事务）
        $startAt = $date->copy()->setTime(9, 0, 0);
        $endAt = $date->copy()->setTime(23, 59, 0);
        $creatorUserid = (int) $parentOwner->userid;

        try {
            DB::transaction(function () use ($project, $column, $parentOwner, $bdUsers, $taskTitle, $startAt, $endAt, $creatorUserid) {
                // 父任务
                $parentSort = (int) ProjectTask::whereColumnId($column->id)->max('sort') + 1;
                $parent = ProjectTask::createInstance([
                    'parent_id' => 0,
                    'project_id' => $project->id,
                    'column_id' => $column->id,
                    'name' => $taskTitle,
                    'userid' => $creatorUserid,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'p_level' => 0,
                    'p_name' => '',
                    'p_color' => '',
                    'sort' => $parentSort,
                    'visibility' => 1,
                ]);
                $parent->save();
                ProjectTaskUser::createInstance([
                    'project_id' => $project->id,
                    'task_id' => $parent->id,
                    'task_pid' => $parent->id,
                    'userid' => $creatorUserid,
                    'owner' => 1,
                ])->save();

                // 子任务
                $subSort = 1;
                foreach ($bdUsers as $u) {
                    $subName = "{$u->nickname} 日报";
                    $sub = ProjectTask::createInstance([
                        'parent_id' => $parent->id,
                        'project_id' => $project->id,
                        'column_id' => $column->id,
                        'name' => $subName,
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
                        'task_pid' => $parent->id,
                        'userid' => (int) $u->userid,
                        'owner' => 1,
                    ])->save();
                }
            });
        } catch (\Throwable $e) {
            BdDailyReportNotifier::alert("[{$dateStr}] 创建任务失败：{$e->getMessage()}", $parentOwner->userid);
            Log::error('[BdDailyReport] create failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return 1;
        }

        $this->info("[{$dateStr}] 创建成功，子任务数：" . $bdUsers->count());
        Log::info("[BdDailyReport] {$dateStr} created, subtasks=" . $bdUsers->count());
        return 0;
    }
}
