<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes business event WeChat Work group bot notification.
 *
 * 详细设计: docs/DESIGN_2026-04-22_M6_业务通知系统.md
 *
 * Phase 1 范围 (2026-04-22):
 *   - E1 工单创建 (task__add 中 type=1)
 *
 * Phase 2/3 待解锁:
 *   - E2 任务派发 / E3 临期提醒
 *
 * 失败语义: send() 失败仅 Log::warning, 不抛异常, 不阻塞业务流程.
 * 双发架构: 站内消息 + task-notify 邮件保持现状, wecom 是新增通道.
 */

namespace App\Module;

use App\Models\Project;
use App\Models\ProjectTaskUser;
use App\Models\User;
use App\Models\UserDepartment;
use Illuminate\Support\Facades\Log;

class WechatBusinessNotifier
{
    /**
     * 推送 markdown 消息到企微群机器人.
     * features.wechat_webhook_enabled=false 或 urls=[] 时立即 return false.
     *
     * @return bool true 表示至少 1 个 URL 推送成功
     */
    public static function send(string $title, string $content): bool
    {
        if (!config('features.wechat_webhook_enabled', false)) {
            return false;
        }
        $urls = config('features.wechat_webhook_urls', []);
        if (empty($urls)) {
            return false;
        }

        $payload = [
            'msgtype' => 'markdown',
            'markdown' => ['content' => "## {$title}\n{$content}"],
        ];
        $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ok = false;
        foreach ($urls as $url) {
            $ok = self::postOne($url, $payload_json) || $ok;
        }
        return $ok;
    }

    /**
     * E1: 任务创建通知.
     * 仅当 creator 属于 features.wechat_webhook_departments (含父部门链) 才推送.
     * 部门白名单为空 = 不限部门.
     *
     * 命名说明: 这个方法叫 taskCreated 而非 ticketCreated, 因为 DooTask 数据库
     * 实际所有 task 都是 type=0, "工单"是 uhomes 前端 UI 的概念而非 DB 字段.
     * 部门白名单 (DEPARTMENTS=1) 当前限定留学渠道部, 后续若刷屏可加 column/project 细分.
     */
    public static function taskCreated($task, User $creator): void
    {
        $allowedDeptIds = array_map('intval', config('features.wechat_webhook_departments', []));
        if (!self::isUserInDepartments($creator, $allowedDeptIds)) {
            return; // creator 不在允许的部门, 静默跳过
        }
        $title = '🆕 新任务';

        $lines = [];
        $lines[] = sprintf("**标题**：%s", $task->name ?? '(无标题)');

        $project_name = self::getProjectName($task);
        if ($project_name) {
            $lines[] = sprintf("**项目**：%s", $project_name);
        }

        $lines[] = sprintf("**提交人**：%s", $creator->nickname ?: '(未知)');

        $owners = self::getOwnersDisplay($task);
        $lines[] = sprintf("**处理人**：%s", $owners ?: '未指派');

        $deadline = self::formatDeadline($task);
        if ($deadline) {
            $lines[] = sprintf("**截止**：%s", $deadline);
        }

        $lines[] = ''; // 空行分段
        // 显式 markdown 链接格式: 企微 PC/手机端都识别 [text](url) 为可点击
        $lines[] = sprintf("[🔗 打开任务 #%d](https://task.uhomes.com/single/task/%d)", $task->id, $task->id);

        self::send($title, implode("\n", $lines));
    }

    /** 拿任务所在项目名, 失败返回空字符串. */
    private static function getProjectName($task): string
    {
        try {
            if (empty($task->project_id)) {
                return '';
            }
            $project = Project::find($task->project_id);
            return $project ? (string)($project->name ?? '') : '';
        } catch (\Throwable $e) {
            Log::warning('[WechatBusinessNotifier] getProjectName failed', ['err' => $e->getMessage()]);
            return '';
        }
    }

    /** 格式化截止时间; 没截止 / 解析失败返回空. */
    private static function formatDeadline($task): string
    {
        try {
            if (empty($task->end_at)) {
                return '';
            }
            $ts = is_string($task->end_at) ? strtotime($task->end_at) : ($task->end_at->timestamp ?? false);
            return $ts ? date('Y-m-d H:i', $ts) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @deprecated 用 taskCreated 替代; 保留是因为 DooTask type=1 在本部署中实际从未使用 */
    public static function ticketCreated($task, User $creator): void
    {
        self::taskCreated($task, $creator);
    }

    /**
     * 检查 user 是否属于 allowedDeptIds (含父部门链).
     * allowedDeptIds 为空数组 = 不限制 (所有用户视为通过).
     * 复用 ProjectController::task__add 中 ticket 部门白名单的同款递归逻辑.
     */
    public static function isUserInDepartments(User $user, array $allowedDeptIds): bool
    {
        if (empty($allowedDeptIds)) {
            return true; // 空白名单 = 不限部门
        }
        $deptRaw = is_array($user->department)
            ? $user->department
            : explode(',', trim($user->getAttributes()['department'] ?? '', ','));
        $userDeptIds = array_filter(array_map('intval', $deptRaw));
        foreach ($userDeptIds as $deptId) {
            $dept = UserDepartment::find($deptId);
            while ($dept) {
                if (in_array($dept->id, $allowedDeptIds)) {
                    return true;
                }
                $dept = $dept->parent_id ? UserDepartment::find($dept->parent_id) : null;
            }
        }
        return false;
    }

    /** 单个 URL POST. 短超时 + 详细错误 log. */
    private static function postOne(string $url, string $payload_json): bool
    {
        $ch = curl_init($url);
        if ($ch === false) {
            Log::warning('[WechatBusinessNotifier] curl_init failed');
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload_json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $url_hash = substr(md5($url), 0, 8);

        if ($http_code !== 200) {
            Log::warning('[WechatBusinessNotifier] HTTP ' . $http_code, [
                'url_hash' => $url_hash,
                'curl_err' => $err,
            ]);
            return false;
        }

        $body = json_decode($resp, true);
        if (!is_array($body) || ($body['errcode'] ?? -1) !== 0) {
            Log::warning('[WechatBusinessNotifier] errcode != 0', [
                'url_hash' => $url_hash,
                'body' => $resp,
            ]);
            return false;
        }
        return true;
    }

    /** 拿 task 当前 owner 列表的昵称, 顿号拼接. 失败返回空字符串. */
    private static function getOwnersDisplay($task): string
    {
        try {
            $owner_userids = ProjectTaskUser::whereTaskId($task->id)
                ->whereOwner(1)
                ->pluck('userid')
                ->toArray();
            if (empty($owner_userids)) {
                return '';
            }
            $nicknames = User::whereIn('userid', $owner_userids)
                ->pluck('nickname')
                ->toArray();
            return implode('、', $nicknames);
        } catch (\Throwable $e) {
            Log::warning('[WechatBusinessNotifier] getOwnersDisplay failed', [
                'err' => $e->getMessage(),
            ]);
            return '';
        }
    }
}
