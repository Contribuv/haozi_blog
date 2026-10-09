# Haozi Blog（PHP 版）

infowe.site 博客系统的 PHP 实现：单管理员、轻量、开箱即用，自带安装向导。

## 简介

本项目是原 Flask 版博客的 PHP 重构版本，保持前端交互与后台功能 1:1 对齐，改用 PHP 8 + MySQL 实现，
便于在任意支持 PHP 的虚拟主机或服务器上直接部署，无需 Python 环境。

主要功能：

- 文章、分类、标签、搜索与 RSS 输出
- 项目展示（支持 GitHub / Gitee 仓库信息同步与语言占比统计）
- 友链申请与审核、时间线、站点状态监控
- 评论（含嵌套回复、审核、头像代理）
- 主题切换、站点设置、数据备份与导入（SQL / SQLite）
- 后台在线升级

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.0 及以上 |
| MySQL | 5.7 或 8.0 |
| PHP 扩展 | `pdo_mysql`、`mbstring`、`curl`、`gd` |

## 安装

1. 将项目上传到服务器，并把**网站运行目录指向 `public/`**；
2. 浏览器访问站点首页，会自动进入安装向导；
3. 按提示依次完成：环境检测 → 数据库连接 → 管理员账号 → 站点信息；
4. 安装完成后自动创建一封《致使用者的一封信》作为示例文章。

向导会在 `config/config.php` 写入数据库凭据，该文件已加入 `.gitignore`，请勿提交到仓库；
新环境分发时仓库中只保留 `config/config.sample.php` 模板文件。

后台入口：`/admin`（使用安装时填写的账号密码登录）。

## 目录结构

```
PHP_blog/
├── core/            内核：路由、控制器、模型、服务、自研模板引擎
├── public/          Web 根目录（入口 index.php / admin.php / install.php）
├── themes/          主题（含 tech 默认主题）
├── plugins/         插件目录
├── config/          配置（config.sample.php 为模板）
├── docs/            设计文档
├── bin/             CLI 脚本（迁移、监控、后台任务）
├── storage/         运行期缓存、会话、日志
├── backups/         数据库备份
└── uploads/         上传文件
```

## 升级

- 后台「系统升级」页可一键检查并升级；
- 或直接在服务器上 `git pull` 后重启 PHP 服务。

升级只替换程序文件，`config/` 目录不会被覆盖。

## 常见问题

### 安装时提示「未能创建单管理员守护触发器」

这是 MySQL 的限制而非建表失败：**开启 binlog 时，创建触发器需要全局 `SUPER` 权限**，
而数据库账号通常只被授予单库权限（`GRANT ALL ON 该库.*`）。

向导会自动跳过该触发器，单管理员约束改由程序层保证（后台不提供创建第二个账号的入口），
不影响正常使用。

如需数据库层兜底，用有 `SUPER` 权限的账号（如 root）手动执行一次即可，之后无需再调整任何变量：

```sql
USE 你的数据库名;
DROP TRIGGER IF EXISTS users_single_admin_guard;
CREATE TRIGGER users_single_admin_guard BEFORE INSERT ON users FOR EACH ROW
BEGIN
  IF (SELECT COUNT(*) FROM users) >= 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'users 表仅允许单个管理员账号';
  END IF;
END;
```

### 忘记管理员密码

可通过后台登录页的「忘记密码」走邮箱找回；若未配置 SMTP，可直接在数据库中重置，
或删除 `config/installed.lock` 后重新走一遍安装向导（会清空数据，请谨慎）。

## 版本

当前版本：**1.0.0**