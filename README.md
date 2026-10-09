# Haozi Blog（PHP 版）

infowe.site 博客系统的 PHP 实现：**单管理员、轻量、开箱即用**，自带图形化安装向导，无需 Python 环境。

> 原 Flask 版（SQLite）已停止更新，本仓库为 1:1 重构版本：前端交互与后台功能保持一致，存储层改为 MySQL。

## 功能特性

### 前台

- **文章**：Markdown 写作、分类、标签、精选、阅读时长、访问计数、上下篇导航
- **文章状态**：`published`（已发布）/ `draft`（草稿）/ `hidden`（隐藏）——隐藏的文章仅凭 URL
  可访问，不出现在首页、文章列表、标签页、年份归档与上下篇导航中
- **检索**：全文搜索、标签聚合页、分类筛选、分页
- **订阅**：`/feed.xml` RSS 输出
- **评论**：可开关、嵌套回复、审核制、通知邮件、IP 归属地离线解析（内置 ip2region 库，零外部请求）、头像代理
- **项目展示**：GitHub / Gitee 仓库信息同步、语言占比统计、单项目评论区
- **友链**：申请提交 + 后台审核、批量管理
- **时间线 / 关于页 / 站点状态监控**（探活结果可后台配置）
- 分享卡片缩略图（`og:image` 自动生成）、站内 404 页、站点备案信息展示

### 后台

- 仪表盘（文章/评论/友链统计）
- 文章管理：新建、编辑、预览、删除、批量操作
- 分类 / 标签 / 时间线 / 友链 / 评论 管理（均支持批量操作）
- 项目管理：单条或全量同步 GitHub 数据、同步状态查询
- 评论审核与批量清理
- 媒体上传：图片 / 媒体 / 文件
- 孤儿文件清理：缩略图预览、搜索、排序、批量删除
- 站点设置：站点信息、导航开关、评论开关、统计代码注入、图片水印、SMTP 测试
- 主题切换（多套主题目录，开箱内置 `tech`）
- 数据备份与迁移：数据库备份、SQL / SQLite 文件导入、JSON / Markdown 导出
- **在线升级**：检测并一键升级，GitHub Releases 主源 + Gitee 镜像回退

### 安全

- 单管理员硬约束（数据库触发器兜底 + 程序层保证）
- 登录防爆破（基于 IP 的失败计数、递增延迟、15 分钟锁定、验证码）
- OTP 双重验证（RFC 6238 TOTP，兼容 Google Authenticator / 1Password / Authy）
- 邮箱找回密码（6 位验证码，10 分钟有效、60 秒限频）
- **CSRF 防护**：全部 POST 请求在根 `index.php` 统一校验 Origin / Referer 同源，
  跨站提交一律返回 403（`themes/<主题>/403.html`）；配合 Session Cookie 的 `SameSite=Lax`
  形成两层防护，后台表单无需手工插入 token
- 反代场景下 `X-Forwarded-For` 伪造防护：仅当 `REMOTE_ADDR` 本身是回环 / 私网地址时
  才采信 XFF，且逐段校验格式
- 安全响应头、CSP、Session Cookie 加固、Markdown 链接协议过滤（含 GitHub README）

## 环境要求

| 项目 | 要求 | 说明 |
| --- | --- | --- |
| PHP | 8.0 及以上 | 推荐 8.2 / 8.3 |
| MySQL | 5.7 或 8.0 | 字符集 `utf8mb4` |
| PHP 扩展 | `pdo_mysql`、`mbstring`、`curl`、`gd` | 安装向导第一步自动检测 |
| Web 服务器 | Nginx / Apache | 运行目录（网站根目录）直接指向**项目根目录** |

## 目录结构

