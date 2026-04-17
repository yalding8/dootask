#!/usr/bin/env bash
# Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
#
# migrate.sh — 数据库迁移独立执行脚本
#
# 为什么独立于 deploy.sh:
#   1. migration 失败 ≠ 代码失败, 合并在一起会让 "自动回滚" 变成陷阱
#   2. 代码可 git reset, DDL 不可撤销（DROP COLUMN 不能逆转）
#   3. 人工确认是 migration 安全上线的必要步骤
#
# 用法:
#   sudo ./scripts/migrate.sh --dry-run     # 看要跑什么, 不执行
#   sudo ./scripts/migrate.sh --status      # 看未跑的 migration 列表
#   sudo ./scripts/migrate.sh               # 执行 (有确认)
#   sudo ./scripts/migrate.sh --rollback N  # 回滚最后 N 批
#
# 对照流程（全局 CLAUDE.md "事前预防" 章节）:
#   上线前必须跑:
#     SELECT VERSION();                     -- 确认 MariaDB 版本
#     SELECT COUNT(*) FROM <target_table>;  -- 确认行数
#     SHOW CREATE TABLE <target_table>;     -- 看现有索引/外键

set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/opt/dootask}"

log() { echo "[migrate $(date '+%H:%M:%S')] $*"; }
die() { echo "[FATAL] $*" >&2; exit 1; }

cd "$DEPLOY_DIR"

case "${1:-}" in
  --dry-run)
    log "── Pending migrations ──"
    ./cmd php artisan migrate:status | grep -E '^\|.*Pending|No ' || true
    log "── --pretend (打印 SQL, 不执行) ──"
    ./cmd php artisan migrate --pretend
    ;;

  --status)
    ./cmd php artisan migrate:status
    ;;

  --rollback)
    N="${2:-1}"
    log "回滚最后 $N 批 migration"
    read -r -p "确定？(yes/no) " ans
    [ "$ans" = "yes" ] || die "取消"
    ./cmd php artisan migrate:rollback --step="$N" --force
    ;;

  "")
    log "── 当前状态 ──"
    ./cmd php artisan migrate:status | tail -20 || true
    echo
    log "── --pretend 预演 ──"
    ./cmd php artisan migrate --pretend
    echo
    log "确认以上 SQL 符合预期（特别留意 DROP / ALTER / RENAME）"
    read -r -p "执行？(yes/no) " ans
    [ "$ans" = "yes" ] || die "取消"
    ./cmd php artisan migrate --force
    log "✓ migration 完成"
    ;;

  *)
    sed -n '3,20p' "$0" | sed 's/^# //; s/^#//'
    exit 2
    ;;
esac
