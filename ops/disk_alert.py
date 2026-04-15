#!/usr/bin/env python3
"""
磁盘空间告警脚本（uhomes 内部运维工具）。

每次执行时检查根分区使用率，超过阈值通过 SMTP 发送邮件告警。
复用 task-notify 的 SMTP 配置（独立于 DooTask 主服务，避免 DooTask
本身故障时告警通道也失效）。

- 默认阈值 85%
- 同一告警 4 小时内只发一次（防风暴）
- 邮件正文附磁盘 Top 5 大目录 + Docker 占用诊断信息
- 主题含 SERVER_LABEL（默认 hostname）便于多机器区分
- 数字与 df 对齐（排除文件系统 root 保留块）

部署：
  curl 下载到 /opt/task-notify/disk_alert.py
  cron: */30 * * * * root /opt/task-notify/venv/bin/python3 /opt/task-notify/disk_alert.py

环境变量（可选，覆盖默认）:
  SERVER_LABEL  服务器标识（默认 os.uname().nodename）
  THRESHOLD     使用率告警阈值（默认 85，单位 %）
  RECIPIENTS    收件人列表（默认 ning.ding@uhomes.com，多个用 , 分隔）

测试（强制触发）:
  sudo bash -c "THRESHOLD=10 /opt/task-notify/venv/bin/python3 /opt/task-notify/disk_alert.py"
"""
import configparser
import os
import shutil
import smtplib
import subprocess
import sys
import time
from email.mime.text import MIMEText
from email.utils import formataddr

CONFIG = '/opt/task-notify/config.ini'
DEFAULT_THRESHOLD = 85
DEFAULT_RECIPIENTS = ['ning.ding@uhomes.com']
STATE_FILE = '/var/tmp/disk-alert-last-sent'
ALERT_INTERVAL = 4 * 3600  # 同一告警最少间隔（秒）

# 环境变量覆盖
THRESHOLD = int(os.environ.get('THRESHOLD', DEFAULT_THRESHOLD))
RECIPIENTS = [
    x.strip() for x in os.environ.get(
        'RECIPIENTS', ','.join(DEFAULT_RECIPIENTS)
    ).split(',') if x.strip()
]
SERVER_LABEL = os.environ.get('SERVER_LABEL') or os.uname().nodename


def disk_stats():
    """
    返回与 df 一致的磁盘统计：用户可用总量 = used + free
    （shutil 的 total 含 root 保留块，会和 df 对不上）
    """
    usage = shutil.disk_usage('/')
    used_b = usage.used
    free_b = usage.free
    user_total_b = used_b + free_b
    pct = round(used_b * 100 / user_total_b) if user_total_b else 0
    gb = lambda b: b / (1024 ** 3)
    return pct, gb(used_b), gb(free_b), gb(user_total_b)


def already_alerted_recently():
    if not os.path.exists(STATE_FILE):
        return False
    return (time.time() - os.path.getmtime(STATE_FILE)) < ALERT_INTERVAL


def mark_alerted():
    with open(STATE_FILE, 'w') as f:
        f.write(str(int(time.time())))


def shell(cmd, timeout=30):
    try:
        result = subprocess.run(
            cmd, shell=True, capture_output=True, text=True, timeout=timeout,
        )
        return result.stdout.strip() or '(空)'
    except subprocess.TimeoutExpired:
        return f'(超时 {timeout}s — 磁盘可能 I/O 拥堵)'
    except Exception as e:
        return f'(出错: {e})'


def collect_partitions():
    """各分区 df 输出（瞬时，不递归扫描，告警不会因 I/O 卡死）。
    过滤掉 docker overlay / 系统虚拟挂载，只留真实物理分区。"""
    raw = shell(
        'df -h --output=source,size,used,avail,pcent,target '
        '-x tmpfs -x devtmpfs -x overlay -x efivarfs -x squashfs',
        timeout=5,
    )
    return '\n'.join(f'  {line}' for line in raw.splitlines())