```
PHP_blog/                    ← 网站运行目录（root / DocumentRoot）直接指这里
├── index.php                全站唯一入口（前台 + 后台全部路由）
├── install.php              安装向导
├── .htaccess                Apache 伪静态 + 敏感目录封锁（Apache 用户无需另配）
├── core/                    内核
│   ├── Controller/          控制器（front 前台 / admin 后台）
│   ├── Model/               模型（Post / Comment / Project / Link / ...）
│   ├── Service/             服务（Markdown / Upload / Mail / Otp / Backup / ErrorPage / ...）
│   ├── Template/            自研 Jinja2 兼容模板引擎（Compiler / Runtime / Markup）
│   ├── view/                视图模板（前台 / 后台 / 公共片段）
│   ├── lib/                 随包资源（cacert.pem）
│   ├── routes.php           全站路由表
│   ├── Url.php              URL 生成（新增路由须同步登记 ROUTES）
│   └── bootstrap.php        启动引导（配置加载、自动加载、会话）
├── themes/                  主题（模板 + 该主题的静态资源）
│   └── tech/                内置主题，可另建子目录
├── static/                  全站静态资源（含 50x.html 兜底页）
├── plugins/                 插件（Hook 钩子总线）
├── config/                  配置
│   └── config.sample.php    配置模板（config.php 由安装向导生成，不入库）
├── bin/                     CLI 脚本（migrate / project_sync / wm_refresh / schema / dbcheck / sqlitecheck / build_50x / check_*）
├── data/                    运行期数据（项目同步状态、升级缓存）
├── fonts/                   内置中文字体（文泉驿微米黑，Apache-2.0）
├── storage/                 缓存（模板编译产物）、会话、日志
├── backups/                 数据库备份
└── uploads/                 上传文件
```

> 站点 URL 与磁盘路径一一对应：`/static/…` → `static/`，`/themes/…` → `themes/`。
> 其余非真实文件的请求一律交给根 `index.php`。

> **安全前提**：网站运行目录就是项目根，因此 `config/config.php`（含数据库密码）、
> `core/`、`storage/`、`data/`、`backups/`、`bin/`、`plugins/`、`docs/`、`fonts/`
> 都在 Web 可见范围内。根目录 `.htaccess`（Apache）与 README 中的 nginx 配置
> （Nginx）**必须保留其中的封锁规则**，否则源码与数据库密码可被直接下载。
> `themes/` 下的 `.html`（模板源码）与 `.json`（`info.json`）同样已在封锁之列，
> 只放行 `theme.css` / `theme.js` / `preview.png`。

> `config/config.php`、`config/installed.lock`、`storage/`、`uploads/`、`backups/`、`data/`、`.toolchain/` 均已在 `.gitignore` 中排除，更新升级不会覆盖。

## 安装（安装向导）

1. 将项目上传到服务器，**把网站运行目录指向项目根目录**（即包含 `index.php` 的那一层）；
   Nginx 用户直接套用下文「伪静态与服务器配置」中的完整示例；
2. 浏览器访问站点首页，自动进入安装向导；
3. 按向导四步完成；
4. 安装完成后自动发布一封《致使用者的一封信》作为示例文章。

后台入口：`/admin`（使用安装时填写的账号密码登录）。

### 向导四步

**第 1 步 · 环境检测**

逐项检查并显示结果，任一项不通过则无法进入下一步：

| 检查项 | 说明 |
| --- | --- |
| PHP 版本 ≥ 8.0 | 显示当前 PHP 版本 |
| `pdo_mysql` | 数据库驱动，缺失则无法连接 MySQL |
| `mbstring` | 中文处理（截断、校验） |
| `curl` | 项目同步、水印下载等远程请求 |
| `gd` | 图片水印与缩略图 |
| `config` / `storage` / `storage/cache` 目录可写 | 缺失会自动创建 |

**第 2 步 · 数据库连接**

| 字段 | 说明 |
| --- | --- |
| 数据库主机 | 一般填 `127.0.0.1` 或数据库服务器地址 |
| 端口 | 默认 `3306` |
| 数据库名 | 仅允许字母、数字、下划线、短横线；**不存在且账号有建库权限时会自动创建** |
| 数据库用户名 | 建议使用仅授权单库的账号 |
| 数据库密码 | 仅写入 `config/config.php`，不回显 |

连接成功后向导会检查该库是否已有博客数据（有管理员账号即判定为已占用），引导改用空库。

**第 3 步 · 管理员账号**

| 字段 | 校验 |
| --- | --- |
| 管理员用户名 | 3–30 个字符，不含空格 |
| 密码 | 至少 8 位 |
| 确认密码 | 需与上一步一致 |

