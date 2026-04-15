# DooTask - 开源任务管理系统

**[English](./README.md)** | 中文文档

- [截图预览](./README_PREVIEW.md)
- [演示站点](http://www.dootask.com/)

**QQ交流群**

- QQ群号: `546574618`

## Fork 修改说明 (dingning.ai)

本仓库是 [DooTask](https://github.com/kuaifan/dootask) 的修改版 Fork，用于内部部署。详见 [MODIFICATIONS.md](./MODIFICATIONS.md)。

### DooTask 代码修改

- `app/Module/Doo.php`：绕过 License 用户数限制（自建部署）
- `docker/nginx/default.conf`：禁用 appstore 代理（镜像不可用）
- `resources/assets/js/.../template/file-download.vue`：修复"立即下载"按钮（iView `Button :to` 把 URL 当 vue-router 路由静默失败，改用 `window.open` 主动触发下载，详见 [MODIFICATIONS.md](./MODIFICATIONS.md)）

### 任务邮件通知系统

独立 Python 脚本（`task-notify/`），只读 DooTask 数据库，在任务关键节点自动发送结构化邮件：

| 通知类型 | 触发条件 | 颜色 |
|---------|---------|------|
| 新任务指派 | 任务创建并分配负责人 | 绿色 |
| 确认超时 | 创建 4 小时后仍未确认 | 橙色 |
| 逾期警告 | 截止前 4 小时 / 已逾期 | 红色 |
| 每日汇总 | 每天 16:00 | 蓝色 |

配置方式：参考 `task-notify/config.ini.example`。

```bash
# 安装依赖
pip3 install pymysql
cp task-notify/config.ini.example task-notify/config.ini
# 编辑 config.ini 填入数据库和 SMTP 凭证

# 手动运行
python3 task-notify/notify.py

# 添加定时任务（每分钟执行）
echo '* * * * * root /opt/task-notify/venv/bin/python3 /opt/task-notify/notify.py' > /etc/cron.d/task-notify
```

### BD 日报自动化（Artisan Schedule）

在 `留学渠道全员任务` 项目里，每个工作日自动为每位 BD 建一条**独立主任务**（标题 `{昵称} YYYY-MM-DD 日报`），18:00 对未完成的任务负责人发站内 + 邮件催交。

每人有独立任务详情页，可在描述区填写日报内容、评论补充、上传附件，完成后点"完成"按钮。

| 项 | 值 |
|---|---|
| 任务结构 | 每 BD 一条独立主任务（`parent_id=0`），非父子任务 |
| 创建时间 | 工作日 09:00 |
| 催交时间 | 工作日 18:00（站内 + 邮件） |
| 工作日定义 | 周一~周五 且 非中国法定节假日（通过 `timor.tech` API 判断，API 失败降级为周末规则） |
| BD 名单来源 | 配置的根部门 ID（推荐）或部门名（fallback），递归包含所有子部门成员 |
| 幂等判重 | 按 `userid` 判重（昵称中途修改不会重复建任务） |
| 排除名单 | 通过 `BD_REPORT_EXCLUDE_USERIDS` 显式配置（无内置默认） |
| 模板 | 当前不预填，BD 在描述区自由填写（结构化解析方向已留档，见设计文档） |
| 实现 | 2 条 Artisan 命令 + `LaravelScheduleJob` 触发 |

**环境变量**（`.env`，**所有内部业务值需在部署时显式配置，仓库内不带默认值以避免泄露内部信息**）：

| 变量 | 必填 | 示例 | 说明 |
|---|---|---|---|
| `LARAVELS_TIMER` | ✅ | `true` | 必须 `true` 才会触发 schedule |
| `BD_REPORT_PROJECT` | ✅ | `项目名` | DooTask 里项目名（必须已存在） |
| `BD_REPORT_DEPARTMENT_IDS` | ✅（推荐） | `1` 或 `1,2` | 根部门 ID（多个用 `,` 分隔，递归包含子部门）。比按名字匹配更稳——部门改名不影响 |
| `BD_REPORT_DEPARTMENT` | 否 | `部门全名` | 根部门**名**，仅当 `BD_REPORT_DEPARTMENT_IDS` 为空时作为 fallback |
| `BD_REPORT_PARENT_OWNER_EMAIL` | ✅ | `owner@example.com` | 父任务负责人邮箱（创建人显示为此用户） |
| `BD_REPORT_EXCLUDE_USERIDS` | 否 | `1` | 排除的 userid 列表（逗号分隔），如不配置则无人排除 |
| `BD_REPORT_TASK_URL_BASE` | ✅ | `https://task.example.com` | 催交邮件/站内消息里的任务链接基址 |
| `BD_REPORT_HOLIDAY_API` | 否 | `https://timor.tech/api/holiday/info/` | 节假日 API |
| `BD_REPORT_ALERT_ENABLED` | 否 | `true` | 告警开关（false 时仅写日志不推送） |
| `BD_REPORT_SUBTASK_TITLES` | 否 | （空） | 每个主任务下挂的子任务清单（DooTask checklist），逗号分隔。例：`租赁商机,新增合作方,新增租赁成单,新增学费成单,是否达成最低预算,其他工作`。空则不挂子任务 |

**手动命令**：

```bash
# 预览当日建任务计划（不落库）
sudo docker exec dootask-php-3185cf php artisan bd-daily-report:create --dry-run

# 立即创建当日任务
sudo docker exec dootask-php-3185cf php artisan bd-daily-report:create

# 预览当日催交名单
sudo docker exec dootask-php-3185cf php artisan bd-daily-report:remind --dry-run

# 立即催交
sudo docker exec dootask-php-3185cf php artisan bd-daily-report:remind

# 指定日期（补建历史/测试）
sudo docker exec dootask-php-3185cf php artisan bd-daily-report:create --date=2026-04-14
```

**相关文件**：

- `app/Console/Commands/BdDailyReportCreate.php` — 建任务命令
- `app/Console/Commands/BdDailyReportRemind.php` — 催交命令
- `app/Module/HolidayClient.php` — 节假日 API 客户端
- `app/Module/BdDailyReportNotifier.php` — 站内/邮件/告警封装
- `config/bd_daily_report.php` — 配置
- `app/Console/Kernel.php` — schedule 注册（工作日 09:00 / 18:00）

设计文档见 `../docs/DESIGN_2026-04-14_BD_DAILY_REPORT.md`。

### 运维监控脚本

#### 磁盘告警 `ops/disk_alert.py`

独立监控脚本（**MIT 协议**，非 DooTask 衍生作品）。每 30 分钟检查根分区使用率，超过阈值发邮件告警。复用 task-notify 的 SMTP 配置——独立于 DooTask 主服务，避免 DooTask 故障时告警通道也失效。

特性：
- 防风暴：同一告警 4 小时内最多发一次
- 邮件正文含 df 各分区 + Docker 占用 + 排查命令
- 主题含 `SERVER_LABEL`（默认 hostname），多机器可区分
- 数字与 `df -h` 一致（排除文件系统 root 保留块）
- 告警路径**不跑递归 `du`**（磁盘满 + I/O 拥堵时会卡死）

环境变量：

| 变量 | 默认 | 说明 |
|---|---|---|
| `THRESHOLD` | `85` | 告警阈值（百分比） |
| `RECIPIENTS` | `ning.ding@uhomes.com` | 收件人（多个用 `,` 分隔） |
| `SERVER_LABEL` | `os.uname().nodename` | 主题里的服务器标识 |

部署：

```bash
# 1. 下载脚本
sudo curl -fsSL https://raw.githubusercontent.com/yalding8/dootask/pro/ops/disk_alert.py \
    -o /opt/task-notify/disk_alert.py
sudo chmod +x /opt/task-notify/disk_alert.py

# 2. 注册 cron
echo "*/30 * * * * root SERVER_LABEL=<your-label> /opt/task-notify/venv/bin/python3 /opt/task-notify/disk_alert.py" \
    | sudo tee /etc/cron.d/disk-alert
sudo systemctl restart cron

# 3. 测试触发（强制低阈值）
sudo bash -c "SERVER_LABEL=test THRESHOLD=10 /opt/task-notify/venv/bin/python3 /opt/task-notify/disk_alert.py"
```

#### Docker 周清

每周日 03:00 自动清理 Docker 构建缓存 + 悬挂镜像，避免磁盘累积满。

```bash
# 一次性安装（脚本 + cron）见 docs/INCIDENT_2026-04-15_DISK_FULL.md 附录
ls /etc/cron.d/docker-cleanup
```

事故复盘见 [docs/INCIDENT_2026-04-15_DISK_FULL.md](../docs/INCIDENT_2026-04-15_DISK_FULL.md)。

---

## 📍 0.x 迁移到 1.x

- 升级时请务必备份好数据！
- 如果升级失败请尝试执行 `./cmd update` 重试几次。
- 如果升级中出现 `没有找到 xxx 容器` 的提示，请运行 `./cmd reup` 后再执行 `./cmd update`。
- 如果升级后出现502错误请运行 `./cmd reup` 重启服务即可。
- 如果升级后出现 `应用「xxx」未安装` 的提示，请使用管理员账号进入应用商店安装相关应用。

## 安装程序

- 必须安装：`Docker v20.10+` 和 `Docker Compose v2.0+`
- 支持环境：`Centos/Debian/Ubuntu/macOS` 等 linux/unix 系统
- 硬件建议：2核4G以上
- 特别说明：Windows 可以使用 WSL2 安装 Linux 环境后再安装 DooTask。

### 部署项目

```bash
# 1、克隆项目到您的本地或服务器

# 通过github克隆项目
git clone --depth=1 https://github.com/kuaifan/dootask.git
# 或者你也可以使用gitee
git clone --depth=1 https://gitee.com/aipaw/dootask.git

# 2、进入目录
cd dootask

# 3、一键安装项目（自定义端口安装，如：./cmd install --port 80）
./cmd install
```

### 重置密码

```bash
# 重置默认管理员密码
./cmd repassword
```

### 更换端口

```bash
# 此方法仅更换http端口，更换https端口请阅读下面SSL配置
./cmd port 80
```

### 停止服务

```bash
./cmd down
```

### 启动服务

```bash
./cmd up
```

### 开发编译

请确保你已经安装了 `NodeJs 20+`

```bash
# 开发模式
./cmd dev
   
# 编译项目（这是网页端的，客户端请参考“.github/workflows/publish.yml”文件）
./cmd prod  
```

### SSL 配置

#### 方法1：自动配置

```bash 
# 执行指令，根据提示执行即可
./cmd https
```

#### 方法2：Nginx 代理配置

```bash 
# 1、Nginx 代理配置添加
proxy_set_header X-Forwarded-Host $http_host;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

# 2、执行指令（如果取消 Nginx 代理配置请运行：./cmd https close）
./cmd https agent
```

## 升级更新

**注意：在升级之前请备份好你的数据！**

```bash
./cmd update
```

* 跨越大版本升级失败时请重试执行一次。
* 如果升级后出现502请运行 `./cmd reup` 重启服务即可。

## 迁移项目

在新项目安装好之后按照以下步骤完成项目迁移：

1、备份原数据库

```bash
# 在旧的项目下执行指令
./cmd mysql backup
```

2、将旧项目以下文件和目录拷贝至新项目同路径位置

 - `数据库备份文件`
 - `docker/appstore`
 - `public/uploads`

3、还原数据库至新项目
```bash
# 在新的项目下执行指令
./cmd mysql recovery
```

## 卸载项目

```bash
./cmd uninstall
```

### 更多指令

```bash
./cmd help
```
