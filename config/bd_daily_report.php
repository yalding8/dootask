<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 *
 * 所有配置可通过环境变量覆盖，部署时优先用 .env
 */

/*
 * 部署必填项（生产环境必须在 .env 里设置以下）：
 *   BD_REPORT_PROJECT=...                # 项目名
 *   BD_REPORT_DEPARTMENT_IDS=1[,2,...]   # 根部门 id（推荐）
 *   BD_REPORT_PARENT_OWNER_EMAIL=...     # 父任务负责人邮箱
 *   BD_REPORT_TASK_URL_BASE=https://...  # 任务链接基址
 *
 * 不在代码里硬编码内部业务名/邮箱/域名，避免公开 fork 暴露内部信息。
 */
return [
    // 项目名（DooTask 里必须已存在同名项目）
    'project_name' => env('BD_REPORT_PROJECT', ''),

    // 根部门 ID 列表（推荐用 ID 定位，因为部门名可能被改）
    // 多个用逗号分隔，命令会递归包含这些部门的所有子部门成员
    'department_ids' => array_values(array_filter(array_map(
        fn($v) => (int) trim($v),
        explode(',', (string) env('BD_REPORT_DEPARTMENT_IDS', ''))
    ))),

    // 根部门名（仅在 department_ids 为空时作为 fallback）
    'department_name' => env('BD_REPORT_DEPARTMENT', ''),

    // 排除不参与日报的 userid 列表（逗号分隔）
    // 例：BD_REPORT_EXCLUDE_USERIDS=1,5,12
    'exclude_userids' => array_values(array_filter(array_map(
        fn($v) => (int) trim($v),
        explode(',', (string) env('BD_REPORT_EXCLUDE_USERIDS', ''))
    ))),

    // 父任务负责人邮箱，启动时按 email 解析 userid
    'parent_owner_email' => env('BD_REPORT_PARENT_OWNER_EMAIL', ''),

    // 节假日 API（timor.tech 免费接口，公开 URL）
    'holiday_api' => env('BD_REPORT_HOLIDAY_API', 'https://timor.tech/api/holiday/info/'),

    // 节假日结果缓存时长（秒），默认 1 天
    'holiday_cache_ttl' => env('BD_REPORT_HOLIDAY_CACHE_TTL', 86400),

    // 时区
    'timezone' => env('BD_REPORT_TIMEZONE', 'Asia/Shanghai'),

    // 告警开关（false 时异常只写日志不推送）
    'alert_enabled' => env('BD_REPORT_ALERT_ENABLED', true),

    // 任务链接基址（用于催交站内/邮件里的链接）
    'task_url_base' => env('BD_REPORT_TASK_URL_BASE', ''),

    // 每个主任务下要挂的子任务清单（DooTask checklist 复选项），逗号分隔
    // 例：BD_REPORT_SUBTASK_TITLES=租赁商机,新增合作方,新增租赁成单,...
    // 留空则不建子任务（仅一条独立主任务）
    // 改动模板不追溯——已建任务的子任务不会跟着变
    'subtask_titles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BD_REPORT_SUBTASK_TITLES', ''))
    ))),
];