**第 4 步 · 站点信息**

| 字段 | 说明 |
| --- | --- |
| 站点名称 | 显示在导航、页脚与浏览器标题 |
| 作者名 | 文章署名 |
| 联系邮箱 | 用于接收评论通知与密码找回邮件 |

提交后向导完成：建库（若需要）→ 建表 → 创建管理员 → 初始化默认设置 → 发布致谢文章 → 写入 `config/config.php` → 写入 `config/installed.lock`。

### 安装完成后

站点已生成运行时配置与安装标记，重复访问不会再进入向导。如需重装：删除 `config/installed.lock` 与 `config/config.php`，并清空数据库。

出于安全考虑，建议在 Nginx 中禁止访问安装向导（根 `index.php` 已内置 `installed.lock`
判断，删除 `install.php` 同样安全）：

```nginx
location = /install.php { deny all; return 404; }
```

### 手动安装（可选）

不想走向导时，复制 `config/config.sample.php` 为 `config/config.php` 并填写数据库信息，
再执行建表脚本，然后访问站点（检测到已有管理员会自动补写安装标记）：

```bash
php bin/schema.php          # 建表（幂等）
php bin/migrate.php         # 结构迁移
```

## 伪静态与服务器配置

站点入口已合并为根目录的 `index.php`（前台 + 后台全部路由都在其中），因此**不再需要**
把 `/admin` 前缀单独交给另一个 `admin.php`。只剩两件事要做：

1. 非真实文件的请求 → 交给 `index.php`
2. **封锁源码与数据目录** —— 运行目录就是项目根，不封锁等于把数据库密码敞开

### Nginx

宝塔面板：**网站 → 设置 → 伪静态**，粘贴以下内容（`fastcgi_pass` 的 socket 路径按实际
PHP 版本修改，宝塔一般位于 `/tmp/php-cgi-XX.sock`）。

```nginx
# 网站运行目录 = 项目根目录（含 index.php 的那一层），URL 与磁盘一一对应

# ① 源码与数据目录：禁止 Web 访问（缺了这条 config/config.php 可被直接下载）
location ~ ^/(config|core|storage|data|backups|bin|plugins|docs|fonts)(/|$) { deny all; return 404; }
location ~ /\.            { deny all; return 404; }
location ^~ /.well-known/ { allow all; }

# ② 主题目录只放行静态资源：theme.css / theme.js / preview.png
location ~ ^/themes/.+\.(html|json)$ { deny all; return 404; }

# ③ 上传目录：正常图片可访问，但隐藏目录（无痕原图 .originals）与可执行脚本一律拒绝
#    注意别写成 location ~* /uploads/.*/ —— 那会连正常的 /uploads/2026/08/xxx.jpg 也拦掉
location ~ ^/uploads/(?:.*/)?\.[^/]*        { deny all; return 404; }
location ~* ^/uploads/.*\.(php|phtml|phar)$ { deny all; return 404; }

# ④ 伪静态：非真实文件/目录一律交给前端控制器（static/ 与 themes/ 下的真实文件由 nginx 直出）
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# ⑤ 静态资源缓存
location ~* ^/(static|themes)/.*\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf)$ {
    expires 30d;
    add_header Cache-Control "public";
    access_log off;
}

# ⑥ 错误页：404 走主题；502/504 走预渲染静态兜底页（此时 PHP 已崩溃，无法现渲染）
error_page 404 /index.php;
error_page 500 502 503 504 /static/50x.html;

location ~ \.php$ {
    fastcgi_pass unix:/tmp/php-cgi-83.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_read_timeout 300;
    include fastcgi_params;
}
```

注意事项：

- **`/admin` 无需任何特殊配置**，根 `index.php` 已注册后台路由。
- **`themes/` 的封锁规则不能漏**，否则主题模板源码（`base.html` 等）可被直接下载。
- `error_page 404 /index.php` 会让 nginx 内部再走一次 PHP，PHP 内 `Router` 未命中即输出
  主题化的 404 页（`themes/<主题>/404.html`）；模板缺失时自动降级为自包含 HTML。
- `502` 必须指向 `static/50x.html` 这个**预渲染静态文件**：nginx 连不上 PHP 时
  PHP 已经崩溃，无法现场渲染主题页。主题或样式改动后用
  `php bin/build_50x.php` 重新生成。
