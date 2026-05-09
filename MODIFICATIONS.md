# Modifications to DooTask

This is a modified version of [DooTask](https://github.com/kuaifan/dootask), licensed under AGPL-3.0.

## Modified by

- **Organization**: dingning.ai
- **Contact**: ceo@dingning.ai
- **Date**: 2026-04-13

## Changes

### `app/Module/Doo.php`

1. **`license()` method**: Modified to return `people = 0` (unlimited users), bypassing the compiled binary's user count restriction for internal deployment.
2. **`userCreate()` method**: Added try-catch fallback — when the compiled `doo.so` binary rejects user creation due to license limits, users are created directly via database insert using the same password hashing mechanism (`Doo::md5s`).

### `docker/nginx/default.conf`

1. **Disabled appstore proxy**: Commented out `/appstore/` location block and appstore config include, as the `dootask/appstore:0.4.0` Docker image was unavailable (TLS handshake timeout during pull).

### `resources/assets/js/pages/manage/components/DialogView/template/file-download.vue` (2026-04-15)

1. **Replaced `<Button :to="msg.url" target="_blank">` with `<Button @click="downloadNow">`**: iView's `Button` component treated the absolute URL as a `vue-router` target, failing silently and routing back to a default page. Switched to `window.open` from a click handler so user-gesture context lets the download proceed without popup blocking.

### `app/Console/Kernel.php` (2026-04-15)

1. **Registered BD daily report schedule**: Added `bd-daily-report:create` (weekdays 09:00) and `bd-daily-report:remind` (weekdays 18:00) cron jobs.

### `config/laravels.php` (2026-04-15)

1. **Enabled `LaravelScheduleJob`** in `timer.jobs` so LaravelS Swoole timer triggers `schedule:run` every minute (required for the new schedule above).

### `config/bd_daily_report.php` (added 2026-04-14)

New configuration file for BD daily report automation. All sensitive defaults (project name, department name, owner email, internal domain) are intentionally left blank — production deployment must populate via `.env`.

### `app/Module/HolidayClient.php` (added 2026-04-14, modified 2026-05-09)

New module: HTTP client wrapper for `timor.tech` Chinese-holiday API with daily cache and weekend fallback.

**2026-05-09**: Added per-date force-workday override. If `storage/app/.bd-force-workday-YYYY-MM-DD` exists, `isOffDay()` short-circuits to `is_off=false` for that exact date. Use case: 调休补班 (e.g. 五一后周六补班) when default policy "调休不汇报" needs a one-day exception. Date-scoped filename prevents leftover-file from accidentally overriding future holidays.

### `app/Module/BdDailyReportNotifier.php` (added 2026-04-14)

New module: unified dialog-message + email + alert sender for BD daily report. Reuses DooTask's bot-user (`User::botGetOrCreate`) and SMTP (`Base::setting('emailSetting')`).

### `app/Console/Commands/BdDailyReportCreate.php` (added 2026-04-14)

New Artisan command. Each weekday morning creates one independent main task per BD in the configured project with title `{nickname} {YYYY-MM-DD} 日报`. Idempotency by owner userid (nickname-change resilient). Recurses into sub-departments.

### `app/Console/Commands/BdDailyReportRemind.php` (added 2026-04-14)

New Artisan command. Each weekday 18:00 scans incomplete daily-report tasks and pushes in-app message + email to the assigned BD.

### `ops/disk_alert.py` (added 2026-04-15)

New ops script (MIT license, not derivative of DooTask). Disk-usage monitoring: when `/` exceeds threshold (default 85%), emails the operator via the host's `task-notify` SMTP config. Independent of DooTask runtime so an unhealthy DooTask cannot silence its own monitoring.

### `README_CN.md` (2026-04-14, 2026-04-15)

1. Documented the BD daily report automation feature in the Fork modification section.
2. Documented env vars required for deployment.

### `app/Console/Commands/MetricsDauReport.php` (added prior, modified 2026-05-01)

New Artisan command `metrics:dau-report [--snapshot]` for M2 KR1 measurement. Reads `users.line_at` to compute DAU/WAU/MAU; `--snapshot` appends to `storage/app/metrics/wau-snapshots.jsonl` and judges KR1 against the last 4 weekly snapshots.

**2026-05-01**: Changed KR1 threshold from absolute `WAU ≥ 800` to ratio-based `WAU/total ≥ 70%`. The 800 figure was a remote 1000-user-scale target from PRD §M2; at current 77-user company size it can never be reached, so daily cron output was always "❌ 未达标 (达成率 7.6%)" — a false-negative signal. Ratio mode reflects real product health (B2B internal collab tools industry baseline 60-70%).

## Original Project

- **Repository**: https://github.com/kuaifan/dootask
- **License**: AGPL-3.0
- **Original Author**: kuaifan
