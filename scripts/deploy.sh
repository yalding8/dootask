#!/usr/bin/env bash
# Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
#
# deploy.sh — DooTask 生产部署脚本（代码部署 + 冒烟 + 自动回滚）
#
# 范围（做什么）：
#   1. 拉取代码到指定 ref
#   2. 打 deploy-rollback-* tag
#   3. 重启 PHP + nginx reload（INCIDENT-0416 必做动作）
#   4. 轮询式冒烟验证（防 Swoole 冷启动误判）
#   5. 任一冒烟失败 → 自动回滚 + 再冒烟
#
# 不做（刻意拆分）：
#   ✗ 数据库 migration —— 单独 scripts/migrate.sh 人工执行
#     理由: migration 回滚路径与代码回滚不对称, 混在一起会放大故障
#   ✗ 前端 build —— 在开发者 Mac 上跑, 产物 commit 进 git
#   ✗ .env 改动 —— 人工维护
#
# 用法：
#   sudo ./scripts/deploy.sh                # 部署到 fork/pro 最新
#   sudo ./scripts/deploy.sh <ref>          # 部署到指定 ref
#   sudo ./scripts/deploy.sh --rollback     # 回滚到最近 deploy-rollback-* tag
#   sudo ./scripts/deploy.sh --dry-run      # 不动手, 只打印会做什么
#
# 设计原则（来源：2026-04-17 圆桌共识 + INCIDENT-2026-04-16）：
#   - 每次部署自动打回滚点（不记 hash）
#   - 重启 upstream 后强制 nginx reload（清 keepalive 连接池）
#   - 冒烟 = API 200 + HTML JS hash + 所有容器 healthy
#   - Swoole 冷启动耐心等（轮询, 上限 90s）
#   - 并发保护（flock）
#   - tag 自动保留最近 30 个, 更老的清理

set -euo pipefail

# ───── 配置区 ─────
DEPLOY_DIR="${DEPLOY_DIR:-/opt/dootask}"
FORK_REMOTE="${FORK_REMOTE:-fork}"
FORK_BRANCH="${FORK_BRANCH:-pro}"
PROD_URL="${PROD_URL:-https://task.critvo.com}"
# 内部探测必须跟 301→HTTPS 跳转 (线上 nginx 强制 https).
# 2026-04-17 RUNBOOK 件 1 实测: http://127.0.0.1/api/system/version 返回 301 而非 200.
# -L 跟跳转, -k 接受自签证书 (127.0.0.1 证书不是给这个主机名签的).
INTERNAL_URL="${INTERNAL_URL:-http://127.0.0.1}"
INTERNAL_CURL_OPTS="${INTERNAL_CURL_OPTS:--sL -k}"
DEPLOY_LOG="${DEPLOY_DIR}/DEPLOY_LOG.md"
LOCK_FILE="${DEPLOY_DIR}/.deploy.lock"
TAG_KEEP=30

SMOKE_TIMEOUT_SECS=90
SMOKE_POLL_INTERVAL=3
SMOKE_CONSECUTIVE_OK=3

DRY_RUN=0
ROLLBACK_MODE=0
TARGET_REF=""

# ───── 参数解析 ─────
for arg in "$@"; do
  case "$arg" in
    --rollback) ROLLBACK_MODE=1 ;;
    --dry-run)  DRY_RUN=1 ;;
    --help|-h)
      sed -n '3,30p' "$0" | sed 's/^# //; s/^#//'
      exit 0 ;;
    -*) echo "未知选项: $arg" >&2; exit 2 ;;
    *)  TARGET_REF="$arg" ;;
  esac
done
TARGET_REF="${TARGET_REF:-${FORK_REMOTE}/${FORK_BRANCH}}"

# ───── 工具函数 ─────
log()  { echo "[deploy $(date '+%Y-%m-%d %H:%M:%S')] $*"; }
die()  { echo "[FATAL] $*" >&2; exit 1; }

# 并发保护 —— 第一个进程拿锁, 后来者立即退出
acquire_lock() {
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    die "已有 deploy.sh 在运行（锁: $LOCK_FILE）。如确认已死，rm 该文件。"
  fi
}

find_nginx_containers() {
  docker ps --format '{{.Names}}' | grep -E '^dootask-nginx-' || true
}

find_php_container() {
  docker ps --format '{{.Names}}' | grep -E '^dootask-php-' | head -1
}