- 修改后 `nginx -t` 检查语法，再 `nginx -s reload`。

### Nginx 完整示例（含 HTTPS）

```nginx
server {
    listen 80;
    server_name infowe.site;
    root /www/wwwroot/www.infowe.site;
    index index.php;

    location ~ ^/(config|core|storage|data|backups|bin|plugins|docs|fonts)(/|$) { deny all; return 404; }
    location ~ /\.            { deny all; return 404; }
    location ^~ /.well-known/ { allow all; }
    location ~ ^/themes/.+\.(html|json)$ { deny all; return 404; }
    location ~ ^/uploads/(?:.*/)?\.[^/]*        { deny all; return 404; }
    location ~* ^/uploads/.*\.(php|phtml|phar)$ { deny all; return 404; }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~* ^/(static|themes)/.*\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf)$ {
        expires 30d;
        add_header Cache-Control "public";
        access_log off;
    }

    error_page 404 /index.php;
    error_page 500 502 503 504 /static/50x.html;

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-83.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
        include fastcgi_params;
    }
}
```

### Apache

项目根目录的 `.htaccess` 已内置全部规则（敏感目录封锁 + 主题模板封锁 + 伪静态），
站点只需允许 `AllowOverride All`：

```apache
<Directory /www/wwwroot/www.infowe.site>
    AllowOverride All
</Directory>
```

规则内容见根目录 `.htaccess`，无需在面板里另写伪静态。

### 宝塔 open_basedir（重要）

网站运行目录就是项目根，因此内核与数据目录天然都在 open_basedir 白名单内，通常无需处理。
若面板仍限制了路径导致首页报：

```
require_once(): open_basedir restriction in effect.
File(/www/wwwroot/www.infowe.site/core/bootstrap.php) is not within the allowed path(s)
```

两种解决方式，任选其一：

1. **关闭「防跨站攻击」**：宝塔 → 网站 → 设置 → 网站目录 → 关闭「防跨站攻击」；
2. **精确放行站点根目录**（保持防跨站攻击开启，推荐）：

```bash
cd /www/wwwroot/www.infowe.site
chattr -i .user.ini                      # 解除锁定（宝塔加了 +i）
sed -i 's#^open_basedir=.*#open_basedir=/www/wwwroot/www.infowe.site/:/tmp/#' .user.ini
chattr +i .user.ini                      # 重新锁定，防面板覆盖
```

注意：open_basedir 配置有约 300 秒缓存，改完后需等待或重启 PHP（约 1 分钟）再刷新页面。

验证：登录 SSH 执行 `cat /www/wwwroot/www.infowe.site/.user.ini`，应看到
`open_basedir=/www/wwwroot/www.infowe.site/:/tmp/`（**末尾不带 `/public/`**）。

## 本地开发

```bash
# PHP 内置服务器（bin/router.php 负责静态直出与伪静态兜底）
php -S 0.0.0.0:8000 -t . bin/router.php
```

访问 http://127.0.0.1:8000 ，后台 http://127.0.0.1:8000/admin/login 。

常用 CLI 脚本：

| 脚本 | 作用 |
| --- | --- |
| `php bin/schema.php` | 建表（幂等，可重复执行） |
| `php bin/migrate.php` | 结构迁移 |
| `php bin/project_sync.php` | 同步 GitHub / Gitee 项目数据 |
| `php bin/wm_refresh.php` | 按当前设置重做历史图片水印 |
| `php bin/dbcheck.php` | 检查数据库连接与表结构 |
| `php bin/sqlitecheck.php` | 检查 SQLite 备份文件 |
| `php bin/build_50x.php` | 重新生成 502 兜底页 `static/50x.html`（改主题样式后执行） |
| `php bin/check_routes.php` | 校验路由表与 `Url::ROUTES` 一致性 |
| `php bin/check_errorpage.php` | 校验错误页链路（主题模板 / 降级 HTML / 静态兜底） |
| `php bin/check_hidden.php` | 校验文章 `hidden` 状态的列表 / 邻篇 / 统计口径 |
| `php bin/check_iploc.php` | 校验 IP 归属地离线解析 |
| `php bin/check_ip.php` | 校验客户端 IP 取值（反代 XFF 伪造防护） |
| `php bin/render_errorpage.php 404` | 单独渲染某个状态码的错误页，用于排查 |

