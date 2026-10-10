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
│   ├── config.sample.php    配置模板（config.php 由安装向导生成，不入库）
│   └── nginx.conf.example   伪静态配置样例（可整段复制到宝塔「伪静态」）
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
PHP 版本修改，宝塔一般位于 `/tmp/php-cgi-XX.sock`）。同一份内容也存于
[`config/nginx.conf.example`](config/nginx.conf.example)，可直接复制，避免抄漏 `error_page` 行。

```nginx
# ───────────────────────────────────────────────────────────────────
# PHP_blog 伪静态配置样例（宝塔面板：网站 → 设置 → 伪静态，整段粘贴）
#
# 使用前提：网站运行目录指向项目根目录（含 index.php 的那一层），URL 与磁盘一一对应。
# fastcgi_pass 的 socket 路径按实际 PHP 版本修改，宝塔一般是 /tmp/php-cgi-XX.sock。
#
# ⚠️ 最容易踩、也最致命的坑（务必照抄，不要改）：
#     错误页必须指向「预渲染静态文件」static/{404,403,50x}.html，
#     绝不能写成 error_page 404 /index.php。
#   因为 404 / 403 / 502 恰恰发生在 PHP 通道本身不可用的时候（文件没上传、fastcgi 挂了），
#   此时再让 nginx 内部走一次 PHP，只会拿到 nginx 原生错误页 —— 这正是
#   「错误页不跟主题走、始终是原生页」的根本原因。
#   静态页由 php bin/build_50x.php 生成，后台「切换主题」时也会自动重建。
# ───────────────────────────────────────────────────────────────────

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

# ⑤ 静态资源缓存（只此一处，切勿重复定义）
#    必须带 charset：否则 nginx 直出的 js / css 不带 charset，直接访问会按本地编码解码 → 中文乱码
location ~* ^/(static|themes)/.*\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf)$ {
    charset utf-8;
    charset_types application/javascript text/css;
    expires 30d;
    add_header Cache-Control "public";
    access_log off;
}

# ⑥ 错误页：全部指向预渲染静态文件，不经过 PHP。
#    404 与 403 各有独立页面，少任何一行该状态码都会退回 nginx 原生页。
error_page 404 /static/404.html;
error_page 403 /static/403.html;
error_page 500 502 503 504 /static/50x.html;

location ~ \.php$ {
    # 缺这行时，脚本不存在会返回 Primary script unknown —— nginx 照样吐原生 404 页，
    # 把「文件没上传」和「PHP 通道挂了」两种原因混在一起，看不出真实故障
    try_files $uri =404;
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
- **`error_page` 一律指向预渲染静态文件，绝不写 `/index.php`**。404/403/502 恰恰发生在 PHP 通道
  本身不可用的时候（文件没上传、fastcgi 挂了），此时让 nginx 内部再走一次 PHP 只会得到 nginx 原生页。
  静态页由 `php bin/build_50x.php` 从 `themes/<主题>/{404,403,502}.html` 预渲染并内联 theme.css，
  nginx 直接吐文件，不经过 PHP。改了主题样式后重新执行该脚本。
- **排查 404 是 nginx 原生页还是主题页**：先在项目根放一个探针 `echo '<?php echo "PHPOK";' > t.php`，
  访问 `https://你的域名/t.php`。返回 `PHPOK` 说明 PHP 通道正常，是文件没上传到位
  （1.x 升级后 `index.php` 在根、`public/` 已删）；返回 nginx 原生页才是 `location ~ \.php$`
  没生效，用 `nginx -T 2>/dev/null | grep -n 'location\|fastcgi\|root '` 查实际配置。
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
        charset utf-8;
        charset_types application/javascript text/css;
        expires 30d;
        add_header Cache-Control "public";
        access_log off;
    }

    error_page 404 /static/404.html;
    error_page 403 /static/403.html;
    error_page 500 502 503 504 /static/50x.html;

    location ~ \.php$ {
        try_files $uri =404;
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
| `php bin/build_50x.php` | 重新生成静态错误页 `static/{404,403,50x}.html`（改主题样式后必须执行） |
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

- **全部四个**都要执行 `php bin/build_50x.php` 重新生成 `static/{404,403,50x}.html`，
  因为 nginx 的 `error_page` 只读静态文件，读不到模板；
- `403` / `404` / `500` 的 PHP 侧渲染（应用内部主动 abort 时走这条路）还会带上站点名与导航，
  改完清空 `storage/cache/templates/*` 即生效。

`error_page` 必须指向静态文件，不能写 `/index.php`：404 / 403 / 502 恰恰发生在 PHP 通道本身
不可用的时候（文件没上传、fastcgi 挂了），让 nginx 内部再走一次 PHP 只会退回 nginx 原生页。

`403` 是跨站请求被拦截时的页面（见下文「安全」的 CSRF 说明），由根 `index.php` 渲染，
不需要改 nginx。

## 版本

当前版本：**2.2.4**

### 从 2.2.3 升级到 2.2.4

三项修复，其中第一项解释了「检测明明成功、页面却提示连不上 GitHub / Gitee」：

1. **升级检测被 PHP 警告污染**：宝塔环境下 `php.ini` 的 `openssl.cafile` 指向 `/etc/pki/tls/certs/ca-bundle.crt`，而它不在 `open_basedir` 白名单内，`is_file()` 越界抛出 `E_WARNING`；警告文本因 `display_errors` 被输出在 JSON **之前**，前端 `JSON.parse` 直接失败并走到兜底分支，于是显示「无法连接 GitHub / Gitee（可能网络受限…）」，实际版本早已检测成功。现改为探测路径一律抑制警告（`Http.php`、水印字体探测同理），并在 `Response::json()` 输出前清空缓冲区，确保任何警告都不会再破坏 JSON 响应。
2. **GitHub / Gitee 双平台熔断与可用性探测**：某平台连续失败后进入熔断窗口，窗口内直接跳过、不再干等连接超时，直接走另一平台；后台会异步探测两平台可用性并缓存，升级页同时展示各平台最近一次探测状态与时间。
3. **列表批量操作只对最后一项生效**：勾选框提交的是同名字段（如 `link_ids`），`a=1&a=2` 只有 `a[]` 形式才会被 PHP 解析成数组，否则后者覆盖前者，导致批量删除 / 批量操作实际只处理了最后一条。涉及文章、评论、友链、分类、项目、时间线、文件清理共 7 处，现已全部改为数组字段。

> 本版无数据库结构与 nginx 配置变更，后台一键升级即可。

<details>
<summary><b>历史版本日志（点击展开）</b></summary>

### 从 2.2.2 升级到 2.2.3

修复升级检测的一处缓存缺陷：

1. **检测失败后不再长期误判「无更新」**：此前检测结果无论成功与否都会写入缓存，一旦某次 GitHub 与 Gitee 都取不到数据，接下来的 10 分钟（`CACHE_TTL`）内所有请求都会直接返回空结果、**不再重新检测**，看起来就像「有 Gitee 镜像也查不到更新」，只有手动点「重新检测」才恢复。现改为**只缓存成功结果**，失败不落盘，下次请求立即重试；GitHub 打不开、超时、限流或返回内容非法时，自动回退 Gitee 镜像。

> 本版无数据库结构与 nginx 配置变更，后台一键升级即可。

### 从 2.2.1 升级到 2.2.2

两处修复：

1. **文件清理（/admin/orphans）删不掉单个文件**：表单字段 `path` 只有一个值时 PHP 给的是字符串而非数组，`is_array()` 判断失败导致一个都删不掉、每行都标红；现已归一化为数组。此前只有「一次勾选多个文件」才删得动，这也是该页长期存在的隐患。
2. **友链排序次序错乱（/admin/links）**：列表原按 `sort_order ASC, created_at DESC` 排序，当多条链接的排序值相同（例如都为 0）时，后添加的反而排到前面。现改为 `sort_order ASC, id ASC`，排序值为 0 的「项目本身」必定第一条。该排序全站共用，前台首页友链、`/links` 页面与页脚统计同步生效。

> 本版无数据库结构与 nginx 配置变更，后台一键升级即可。

### 从 2.2.0 升级到 2.2.1

修复升级页的一处错误链接：

1. **「打开 Releases 页面」指向了 Python 版仓库**：升级页在检测更新失败时用的兜底链接一直是原 Flask 版 `Contribuv/infowe_blog`，点开是另一个项目；现改为 PHP 版仓库 `Contribuv/haozi_blog`。当 GitHub 检测正常时，该链接本就取接口返回的真实地址，不受影响。

> 本版仅此一处前端链接修复，无其它变更。

### 从 2.1.2 升级到 2.2.0

图片处理与编辑器上传的一轮加固，另含一处移动端布局修复：

1. **上传白名单可在后台配置**：新增「博客设置 → 上传设置」，可自定义图片 / 音视频 / 附件允许的扩展名与单文件大小上限（1–512MB，留空回退默认）。编辑器文件选择器与后端校验共用同一份配置，不再出现「能选中却传不上去」。`php` / `phtml` / `phar` / `htaccess` 等可执行或会改动服务器配置的扩展名会被自动忽略并提示。
2. **支持 HEIC / HEIF**：PHP GD 不含 HEIF 解码能力，现改为依次借用 Imagick、`ffmpeg`、`magick`、`heif-convert` 转成 JPEG 后入库。**服务器需安装其中之一**，否则上传会给出明确提示。
3. **图片校验修复**：无法解码的图片（文件损坏，或把脚本改名伪装成图片）现在直接拒绝，不再原样落盘；头像上传同口径处理。
4. **GIF / WebP 保持原格式**：此前除 PNG 外一律重编码为 JPEG，导致 GIF 动画丢失、WebP 变静态图；现 GIF 原样保留动画，WebP 用 `imagewebp` 重编码（含加水印流程）。
5. **编辑器上传进度浮窗**：上传文件时屏幕居中显示进度条（含百分比与 `已完成/总数`），多文件按字节聚合，完成后自动淡出。
6. **附件链接显示类型图标**：前台文章中 `zip / pdf / doc / txt` 等下载链接按类型显示前置图标（编辑器正文仍保持纯 Markdown 链接，不污染内容）。
7. **移动端修复**：编辑器置顶工具栏与后台顶栏原本都吸附 `top:0`，滚动时工具栏首行被顶栏盖住；现按顶栏高度下移。

> 本版无需改动 nginx 配置，也没有数据库结构变更，后台一键升级即可。

### 从 2.1.1 升级到 2.1.2

伪静态配置修正（本版只涉及配置样例与文档，PHP 代码无变更）：

1. **修复直接访问 js / css 时中文乱码**：nginx 直出的静态文件按 `mime.types` 默认不带 charset，浏览器直接打开该 URL 时会按本地默认编码（中文环境常是 GBK）解码 UTF-8 文件 → 乱码。静态资源 location 现补充 `charset utf-8;` 与 `charset_types application/javascript text/css;`。
2. **明确静态资源 location 只能有一处**：重复定义相同的 `location` 会让 `nginx -t` 报 `duplicate location`、reload 失败，配置看着改了实际不生效。
3. README 精简过时的 1.x 升级说明。

> 升级到本版后，请把服务器「伪静态」中的 ⑤ 静态资源 location 更新为 [`config/nginx.conf.example`](config/nginx.conf.example) 里的版本（补上那两行 `charset`），再执行 `nginx -t && nginx -s reload`。

### 从 2.1.0 升级到 2.1.1

修复与优化：

1. **修复静态错误页生成失败（重要）**：`ErrorPages` 缺少 `use Blog\View`，导致
   `php bin/build_50x.php` 与后台换主题时的重建**一直失败**，`static/404.html`、
   `static/403.html` 始终生成不出来。nginx 的 `error_page 404 /static/404.html` 指向了不存在的
   文件，于是 404 / 403 退回 nginx 原生页。现已修复命名空间，并把 `rebuild()` 改为逐页容错
   （主题未提供错误页模板时降级为内置 HTML），保证三个文件一定能写出。
2. **修复 `/projects` 首屏卡顿**：自动同步原先在请求线程内同步调用 GitHub API 检测更新，
   项目较多时会拖慢首字节；现改为请求内只做本地判断并入队，检测与拉取在响应发出后
   （`fastcgi_finish_request`）异步执行。
3. **项目自动同步更省流量**：先比对远端 `pushed_at`，**有更新才拉** README 与图片；
   手动同步按钮仍为强制全量。前台访客也能把自动同步跑完，不再依赖管理员打开后台轮询。
4. **升级后自动重建静态错误页**：一键升级完成后自动重建一次，避免升级包里的
   `static/*.html` 与当前主题脱节。
5. **新增 `config/nginx.conf.example`**：可直接复制到宝塔「伪静态」的配置样例；
   README 伪静态段落同步更新，并强调 `error_page` 必须指向 `static/{404,403,50x}.html`。

> 升级到本版后，请**先执行一次 `php bin/build_50x.php`**（或在后台切换一次主题），
> 确保 `static/{404,403,50x}.html` 存在；否则 404 / 403 / 502 仍可能是 nginx 原生页。

### 从 2.0.0 升级到 2.1.0

后台「一键升级」现在是**全量替换 + 清理旧文件**：磁盘上多出来的旧文件与目录一律删除，
所以 1.x 残留的 `public/`、`admin.php` 不会再留在磁盘上冒充新结构。保留 `uploads/`、
`data/`、`storage/`、`backups/`、`config/config.php`、`config/installed.lock`、`.user.ini`。

若你是手动覆盖上传的，请自行确认旧目录已删：

```bash
cd /www/wwwroot/你的站点目录 && ls -la   # 确认无 public/ 与 admin.php 残留
```

### 从 1.x 升级到 2.0.0（历史说明）

- **运行目录改为项目根**（含 `index.php` 的那一层）：`public/` 已整体删除，`themes/`、`static/` 上移到根，沿用旧 nginx 配置会导致主题与静态资源 404；
- **入口合并为单一 `index.php`**：`admin.php` 已移除，`/admin` 无需额外配置；
- **错误页主题化**：nginx 的 `error_page` 指向预渲染静态页 `static/{404,403,50x}.html`，改主题样式后重跑 `php bin/build_50x.php`；
- `config/`、`storage/`、`uploads/`、`backups/`、`data/` 升级不会被覆盖。

</details>

## 开源

原 Flask 版作者：[Contribuv/infowe_blog](https://github.com/Contribuv/infowe_blog)
PHP 版：[Contribuv/haozi_blog](https://github.com/Contribuv/haozi_blog) · 镜像 [infowe/haozi_blog](https://gitee.com/infowe/haozi_blog)

模板引擎 `core/Template/` 为本项目自研实现；`core/lib/cacert.pem` 来自 curl 项目（MIT）；
`fonts/wqy-microhei.ttc` 为文泉驿微米黑（Apache-2.0）；IP 归属地解析数据来自 ip2region（Apache-2.0）。
