#!/usr/bin/env bash
# Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
#
# deploy.sh — DooTask 生产部署脚本（含自动回滚 + 冒烟验证）
#
# 用法：
#   sudo ./scripts/deploy.sh                # 部署到 fork/pro 最新
#   sudo ./scripts/deploy.sh <ref>          # 部署到指定 ref (commit/tag/branch)
#   sudo ./scripts/deploy.sh --rollback     # 回滚到最近一次 deploy-rollback-* tag
#
# 设计目标（来源：docs/DESIGN_2026-04-17_DEPLOYMENT_PIPELINE.md 及圆桌共识）：
#   1. 机制 > 意图（Bezos）— 部署是一键原子操作，不是一串命令
#   2. 每次部署前自动打回滚点（不用记 commit hash）
#   3. API 200 + HTML hash 双冒烟，任一失败自动回滚
#   4. 重启 upstream 容器后强制 nginx reload（清 keepalive 连接池，INCIDENT-2026-04-16 教训）
#   5. 记录每次部署到 DEPLOY_LOG.md（可追溯）

set -euo pipefail

# ───── 配置区 ─────
DEPLOY_DIR="${DEPLOY_DIR:-/opt/dootask}"
FORK_REMOTE="${FORK_REMOTE:-fork}"
FORK_BRANCH="${FORK_BRANCH:-pro}"
PROD_URL="${PROD_URL:-https://task.critvo.com}"
INTERNAL_URL="${INTERNAL_URL:-http://127.0.0.1}"
DEPLOY_LOG="${DEPLOY_DIR}/DEPLOY_LOG.md"
TARGET_REF="${1:-${FORK_REMOTE}/${FORK_BRANCH}}"

# ───── 工具函数 ─────
log()  { echo "[deploy $(date '+%Y-%m-%d %H:%M:%S')] $*"; }
die()  { echo "[FATAL] $*" >&2; exit 1; }

find_nginx_container() {
  docker ps --format '{{.Names}}' | grep -E '^dootask-nginx-' | head -1
}

find_php_container() {
  docker ps --format '{{.Names}}' | grep -E '^dootask-php-' | head -1
}

smoke_test() {
  local label="$1"
  log "冒烟测试 ($label)..."

  # 1) 内部 API 健康
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' "${INTERNAL_URL}/api/system/version" || echo "000")
  if [ "$code" != "200" ]; then
    log "  ✗ 内部 API /api/system/version 返回 $code（期望 200）"
    return 1
  fi
  log "  ✓ 内部 API 200"

  # 2) 外部 API（经 SLB/CDN）
  code=$(curl -s -o /dev/null -w '%{http_code}' "${PROD_URL}/api/system/version" || echo "000")
  if [ "$code" != "200" ]; then
    log "  ✗ 外部 API $PROD_URL 返回 $code"
    return 1
  fi
  log "  ✓ 外部 API 200"

  # 3) HTML 含有效 JS hash（防 manifest 遗漏 — INCIDENT-2026-04-16 教训）
  local js_ref
  js_ref=$(curl -s "${PROD_URL}/" | grep -oE 'js/build/[a-zA-Z0-9._-]+\.js' | head -1 || echo "")
  if [ -z "$js_ref" ]; then
    log "  ✗ HTML 里未找到 js/build/*.js 引用"
    return 1
  fi
  # 验证引用的 JS 文件真的能取到（非 404）
  code=$(curl -s -o /dev/null -w '%{http_code}' "${PROD_URL}/${js_ref}" || echo "000")
  if [ "$code" != "200" ]; then
    log "  ✗ JS 引用 $js_ref 返回 $code（可能 manifest 漏 commit）"
    return 1
  fi
  log "  ✓ HTML 引用的 JS 可取：$js_ref"

  # 4) 关键容器健康
  local unhealthy
  unhealthy=$(docker ps --filter "name=dootask-" --filter "health=unhealthy" --format '{{.Names}}' || echo "")
  if [ -n "$unhealthy" ]; then
    log "  ✗ 有 unhealthy 容器: $unhealthy"
    return 1
  fi
  log "  ✓ 所有 dootask-* 容器 healthy（或无健康检查）"

  return 0
}

