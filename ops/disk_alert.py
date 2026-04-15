#!/usr/bin/env python3
"""
磁盘空间告警脚本（uhomes 内部运维工具）。

每次执行时检查根分区使用率，超过阈值通过 SMTP 发送邮件告警。
复用 task-notify 的 SMTP 配置（独立于 DooTask 主服务，避免 DooTask
本身故障时告警通道也失效）。

- 默认阈值 85%
- 同一告警 4 小时内只发一次（防风暴）
- 邮件正文附磁盘 Top 5 大目录 + Docker 占用诊断信息
- 主题含 hostname 便于多机器区分
- 支持 THRESHOLD / RECIPIENTS 环境变量覆盖默认值（用于测试）

部署：
  scp 到 /opt/task-notify/disk_alert.py
  cron: */30 * * * * root /opt/task-notify/venv/bin/python3 /opt/task-notify/disk_alert.py

测试（强制触发）:
  THRESHOLD=50 sudo /opt/task-notify/venv/bin/python3 /opt/task-notify/disk_alert.py
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
ALERT_INTERVAL = 4 * 3600  # seconds

# 允许通过环境变量覆盖（测试用）
THRESHOLD = int(os.environ.get('THRESHOLD', DEFAULT_THRESHOLD))
RECIPIENTS = [
    x.strip() for x in os.environ.get(
        'RECIPIENTS', ','.join(DEFAULT_RECIPIENTS)
    ).split(',') if x.strip()
]


def disk_stats():
    total, used, free = shutil.disk_usage('/')
    pct = used * 100 // total
    return pct, used // (1024 ** 3), free // (1024 ** 3), total // (1024 ** 3)


def already_alerted_recently():
    if not os.path.exists(STATE_FILE):
        return False
    return (time.time() - os.path.getmtime(STATE_FILE)) < ALERT_INTERVAL


def mark_alerted():
    with open(STATE_FILE, 'w') as f:
        f.write(str(int(time.time())))


def shell(cmd, timeout=30):
    """运行 shell 命令，返回 stdout（失败返回错误说明）。"""
    try:
        result = subprocess.run(
            cmd, shell=True, capture_output=True, text=True, timeout=timeout,
        )
        return result.stdout.strip() or '(empty)'
    except subprocess.TimeoutExpired:
        return f'(timeout after {timeout}s — disk possibly thrashing)'
    except Exception as e:
        return f'(error: {e})'


def collect_diagnostics():
    """收集磁盘 Top 5 目录 + Docker 占用，给运维直接看到清哪里。"""
    sections = []

    sections.append('【最大目录 Top 5】(du -sh /<dir>，仅一级)')
    top_dirs = shell(
        "for d in /var /opt /home /root /tmp /usr; do "
        "du -sh \"$d\" 2>/dev/null; done | sort -hr | head -5",
        timeout=90,
    )
    sections.append(top_dirs)

    sections.append('')
    sections.append('【Docker 占用】(docker system df)')
    docker_df = shell('docker system df 2>&1', timeout=10)
    sections.append(docker_df)

    return '\n'.join(sections)


def build_email(pct, used_gb, free_gb, total_gb, hostname):
    body = f"""服务器 {hostname} 磁盘告警

使用率: {pct}%
已用:   {used_gb} GB
剩余:   {free_gb} GB
总量:   {total_gb} GB
阈值:   {THRESHOLD}%

------------------------------------------------------------
{collect_diagnostics()}
------------------------------------------------------------

常见清理：
  sudo docker builder prune -f
  sudo docker image prune -f
  sudo journalctl --vacuum-size=200M

-- disk_alert.py
"""
    return body


def send_alert(pct, used_gb, free_gb, total_gb):
    hostname = os.uname().nodename
    cfg = configparser.ConfigParser()
    cfg.read(CONFIG)
    if 'smtp' not in cfg:
        print(f'ERROR: [smtp] section missing in {CONFIG}', file=sys.stderr)
        return False
    s = cfg['smtp']

    body = build_email(pct, used_gb, free_gb, total_gb, hostname)
    msg = MIMEText(body, 'plain', 'utf-8')
    msg['From'] = formataddr((s.get('from_name', 'DooTask 监控'), s['account']))
    msg['To'] = ', '.join(RECIPIENTS)
    msg['Subject'] = f'[告警][{hostname}] 服务器磁盘 {pct}%'

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
        # 静默退出，cron 不会产生输出
        sys.exit(0)
    if already_alerted_recently():
        # 防风暴：4 小时内已经告警过，跳过
        sys.exit(0)
    ok = send_alert(pct, used_gb, free_gb, total_gb)
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
