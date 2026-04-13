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
