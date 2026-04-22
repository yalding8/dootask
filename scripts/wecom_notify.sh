#!/usr/bin/env bash
# Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
#
# wecom_notify.sh — 推送企业微信群机器人通知
#
# 用法:
#   ./wecom_notify.sh "标题" "Markdown 内容"
#
# 环境变量:
#   WECOM_BOT_WEBHOOK_URL   企微群机器人 webhook URL (必需; 未配置则静默退出, 不阻塞调用方)
#                           获取: 企微群 -> 群机器人 -> 添加机器人 -> 自定义机器人 -> 复制 URL
#
# 退出码:
#   始终 exit 0 — 通知失败不阻塞 deploy.sh 等关键流程
#   错误信息打到 stderr, 由调用方决定是否记录
#
# 来源:
#   - 2026-04-22 用户决定 deploy 事件通知用企微群机器人 (而非 features.php 里的 PRD M6 占位 flag)
#   - 全局 CLAUDE.md 敏感凭证安全规则: webhook URL 不入代码, 仅环境变量

set -uo pipefail

WEBHOOK_URL="${WECOM_BOT_WEBHOOK_URL:-}"
TITLE="${1:-(no title)}"
CONTENT="${2:-}"

if [ -z "$WEBHOOK_URL" ]; then
  echo "[wecom_notify] WECOM_BOT_WEBHOOK_URL 未配置, 跳过推送" >&2
  exit 0
fi

# 企微 markdown 消息. 内容统一前置 ## 标题, 调用方传入的 CONTENT 走正文.
# 不做 JSON 字符串 escape — 调用方约定不传双引号/反斜杠/换行符外的特殊字符.
# 实测: 换行用 \n 字面量在 markdown 里渲染为换行.
payload=$(cat <<EOF
{
  "msgtype": "markdown",
  "markdown": {
    "content": "## ${TITLE}\n${CONTENT}"
  }
}
EOF
)

resp_file=$(mktemp /tmp/wecom_resp.XXXXXX)
trap 'rm -f "$resp_file"' EXIT

http_code=$(curl -s -o "$resp_file" -w '%{http_code}' \
  --max-time 10 \
  -X POST \
  -H 'Content-Type: application/json' \
  -d "$payload" \
  "$WEBHOOK_URL" 2>/dev/null || echo "000")

if [ "$http_code" != "200" ]; then
  echo "[wecom_notify] HTTP $http_code: $(cat "$resp_file" 2>/dev/null | head -c 200)" >&2
  exit 0
fi

# 企微 API 协议: HTTP 200 但 errcode != 0 也是失败 (例如 webhook URL 无效/限频)
if grep -q '"errcode":0' "$resp_file" 2>/dev/null; then
  exit 0
fi
echo "[wecom_notify] errcode 非 0: $(cat "$resp_file" 2>/dev/null | head -c 200)" >&2
exit 0
