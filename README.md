# DooTask - Open Source Task Management System

English | **[中文文档](./README_CN.md)**

- [Screenshot Preview](./README_PREVIEW.md)
- [Demo Site](http://www.dootask.com/)

**QQ Group**

- Group Number: `546574618`

## Fork Modifications (dingning.ai)

This is a modified fork of [DooTask](https://github.com/kuaifan/dootask) for internal use. See [MODIFICATIONS.md](./MODIFICATIONS.md) for details.

### Changes to DooTask

- `app/Module/Doo.php`: Bypass license user limit for self-hosted deployment
- `docker/nginx/default.conf`: Disabled appstore proxy (image unavailable)

### Task Email Notification System

An independent Python script (`task-notify/`) that reads DooTask database (read-only) and sends structured email notifications:

| Notification | Trigger | Color |
|-------------|---------|-------|
| New task assigned | Task created with assignee | Green |
| Confirm timeout | Not confirmed after 4h | Orange |
| Overdue warning | 4h before deadline / overdue | Red |
| Daily summary | Every day at 16:00 | Blue |

Setup: see `task-notify/config.ini.example` for configuration.

```bash
# Install
pip3 install pymysql
cp task-notify/config.ini.example task-notify/config.ini
# Edit config.ini with your DB and SMTP credentials

# Run manually
python3 task-notify/notify.py

# Add to cron (every minute)
echo '* * * * * root /opt/task-notify/venv/bin/python3 /opt/task-notify/notify.py' > /etc/cron.d/task-notify
```

---

## 📍 Migration from 0.x to 1.x

- Please ensure to back up your data before upgrading!
- If the upgrade fails, try running `./cmd update` multiple times.
- If you encounter "Container xxx not found" during upgrade, run `./cmd reup` and then execute `./cmd update`.
- If you see a 502 error after upgrading, run `./cmd reup` to restart the services.
- If you encounter "Application 'xxx' not installed" after upgrading, log in with the admin account and install the relevant applications from the App Store.

## Installation Requirements

- Required: `Docker v20.10+` and `Docker Compose v2.0+`
- Supported Systems: `CentOS/Debian/Ubuntu/macOS` and other Linux/Unix systems
- Hardware Recommendation: 2+ cores, 4GB+ memory
- Special Note: Windows users can install Linux environment using WSL2 before installing DooTask.

### Deploy Project

```bash
# 1、Clone the project to your local machine or server

# Clone project from GitHub
git clone --depth=1 https://github.com/kuaifan/dootask.git
# Or you can use Gitee
git clone --depth=1 https://gitee.com/aipaw/dootask.git

# 2、Enter directory
cd dootask

# 3、One-click installation (Custom port installation: ./cmd install --port 80)
./cmd install
```

### Reset Password

```bash
# Reset default administrator password
./cmd repassword
```

### Change Port

```bash
# This method only changes HTTP port. For HTTPS port, please read SSL configuration below
./cmd port 80
```

### Stop Service

```bash
./cmd down
```

### Start Service

```bash
./cmd up
```

### Development & Build

Please ensure you have installed `NodeJs 20+`

```bash
# Development mode
./cmd dev
   
# Build project (This is for web client. For desktop apps, refer to ".github/workflows/publish.yml")
./cmd prod  
```

### SSL Configuration

#### Method 1: Automatic Configuration

```bash 
# Run command and follow the prompts
./cmd https
```

#### Method 2: Nginx Proxy Configuration

```bash 
# 1、Add Nginx proxy configuration
proxy_set_header X-Forwarded-Host $http_host;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

# 2、Run command (To cancel Nginx proxy configuration: ./cmd https close)
./cmd https agent
```

## Upgrade & Update

**Note: Please backup your data before upgrading!**

```bash
./cmd update
```

* Please retry if upgrade fails across major versions.
* If you encounter 502 errors after upgrade, run `./cmd reup` to restart services.

## Project Migration

After installing the new project, follow these steps to complete migration:

1、Backup original database

```bash
# Run command in the old project
./cmd mysql backup
```

2、Copy the following files and directories from old project to the same paths in new project

 - `Database backup file`
 - `docker/appstore`
 - `public/uploads`

3、Restore database to new project
```bash
# Run command in the new project
./cmd mysql recovery
```

## Uninstall Project

```bash
./cmd uninstall
```

### More Commands

```bash
./cmd help
```
