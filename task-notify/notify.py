#!/opt/task-notify/venv/bin/python3
"""
DooTask 任务邮件通知系统
独立外挂脚本，只读 DooTask 数据库，在任务关键节点自动发送结构化邮件。
"""

import configparser
import sqlite3
import smtplib
import logging
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from datetime import datetime, timedelta
from pathlib import Path
from string import Template

import pymysql

BASE_DIR = Path(__file__).parent
LOG_FILE = BASE_DIR / "notify.log"
DB_FILE = BASE_DIR / "task_email_logs.db"
TEMPLATE_DIR = BASE_DIR / "templates"
CONFIG_FILE = BASE_DIR / "config.ini"

logging.basicConfig(
    filename=str(LOG_FILE),
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("task-notify")


# ---------------------------------------------------------------------------
# Config & connections
# ---------------------------------------------------------------------------

def load_config():
    config = configparser.ConfigParser()
    config.read(str(CONFIG_FILE), encoding="utf-8")
    return config


def get_mysql(config):
    return pymysql.connect(
        host=config.get("database", "host"),
        port=config.getint("database", "port"),
        user=config.get("database", "user"),
        password=config.get("database", "password"),
        database=config.get("database", "database"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def init_sqlite():
    conn = sqlite3.connect(str(DB_FILE))
    conn.execute("""
        CREATE TABLE IF NOT EXISTS task_email_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            notify_type TEXT NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(task_id, user_id, notify_type)
        )
    """)
    conn.commit()
    return conn


# ---------------------------------------------------------------------------
# SQLite helpers
# ---------------------------------------------------------------------------

def already_sent(sq, task_id, user_id, notify_type):
    cur = sq.execute(
        "SELECT 1 FROM task_email_logs WHERE task_id=? AND user_id=? AND notify_type=?",
        (task_id, user_id, notify_type),
    )
    return cur.fetchone() is not None


def mark_sent(sq, task_id, user_id, notify_type):
    try:
        sq.execute(
            "INSERT INTO task_email_logs (task_id,user_id,notify_type) VALUES (?,?,?)",
            (task_id, user_id, notify_type),
        )
        sq.commit()
    except sqlite3.IntegrityError:
        pass


# ---------------------------------------------------------------------------
# Template rendering
# ---------------------------------------------------------------------------

def load_template(name):
    path = TEMPLATE_DIR / f"{name}.html"
    if not path.exists():
        log.error(f"Template not found: {path}")
        return None
    return Template(path.read_text(encoding="utf-8"))


# ---------------------------------------------------------------------------
# Email sending
# ---------------------------------------------------------------------------

def send_email(config, to_email, subject, html_body):
    server = config.get("smtp", "server")
    port = config.getint("smtp", "port")
    account = config.get("smtp", "account")
    password = config.get("smtp", "password")
    from_name = config.get("smtp", "from_name", fallback="DooTask工单系统")

    msg = MIMEMultipart("alternative")
    msg["Subject"] = subject
    msg["From"] = f"{from_name} <{account}>"
    msg["To"] = to_email
    msg.attach(MIMEText(html_body, "html", "utf-8"))

    with smtplib.SMTP(server, port, timeout=30) as s:
        s.starttls()
        s.login(account, password)
        s.sendmail(account, to_email, msg.as_string())


# ---------------------------------------------------------------------------
# Time helpers
# ---------------------------------------------------------------------------

def fmt_remaining(end_at):
    now = datetime.now()
    if end_at < now:
        secs = (now - end_at).total_seconds()
        h = int(secs / 3600)
        return f"已逾期{h // 24}天" if h >= 24 else f"已逾期{h}小时"
    else:
        secs = (end_at - now).total_seconds()
        h = int(secs / 3600)
        return f"还剩{h // 24}天" if h >= 24 else f"还剩{h}小时"


def in_time_range(config):
    start = config.get("notify", "time_range_start", fallback="08:00")
    end = config.get("notify", "time_range_end", fallback="22:00")
    now = datetime.now().strftime("%H:%M")
    return start <= now <= end


def ensure_datetime(val):
    if isinstance(val, str):
        return datetime.strptime(val, "%Y-%m-%d %H:%M:%S")
    return val


# ---------------------------------------------------------------------------
# Overdue notifications (Issue #2)
# ---------------------------------------------------------------------------

def query_overdue_tasks(my, prefix, warning_hours):
    cutoff = datetime.now() + timedelta(hours=warning_hours)
    sql = f"""
        SELECT
            t.id            AS task_id,
            t.name          AS task_name,
            t.`desc`        AS task_desc,
            t.end_at,
            t.p_name        AS priority_name,
            t.p_color       AS priority_color,
            t.flow_item_name AS status_name,
            t.project_id,
            creator.nickname AS assigner_name,
            owner.userid     AS owner_id,
            owner.nickname   AS owner_name,
            owner.email      AS owner_email,
            p.name           AS project_name
        FROM {prefix}project_tasks t
        JOIN {prefix}project_task_users tu
            ON tu.task_id = t.id AND tu.owner = 1
        JOIN {prefix}users owner
            ON tu.userid = owner.userid
        JOIN {prefix}users creator
            ON t.userid = creator.userid
        JOIN {prefix}projects p
            ON t.project_id = p.id
        WHERE t.complete_at IS NULL
          AND t.deleted_at IS NULL
          AND t.archived_at IS NULL
          AND t.end_at IS NOT NULL
          AND t.end_at <= %s
          AND owner.disable_at IS NULL
          AND owner.bot = 0
        ORDER BY t.end_at ASC
    """
    with my.cursor() as cur:
        cur.execute(sql, (cutoff,))
        return cur.fetchall()


def process_overdue(config, my, sq):
    prefix = config.get("database", "prefix", fallback="pre_")
    warning_hours = config.getint("notify", "overdue_warning_hours", fallback=4)
    base_url = config.get("notify", "base_url")

    tpl = load_template("overdue")
    if not tpl:
        return

    tasks = query_overdue_tasks(my, prefix, warning_hours)
    now = datetime.now()

    for t in tasks:
        end_at = ensure_datetime(t["end_at"])
        is_overdue = end_at < now
        notify_type = "overdue" if is_overdue else "overdue_warning"

        if already_sent(sq, t["task_id"], t["owner_id"], notify_type):
            continue

        remaining = fmt_remaining(end_at)
        task_url = f"{base_url}/single/task/{t['task_id']}"

        if is_overdue:
            h = int((now - end_at).total_seconds() / 3600)
            subject = f"[逾期] {t['task_name'][:15]} 超期{h}小时"
            color_bar = "#F44336"
            context_msg = "⚠ 此任务已逾期，请立即处理。请在任务详情中说明原因并更新预计完成时间。"
            btn_text = "立即处理"
        else:
            hours_left = int((end_at - now).total_seconds() / 3600)
            subject = f"[即将逾期] {t['task_name'][:15]} 还剩{hours_left}小时"
            color_bar = "#FF9800"
            context_msg = f"⚠ 此任务将在 {hours_left} 小时后到期，请及时完成。"
            btn_text = "查看任务"

        html = tpl.safe_substitute(
            color_bar=color_bar,
            task_name=t["task_name"],
            assigner_name=t["assigner_name"],
            owner_name=t["owner_name"],
            deadline=end_at.strftime("%Y-%m-%d %H:%M"),
            time_remaining=remaining,
            priority_name=t["priority_name"] or "普通",
            priority_color=t["priority_color"] or "#999",
            status_name=(t["status_name"] or "进行中").split("|")[-1],
            project_name=t["project_name"],
            context_msg=context_msg,
            btn_text=btn_text,
            task_url=task_url,
            base_url=base_url,
        )

        try:
            send_email(config, t["owner_email"], subject, html)
            mark_sent(sq, t["task_id"], t["owner_id"], notify_type)
            log.info(
                f"Sent {notify_type} → {t['owner_email']} "
                f"task#{t['task_id']}: {t['task_name']}"
            )
        except Exception as e:
            log.error(
                f"Failed {notify_type} → {t['owner_email']} "
                f"task#{t['task_id']}: {e}"
            )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    config = load_config()

    if not in_time_range(config):
        return

    sq = init_sqlite()
    try:
        my = get_mysql(config)
    except Exception as e:
        log.error(f"MySQL connection failed: {e}")
        sq.close()
        return

    try:
        process_overdue(config, my, sq)
    except Exception as e:
        log.error(f"Error in notification processing: {e}")
    finally:
        my.close()
        sq.close()


if __name__ == "__main__":
    main()