nginx_reload_all() {
  local any=0
  while IFS= read -r c; do
    [ -n "$c" ] || continue
    any=1
    log "  nginx -t ($c)"
    docker exec "$c" nginx -t
    log "  nginx -s reload ($c)"
    docker exec "$c" nginx -s reload
  done < <(find_nginx_containers)
  [ $any -eq 1 ] || log "WARN: 未找到 dootask-nginx-* 容器, 跳过 reload"
}

# 单次冒烟检查（不轮询；返回 0=通过, 非 0=失败）
smoke_check_once() {
  local code

  # 关闭 pipefail, 防止 grep 不匹配炸掉整脚本
  set +o pipefail

  # 1) 内部 API (-L 跟 301→https 跳转, 线上 nginx 配了 http 强制跳 https)
  code=$(curl $INTERNAL_CURL_OPTS -o /dev/null -w '%{http_code}' "${INTERNAL_URL}/api/system/version" 2>/dev/null || echo "000")
  [ "$code" = "200" ] || { set -o pipefail; return 1; }

  # 2) 外部 API（经 SLB/CDN）
  code=$(curl -s -o /dev/null -w '%{http_code}' "${PROD_URL}/api/system/version" 2>/dev/null || echo "000")
  [ "$code" = "200" ] || { set -o pipefail; return 2; }

  # 3) HTML 引用的 JS 文件可取（防 manifest 遗漏）
  local html js_ref
  html=$(curl -s "${PROD_URL}/" 2>/dev/null || echo "")
  js_ref=$(echo "$html" | grep -oE 'js/build/[a-zA-Z0-9._/-]+\.js' | head -1 || echo "")
  if [ -z "$js_ref" ]; then
    set -o pipefail
    return 3
  fi
  code=$(curl -s -o /dev/null -w '%{http_code}' "${PROD_URL}/${js_ref}" 2>/dev/null || echo "000")
  [ "$code" = "200" ] || { set -o pipefail; return 4; }

  # 4) 关键容器无 unhealthy
  local unhealthy
  unhealthy=$(docker ps --filter "name=dootask-" --filter "health=unhealthy" --format '{{.Names}}' || echo "")
  [ -z "$unhealthy" ] || { set -o pipefail; return 5; }

  set -o pipefail
  return 0
}

# 轮询式冒烟 —— 最多 90 秒等 Swoole 冷启动, 连续 3 次 200 才算过
smoke_test() {
  local label="$1"
  log "冒烟测试 ($label)，上限 ${SMOKE_TIMEOUT_SECS}s ..."

  local elapsed=0 ok_count=0 last_fail_code=0
  while [ $elapsed -lt $SMOKE_TIMEOUT_SECS ]; do
    if smoke_check_once; then
      ok_count=$((ok_count + 1))
      log "  ✓ 第 $ok_count 次 OK (elapsed=${elapsed}s)"
      if [ $ok_count -ge $SMOKE_CONSECUTIVE_OK ]; then
        local js_ref
        set +o pipefail
        js_ref=$(curl -s "${PROD_URL}/" | grep -oE 'js/build/[a-zA-Z0-9._/-]+\.js' | head -1 || echo "")
        set -o pipefail
        log "  ✓ 冒烟通过。HTML JS: $js_ref"
        return 0
      fi
    else
      last_fail_code=$?
      ok_count=0
      log "  ✗ 冒烟失败 (code=$last_fail_code, elapsed=${elapsed}s), 继续等..."
    fi
    sleep $SMOKE_POLL_INTERVAL
    elapsed=$((elapsed + SMOKE_POLL_INTERVAL))
  done

  log "  ✗ 冒烟超时 ${SMOKE_TIMEOUT_SECS}s 仍未通过 (last_code=$last_fail_code)"
  log "    code 1=内部API, 2=外部API, 3=HTML无JS, 4=JS 404, 5=容器unhealthy"
  return 1
}

record_deploy() {
  local before="$1" after="$2" target="$3" status="$4"
  local ts
  ts=$(date '+%Y-%m-%d %H:%M:%S')
  local short_before="${before:0:8}" short_after="${after:0:8}"
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
      git log --oneline "$before..$after" 2>/dev/null | head -10 | sed 's/^/  - /' || true
    fi
  } >> "$DEPLOY_LOG"
}

prune_old_tags() {
  # 保留最近 TAG_KEEP 个 deploy-rollback-* tag, 老的删掉
  local old_tags
  old_tags=$(git tag --list 'deploy-rollback-*' --sort=-creatordate | tail -n +$((TAG_KEEP + 1)) || echo "")
  if [ -n "$old_tags" ]; then
    log "清理超过 $TAG_KEEP 个的老 tag..."
    echo "$old_tags" | xargs -r git tag -d >/dev/null || true
  fi
}

