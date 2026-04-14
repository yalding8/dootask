<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * 所有配置可通过环境变量覆盖，部署时优先用 .env
 */

return [
    // 项目名（DooTask 里必须已存在同名项目）
    'project_name' => env('BD_REPORT_PROJECT', '留学渠道全员任务'),

    // 部门名（DooTask 里 user_departments.name）
    'department_name' => env('BD_REPORT_DEPARTMENT', '留学渠道部'),

    // 父任务负责人邮箱（韦刚），启动时按 email 解析 userid
    'parent_owner_email' => env('BD_REPORT_PARENT_OWNER_EMAIL', 'vigo.wei@uhomes.com'),

    // 节假日 API（timor.tech 免费接口）
    'holiday_api' => env('BD_REPORT_HOLIDAY_API', 'https://timor.tech/api/holiday/info/'),

    // 节假日结果缓存时长（秒），默认 1 天
    'holiday_cache_ttl' => env('BD_REPORT_HOLIDAY_CACHE_TTL', 86400),

    // 时区
    'timezone' => env('BD_REPORT_TIMEZONE', 'Asia/Shanghai'),

    // 告警开关（false 时异常只写日志不推送）
    'alert_enabled' => env('BD_REPORT_ALERT_ENABLED', true),

    // 任务链接基址（用于催交站内/邮件里的链接）
    'task_url_base' => env('BD_REPORT_TASK_URL_BASE', 'https://task.critvo.com'),
];
