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

use App\Models\ProjectTaskUser;
use App\Models\User;
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
     * E1: 工单创建 (type=1 task) 通知.
     */
    public static function ticketCreated($task, User $creator): void
    {
        $title = '🆕 新工单';
        $owners = self::getOwnersDisplay($task);
        $content = sprintf(
            "**标题**：%s\n**提交人**：%s\n**当前处理人**：%s\n**链接**：https://task.uhomes.com/single/%d",
            $task->name ?? '(无标题)',
            $creator->nickname ?: '(未知)',
            $owners ?: '未指派',
            $task->id
        );
        self::send($title, $content);
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