rollback_to() {
  local target="$1"
  log "!!! 回滚到 $target"
  git reset --hard "$target"
  ./cmd php restart
  nginx_reload_all
}

# ───── --rollback 模式 ─────
if [ $ROLLBACK_MODE -eq 1 ]; then
  acquire_lock
  cd "$DEPLOY_DIR"
  last_tag=$(git tag --list 'deploy-rollback-*' --sort=-creatordate | head -1)
  [ -n "$last_tag" ] || die "未找到 deploy-rollback-* tag"
  log "回滚目标: $last_tag"
  if [ $DRY_RUN -eq 1 ]; then
    log "[DRY-RUN] 将执行: git reset --hard $last_tag + ./cmd php restart + nginx reload"
    exit 0
  fi
  rollback_to "$last_tag"
  smoke_test "回滚后" || die "回滚后冒烟仍失败，请人工介入"
  log "✓ 回滚完成"
  exit 0
fi

# ───── 主流程 ─────
acquire_lock
cd "$DEPLOY_DIR"

log "=== 1/5 拉取最新代码 ($TARGET_REF) ==="
if [ $DRY_RUN -eq 0 ]; then
  git fetch "$FORK_REMOTE"
fi
BEFORE=$(git rev-parse HEAD)
AFTER=$(git rev-parse "$TARGET_REF" 2>/dev/null) || die "无法解析 ref: $TARGET_REF"
if [ "$BEFORE" = "$AFTER" ]; then
  log "已是最新 ($BEFORE), 无需部署"
  exit 0
fi
log "$BEFORE → $AFTER"

if [ $DRY_RUN -eq 1 ]; then
  echo
  log "[DRY-RUN] 将执行:"
  log "  2. git tag deploy-rollback-$(date +%Y%m%d-%H%M%S)"
  log "  3. git reset --hard $AFTER"
  log "  4. ./cmd php restart + nginx reload"
  log "  5. 冒烟测试 (API 200 + HTML JS hash + 容器 healthy)"
  echo
  log "变更清单:"
  git log --oneline "$BEFORE..$AFTER" | head -20
  echo
  log "⚠️  如包含 migration, 先单独跑 ./scripts/migrate.sh, 再跑本脚本（不带 --dry-run）"
  exit 0
fi

log "=== 2/5 打回滚点 ==="
ROLLBACK_TAG="deploy-rollback-$(date +%Y%m%d-%H%M%S)"
git tag "$ROLLBACK_TAG"
log "已打 tag: $ROLLBACK_TAG"
prune_old_tags

log "=== 3/5 同步代码 ==="
git reset --hard "$AFTER"

# Migration 提醒（不代执行）
if git diff --name-only "$BEFORE" "$AFTER" | grep -qE '^database/migrations/'; then
  echo
  log "⚠️  检测到 migration 变更:"
  git diff --name-only "$BEFORE" "$AFTER" | grep -E '^database/migrations/' | sed 's/^/    /'
  echo
  log "⚠️  本脚本不跑 migration. 请另开终端执行:"
  log "    sudo ./scripts/migrate.sh --dry-run   # 预演"
  log "    sudo ./scripts/migrate.sh             # 执行"
  log "    然后在此按 Enter 继续 (或 Ctrl-C 中止)"
  read -r -p "migration 已完成？(回车继续) "
fi

log "=== 4/5 重启 PHP + nginx reload ==="
./cmd php restart
nginx_reload_all

log "=== 5/5 冒烟验证 ==="
if smoke_test "部署后"; then
  log "✓ 部署完成: $BEFORE → $AFTER"
  record_deploy "$BEFORE" "$AFTER" "$TARGET_REF" "成功"
  log "  回滚命令: sudo $0 --rollback"
  exit 0
else
  log "✗ 冒烟失败, 触发自动回滚..."
  record_deploy "$BEFORE" "$AFTER" "$TARGET_REF" "失败-自动回滚"
  rollback_to "$ROLLBACK_TAG"
  if smoke_test "回滚后"; then
    die "部署失败已回滚到 $ROLLBACK_TAG（冒烟通过）"
  else
    die "部署失败且回滚后仍异常！人工介入。失败 tag: $ROLLBACK_TAG"
  fi
fi
