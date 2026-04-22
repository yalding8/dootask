<?php
// Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
//
// Feature flags — 圆桌共识 2026-04-17
// 红线（DHH）：任何时刻 ≤3 个 flag。超过需重新评估架构。
// 清单见 uhomes-workorder/docs/FEATURE_FLAGS.md
//
// 读取方式：
//   config('features.ticket_enabled')
// 代码中包裹：
//   if (config('features.ticket_enabled')) { /* 新路径 */ }
// 灰度到部分部门：
//   if (in_array($user->department_id, config('features.ticket_departments', []))) { /* 新路径 */ }

return [

    // ─────────────────────────────────────────────────────────
    // FLAG 1: Ticket 工单能力
    // Owner: Neil
    // 预期启用: Week 5 (2026-05-15 前后)
    // 预期清理: 2026-Q4（Ticket 能力稳定进入核心路径后，移除 flag）
    // 关联决策: A3 Ticket 数据模型、M1 首个 Ticket 部门
    // ─────────────────────────────────────────────────────────
    'ticket_enabled' => env('FEATURE_TICKET_ENABLED', false),

    // Ticket 灰度部门白名单（部门 ID，逗号分隔）
    // 示例：FEATURE_TICKET_DEPARTMENTS=12,15,23
    'ticket_departments' => array_filter(
        array_map('trim', explode(',', env('FEATURE_TICKET_DEPARTMENTS', '')))
    ),

    // ─────────────────────────────────────────────────────────
    // FLAG 2: 通知三级分级（@我的 / 我参与的 / 全部）
    // Owner: Neil
    // 预期启用: Week 5-6
    // 预期清理: 2026-Q4（成为默认通知视图后，移除 flag）
    // 关联 PRD: M5 NotificationTiering
    // ─────────────────────────────────────────────────────────
    'notification_tiering_enabled' => env('FEATURE_NOTIFICATION_TIERING_ENABLED', false),

    // ─────────────────────────────────────────────────────────
    // FLAG 3: 企业微信 Webhook 推送
    // Owner: Neil
    // 预期启用: Week 5-6
    // 预期清理: 2026-Q4 或成为正式能力后，移除 flag
    // 关联 PRD: M6 WeChatWebhook
    // ─────────────────────────────────────────────────────────
    'wechat_webhook_enabled' => env('FEATURE_WECHAT_WEBHOOK_ENABLED', false),

    // 企微 Webhook URL（逗号分隔多个，新旧切换时并行发）
    'wechat_webhook_urls' => array_filter(
        array_map('trim', explode(',', env('FEATURE_WECHAT_WEBHOOK_URLS', '')))
    ),

    // 企微通知部门白名单（与 ticket_departments 独立: 控制"哪些部门创建的事件要推 wecom")
    // 空数组 = 不限部门 (所有部门都推); 非空 = 只有创建人属于这些部门 (含父部门链) 才推
    // 2026-04-22 上线: FEATURE_WECHAT_WEBHOOK_DEPARTMENTS=1 (仅留学渠道部)
    'wechat_webhook_departments' => array_filter(
        array_map('intval', explode(',', env('FEATURE_WECHAT_WEBHOOK_DEPARTMENTS', '')))
    ),

];