`check_hidden` / `check_iploc` / `check_ip` 需要数据库连接；改动相关逻辑后先跑这三个再提交。

## 升级

- 后台「系统升级」页一键检查并升级（GitHub Releases 主源，超时或限流时自动切换 Gitee 镜像）；
- 或在服务器上 `git pull` 后重载 PHP / 清理模板缓存（`storage/cache/templates/`）。

升级只替换程序文件，`config/` 与 `storage/` 不会被覆盖。

## 常见问题

### 进入安装向导时报 open_basedir 错误

见上文「宝塔 open_basedir」。

### 安装时提示「未能创建单管理员守护触发器」

这是 MySQL 的独立限制，**不是建表失败**：开启 binlog 时，创建触发器需要全局 `SUPER` 权限，
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

### 模板改动不生效

模板编译产物按文件 mtime 失效。若改动了内核模板引擎（`core/Template/`）而非模板文件本身，
缓存不会自动重建，手动清空：

```bash
rm -rf storage/cache/templates/*
```

### 忘记管理员密码

通过后台登录页的「忘记密码」走邮箱找回；也可开启 OTP 后用恢复码。
若两者都不可用，只能在数据库中直接重置 `users` 表的密码字段。

### 页面样式或资源 404

运行目录应指向**项目根**（含 `index.php` 的那一层）。Nginx 还需确认 `config`、`core`
等目录的 `deny all` 与 `themes/` 的模板封锁规则都未被误删。见上文「伪静态与服务器配置」。

### 想改 403 / 404 / 500 / 502 页面

编辑 `themes/<主题名>/403.html`、`404.html`、`500.html`、`502.html`，然后：

- `403` / `404` / `500`：清空模板缓存 `storage/cache/templates/*` 即生效；
- `502`：nginx 用的是预渲染静态文件，须执行 `php bin/build_50x.php` 重新生成
  `static/50x.html`。

`502` 只能用静态文件，因为 nginx 连不上 PHP 时 PHP 已经崩溃，无法现场渲染模板。

`403` 是跨站请求被拦截时的页面（见下文「安全」的 CSRF 说明），由根 `index.php` 渲染，
不需要改 nginx。

## 版本

当前版本：**2.0.0**

### 从 1.x 升级到 2.0.0（破坏性变更）

1. **网站运行目录必须指向项目根目录**（含 `index.php` 的那一层），不再是 `public/`；
   Nginx 用户同步改 `root` 并套用上文「伪静态与服务器配置」的完整示例
   （新增对 `themes/` 模板源码、`uploads/` 内脚本的封锁规则）。
2. **`public/` 已整体删除**：`themes/` 与 `static/` 上移到项目根，旧的
   `public/themes`、`public/static` 路径全部失效。升级后若沿用旧 nginx 配置，
   主题与静态资源会 404。
3. **入口合并为单一 `index.php`**：`admin.php` 已移除，`/admin` 无需任何特殊配置。
4. **错误页主题化**：403 / 404 / 500 由 `themes/<主题>/<code>.html` 渲染，
   502 / 503 / 504 走 `static/50x.html` 静态兜底（PHP 崩溃时唯一能出页面的途径，
   主题改动后需重跑 `php bin/build_50x.php`）。
5. `config/`、`storage/`、`uploads/`、`backups/`、`data/` 不受影响，升级不会被覆盖。

## 开源

原 Flask 版作者：[Contribuv/infowe_blog](https://github.com/Contribuv/infowe_blog)
PHP 版：[Contribuv/haozi_blog](https://github.com/Contribuv/haozi_blog) · 镜像 [infowe/haozi_blog](https://gitee.com/infowe/haozi_blog)

模板引擎 `core/Template/` 为本项目自研实现；`core/lib/cacert.pem` 来自 curl 项目（MIT）；
`fonts/wqy-microhei.ttc` 为文泉驿微米黑（Apache-2.0）；IP 归属地解析数据来自 ip2region（Apache-2.0）。