def collect_docker():
    raw = shell('docker system df 2>&1', timeout=10)
    # 提取出 Images / Containers / Local Volumes / Build Cache 行
    lines = []
    header_seen = False
    for line in raw.splitlines():
        if line.startswith('TYPE'):
            header_seen = True
            continue
        if not header_seen:
            continue
        # docker system df 输出列：TYPE TOTAL ACTIVE SIZE RECLAIMABLE
        # 用空格切，最后两个字段是 SIZE / RECLAIMABLE
        cols = line.split()
        if len(cols) < 5:
            continue
        # 类型可能是 "Local Volumes" / "Build Cache" 两个词
        if cols[0] == 'Local' and cols[1] == 'Volumes':
            kind = 'Volumes'
            rest = cols[2:]
        elif cols[0] == 'Build' and cols[1] == 'Cache':
            kind = 'Build Cache'
            rest = cols[2:]
        else:
            kind = cols[0]
            rest = cols[1:]
        if len(rest) < 4:
            continue
        size = rest[2]
        reclaim = rest[3] if len(rest) >= 4 else '-'
        # RECLAIMABLE 可能含括号 "(91%)" — 拼回去
        if len(rest) >= 5 and rest[4].startswith('('):
            reclaim = f'{reclaim} {rest[4]}'
        lines.append(f'  {kind:<12}  {size:>10}  {reclaim:>14}')
    if not lines:
        return '  (docker system df 无输出)'
    header = f'  {"类型":<10}  {"占用":>10}  {"可回收":>14}'
    return header + '\n' + '\n'.join(lines)


def build_email(pct, used_gb, free_gb, total_gb):
    sep_eq = '═' * 42
    sep_da = '─' * 42
    body = f"""{sep_eq}
  磁盘告警 · {SERVER_LABEL}
{sep_eq}

  使用率   {pct}%   /   阈值 {THRESHOLD}%
  已用     {used_gb:.1f} GB
  可用     {free_gb:.1f} GB
  总量     {total_gb:.1f} GB

{sep_da}
  各分区
{sep_da}
{collect_partitions()}

{sep_da}
  Docker 占用
{sep_da}
{collect_docker()}

{sep_da}
  收到告警后手动排查（在服务器上跑）
{sep_da}
  # 1. 看 / 下哪个目录吃磁盘
  sudo du -sh /var /opt /home /root /tmp /usr 2>/dev/null | sort -hr

  # 2. Docker 占用细分
  sudo docker images --format 'table {{{{.Repository}}}}\\t{{{{.Tag}}}}\\t{{{{.Size}}}}' | sort -k3 -hr

  # 3. 清理（按需）
  sudo docker builder prune -f
  sudo docker image prune -f
  sudo journalctl --vacuum-size=200M

—— disk_alert.py
"""
    return body


def send_alert(pct, used_gb, free_gb, total_gb):
    cfg = configparser.ConfigParser()
    cfg.read(CONFIG)
    if 'smtp' not in cfg:
        print(f'ERROR: [smtp] section missing in {CONFIG}', file=sys.stderr)
        return False
    s = cfg['smtp']

    body = build_email(pct, used_gb, free_gb, total_gb)
    msg = MIMEText(body, 'plain', 'utf-8')
    msg['From'] = formataddr((s.get('from_name', 'DooTask 监控'), s['account']))
    msg['To'] = ', '.join(RECIPIENTS)
    msg['Subject'] = f'[告警][{SERVER_LABEL}] 磁盘 {pct}%'

    try:
        smtp = smtplib.SMTP(s['server'], int(s['port']), timeout=15)
        smtp.starttls()
        smtp.login(s['account'], s['password'])
        smtp.sendmail(s['account'], RECIPIENTS, msg.as_string())
        smtp.quit()
        mark_alerted()
        print(f'alert sent: {pct}% to {RECIPIENTS}')
        return True
    except Exception as e:
        print(f'send failed: {e}', file=sys.stderr)
        return False


def main():
    pct, used_gb, free_gb, total_gb = disk_stats()
    if pct < THRESHOLD:
        sys.exit(0)
    if already_alerted_recently():
        sys.exit(0)
    ok = send_alert(pct, used_gb, free_gb, total_gb)
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