record_deploy() {
  local before="$1" after="$2" target="$3" status="$4"
  local ts
  ts=$(date '+%Y-%m-%d %H:%M:%S')
  local short_before="${before:0:8}"
  local short_after="${after:0:8}"
  {
    [ -f "$DEPLOY_LOG" ] || echo "# DEPLOY LOG"
    echo ""
    echo "## $ts"
    echo "- 目标: \`$target\`"
    echo "- $short_before → $short_after"
    echo "- 状态: $status"
    if [ "$before" != "$after" ]; then
      echo ""
      echo "变更:"
      git log --oneline "$before..$after" 2>/dev/null | head -10 | sed 's/^/  - /'
    fi
  } >> "$DEPLOY_LOG"
}

rollback_to() {
  local target="$1"
  log "!!! 回滚到 $target"
  git reset --hard "$target"
  sudo ./cmd php restart
  local nginx
  nginx=$(find_nginx_container)
  if [ -n "$nginx" ]; then
    log "nginx reload ($nginx)"
    docker exec "$nginx" nginx -s reload || true
  fi
  sleep 5
}

# ───── --rollback 模式 ─────
if [ "${1:-}" = "--rollback" ]; then
  cd "$DEPLOY_DIR"
  last_tag=$(git tag --list 'deploy-rollback-*' --sort=-creatordate | head -1)
  [ -n "$last_tag" ] || die "未找到 deploy-rollback-* tag"
  log "回滚目标: $last_tag"
  rollback_to "$last_tag"
  smoke_test "回滚后" || die "回滚后冒烟仍失败，请人工介入"
  log "回滚完成"
  exit 0
fi

# ───── 主流程 ─────
cd "$DEPLOY_DIR"

log "=== 1/6 拉取最新代码 ($TARGET_REF) ==="
git fetch "$FORK_REMOTE"
BEFORE=$(git rev-parse HEAD)
AFTER=$(git rev-parse "$TARGET_REF")
if [ "$BEFORE" = "$AFTER" ]; then
  log "已是最新 ($BEFORE)，无需部署"
  exit 0
fi
log "$BEFORE → $AFTER"

log "=== 2/6 打回滚点 ==="
ROLLBACK_TAG="deploy-rollback-$(date +%Y%m%d-%H%M%S)"
git tag "$ROLLBACK_TAG"
log "已打 tag: $ROLLBACK_TAG"

log "=== 3/6 同步代码 ==="
git reset --hard "$AFTER"

log "=== 4/6 数据库迁移 ==="
./cmd php artisan migrate --force

log "=== 5/6 重启 PHP + nginx reload ==="
./cmd php restart

NGINX=$(find_nginx_container)
if [ -n "$NGINX" ]; then
  log "nginx config 语法检查"
  docker exec "$NGINX" nginx -t
  log "nginx reload ($NGINX) — 清 keepalive 连接池"
  docker exec "$NGINX" nginx -s reload
else
  log "WARN: 未找到 dootask-nginx-* 容器，跳过 reload"
fi
sleep 5

log "=== 6/6 冒烟验证 ==="
if smoke_test "部署后"; then
  log "✓ 部署完成：$BEFORE → $AFTER"
  record_deploy "$BEFORE" "$AFTER" "$TARGET_REF" "成功"
  log "回滚命令: sudo $0 --rollback"
  exit 0
else
  log "✗ 冒烟测试失败，触发自动回滚"
  record_deploy "$BEFORE" "$AFTER" "$TARGET_REF" "失败-自动回滚"
  rollback_to "$ROLLBACK_TAG"
  if smoke_test "回滚后"; then
    die "部署失败已回滚到 $ROLLBACK_TAG"
  else
    die "部署失败且回滚后仍异常！请人工介入。失败 tag: $ROLLBACK_TAG"
  fi
fi
