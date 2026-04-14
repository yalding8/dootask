<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 */

namespace App\Module;

use App\Models\Setting;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use Guanguans\Notify\Factory;
use Guanguans\Notify\Messages\EmailMessage;
use Illuminate\Support\Facades\Log;

class BdDailyReportNotifier
{
    private const BOT_KEY = 'bd-daily-report';

    /** 发送站内消息给单个用户（通过 BD 日报助手机器人私聊） */
    public static function sendDialogMsg(int $userid, string $title, string $content): bool
    {
        if ($userid <= 0) return false;
        try {
            $bot = User::botGetOrCreate(self::BOT_KEY, [
                'nickname' => 'BD日报助手',
                'userimg' => '',
            ]);
            if (!$bot) {
                Log::warning('[BdDailyReport] bot create failed');
                return false;
            }
            $dialog = WebSocketDialog::checkUserDialog($bot, $userid);
            if (!$dialog) {
                Log::warning('[BdDailyReport] checkUserDialog returned null for uid=' . $userid);
                return false;
            }
            WebSocketDialogMsg::sendMsg(null, $dialog->id, 'template', [
                'type' => 'content',
                'title' => $title,
                'content' => [[
                    'content' => $content,
                    'style' => '',
                ]],
            ], $bot->userid, true, true);
            return true;
        } catch (\Throwable $e) {
            Log::error('[BdDailyReport] sendDialogMsg failed uid=' . $userid . ' err=' . $e->getMessage());
            return false;
        }
    }

    /** 发邮件给单个用户（复用 DooTask 系统邮件配置） */
    public static function sendEmail(User $user, string $subject, string $html): bool
    {
        if (empty($user->email)) {
            return false;
        }
        $setting = Base::setting('emailSetting');
        if (empty($setting['smtp_server']) || empty($setting['account'])) {
            Log::warning('[BdDailyReport] email setting incomplete, skip');
            return false;
        }
        try {
            Setting::validateAddr($user->email, function ($to) use ($setting, $subject, $html) {
                Factory::mailer()
                    ->setDsn(sprintf(
                        'smtp://%s:%s@%s:%s?verify_peer=0',
                        $setting['account'],
                        $setting['password'],
                        $setting['smtp_server'],
                        $setting['port']
                    ))
                    ->setMessage(EmailMessage::create()
                        ->from(sprintf(
                            '%s <%s>',
                            Base::settingFind('system', 'system_alias', 'Task'),
                            $setting['account']
                        ))
                        ->to($to)
                        ->subject($subject)
                        ->html($html))
                    ->send();
            });
            return true;
        } catch (\Throwable $e) {
            Log::error('[BdDailyReport] sendEmail failed uid=' . $user->userid . ' err=' . $e->getMessage());
            return false;
        }
    }

    /**
     * 告警：推送到父任务负责人（韦刚）+ 所有系统管理员。
     * 仅走站内消息，避免告警邮件对管理员邮箱形成风暴。
     */
    public static function alert(string $reason, ?int $parentOwnerUserid = null): void
    {
        Log::error('[BdDailyReport] ALERT: ' . $reason);

        if (!config('bd_daily_report.alert_enabled', true)) {
            return;
        }

        $targets = [];
        if ($parentOwnerUserid && $parentOwnerUserid > 0) {
            $targets[] = $parentOwnerUserid;
        }

        // identity 字段历史上可能是 JSON 或逗号分隔字符串，兼容两种
        $admins = User::where(function ($q) {
                $q->whereRaw("JSON_CONTAINS(identity, '\"admin\"')")
                  ->orWhere('identity', 'like', '%admin%');
            })
            ->whereNull('disable_at')
            ->pluck('userid')
            ->toArray();

        $targets = array_values(array_unique(array_merge($targets, $admins)));

        foreach ($targets as $uid) {
            self::sendDialogMsg((int) $uid, '[BD日报] 自动化异常', $reason);
        }
    }
}
