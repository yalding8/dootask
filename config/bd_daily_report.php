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

    // 根部门 ID 列表（推荐用 ID 定位，因为部门名可能被改）
    // 多个用逗号分隔，命令会递归包含这些部门的所有子部门成员
    // 例：BD_REPORT_DEPARTMENT_IDS=1   或   BD_REPORT_DEPARTMENT_IDS=1,2
    'department_ids' => array_values(array_filter(array_map(
        fn($v) => (int) trim($v),
        explode(',', (string) env('BD_REPORT_DEPARTMENT_IDS', ''))
    ))),

    // 根部门名（兼容老配置，仅在 department_ids 为空时使用）
    'department_name' => env('BD_REPORT_DEPARTMENT', '异乡好居留学渠道与金融推广部'),

    // 排除不参与日报的 userid 列表（逗号分隔）。
    // 默认排除 userid=1（丁宁，非 BD 岗）。如需更多排除，在 .env 里追加，如：
    //   BD_REPORT_EXCLUDE_USERIDS=1,5,12
    'exclude_userids' => array_values(array_filter(array_map(
        fn($v) => (int) trim($v),
        explode(',', (string) env('BD_REPORT_EXCLUDE_USERIDS', '1'))
    ))),

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
