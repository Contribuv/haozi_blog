# Haozi Blog（PHP 版）

> **infowe.site 博客系统的 PHP 实现：单管理员 · 轻量 · 开箱即用 · 自带图形化安装向导 · 无需 Python 环境**

原 Flask 版（SQLite）已停止更新，本仓库为 1:1 重构版本：前端交互与后台功能保持一致，存储层改为 MySQL。

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4) ![MySQL](https://img.shields.io/badge/MySQL-5.7%20%7C%208.0-4479A1) ![version](https://img.shields.io/badge/version-2.7.5-blue) ![license](https://img.shields.io/badge/license-MIT-green)

---

## 🚀 快速导航

| [✨ 功能特性](#-功能特性) | [📦 环境要求](#-环境要求) | [🗂️ 目录结构](#️-目录结构) |
| --- | --- | --- |
| [🔧 安装向导](#-安装向导) | [🌐 伪静态与服务器配置](#-伪静态与服务器配置) | [📝 评论表情面板](#-评论表情面板) |
| [💻 本地开发](#-本地开发) | [⬆️ 升级](#️-升级) | [❓ 常见问题](#-常见问题) |
| [📋 版本](#-版本) | [💚 开源](#-开源) | |

---

## ✨ 功能特性

### 🎨 前台

| 模块 | 说明 |
| --- | --- |
| 📄 **文章** | Markdown 写作、分类、标签、精选、阅读时长、访问计数、上下篇导航 |
| 🚦 **文章状态** | `published`（已发布）/ `draft`（草稿）/ `hidden`（隐藏）——隐藏文章仅凭 URL 可访问，不出现在首页、列表、标签页、年份归档与上下篇导航 |
| 🔍 **检索** | 全文搜索、标签聚合页、分类筛选、分页 |
| 📡 **订阅** | `/feed.xml` RSS 输出 |
| 💬 **评论** | 可开关、嵌套回复、审核制、通知邮件、IP 归属地离线解析（内置 ip2region，零外部请求）、头像代理、4 组 61 个精选表情面板 |
| 🗃️ **项目展示** | GitHub / Gitee 仓库信息同步、语言占比统计、单项目评论区 |
| 🔗 **友链** | 申请提交 + 后台审核、批量管理 |
| 🕐 **时间线 / 关于页 / 站点状态监控** | 探活结果可后台配置 |
| 🖼️ **其他** | 分享卡片缩略图（`og:image` 自动生成）、站内 404 页、站点备案信息展示 |

### ⚙️ 后台

- 📊 **仪表盘** — 文章 / 评论 / 友链统计
- 📝 **文章管理** — 新建、编辑、预览、删除、批量操作
- 🏷️ **分类 / 标签 / 时间线 / 友链 / 评论** — 均支持批量操作
- 🗃️ **项目管理** — 单条或全量同步 GitHub 数据、同步状态查询
- ✅ **评论审核** — 审核与批量清理
- 📤 **媒体上传** — 图片 / 媒体 / 文件
- 🧹 **孤儿文件清理** — 缩略图预览、搜索、排序、批量删除
- 🛠️ **站点设置** — 站点信息、导航开关、评论开关、统计代码注入、图片水印、SMTP 测试
- 🎭 **主题切换** — 多套主题目录，开箱内置 `tech`
- 💾 **数据备份与迁移** — 数据库备份、SQL / SQLite 文件导入、JSON / Markdown 导出
- ⬆️ **在线升级** — 检测并一键升级，GitHub Releases 主源 + Gitee 镜像回退

### 🔒 安全

- 🧍 **单管理员硬约束** — 数据库触发器兜底 + 程序层保证
- 🛡️ **登录防爆破** — 基于 IP 的失败计数、递增延迟、15 分钟锁定、验证码
- 🔐 **OTP 双重验证** — RFC 6238 TOTP，兼容 Google Authenticator / 1Password / Authy
- 📧 **邮箱找回密码** — 6 位验证码，10 分钟有效、60 秒限频
- 🚫 **CSRF 防护** — 全部 POST 请求在根 `index.php` 统一校验 Origin / Referer 同源，跨站提交一律返回 403（`themes/<主题>/403.html`）；配合 Session Cookie 的 `SameSite=Lax` 形成两层防护，后台表单无需手工插入 token
- 🌐 **反代 XFF 伪造防护** — 仅当 `REMOTE_ADDR` 本身是回环 / 私网地址时才采信 `X-Forwarded-For`，且逐段校验格式
- 🧱 **其他** — 安全响应头、CSP、Session Cookie 加固、Markdown 链接协议过滤（含 GitHub README）

---

## 📦 环境要求

| 项目 | 要求 | 说明 |
| --- | --- | --- |
| 🐘 **PHP** | 8.0 及以上 | 推荐 8.2 / 8.3 |
| 🐬 **MySQL** | 5.7 或 8.0 | 字符集 `utf8mb4` |
| 🧩 **PHP 扩展** | `pdo_mysql`、`mbstring`、`curl`、`gd` | 安装向导第一步自动检测 |
| 🌐 **Web 服务器** | Nginx / Apache | 运行目录直接指向**项目根目录** |

---

## 🗂️ 目录结构

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

> 💡 站点 URL 与磁盘路径一一对应：`/static/…` → `static/`，`/themes/…` → `themes/`。其余非真实文件的请求一律交给根 `index.php`。

> ⚠️ **安全前提**：网站运行目录就是项目根，因此 `config/config.php`（含数据库密码）、`core/`、`storage/`、`data/`、`backups/`、`bin/`、`plugins/`、`docs/`、`fonts/` 都在 Web 可见范围内。根目录 `.htaccess`（Apache）与下文 nginx 配置（Nginx）**必须保留其中的封锁规则**，否则源码与数据库密码可被直接下载。`themes/` 下的 `.html`（模板源码）与 `.json`（`info.json`）同样已在封锁之列，只放行 `theme.css` / `theme.js` / `preview.png`。

> 📌 `config/config.php`、`config/installed.lock`、`storage/`、`uploads/`、`backups/`、`data/`、`.toolchain/` 均已在 `.gitignore` 中排除，更新升级不会覆盖。

---

## 🔧 安装向导

1. 将项目上传到服务器，**把网站运行目录指向项目根目录**（即包含 `index.php` 的那一层）；Nginx 用户直接套用下文「伪静态与服务器配置」中的完整示例；
2. 浏览器访问站点首页，自动进入安装向导；
3. 按向导四步完成；
4. 安装完成后自动发布一封《致使用者的一封信》作为示例文章。

> 🎯 后台入口：`/admin`（使用安装时填写的账号密码登录）。

### 向导四步

**🧪 第 1 步 · 环境检测**

逐项检查并显示结果，任一项不通过则无法进入下一步：

| 检查项 | 说明 |
| --- | --- |
| PHP 版本 ≥ 8.0 | 显示当前 PHP 版本 |
| `pdo_mysql` | 数据库驱动，缺失则无法连接 MySQL |
| `mbstring` | 中文处理（截断、校验） |
| `curl` | 项目同步、水印下载等远程请求 |
| `gd` | 图片水印与缩略图 |
| `config` / `storage` / `storage/cache` 目录可写 | 缺失会自动创建 |

**🐬 第 2 步 · 数据库连接**

| 字段 | 说明 |
| --- | --- |
| 数据库主机 | 一般填 `127.0.0.1` 或数据库服务器地址 |
| 端口 | 默认 `3306` |
| 数据库名 | 仅允许字母、数字、下划线、短横线；**不存在且账号有建库权限时会自动创建** |
| 数据库用户名 | 建议使用仅授权单库的账号 |
| 数据库密码 | 仅写入 `config/config.php`，不回显 |

连接成功后向导会检查该库是否已有博客数据（有管理员账号即判定为已占用），引导改用空库。

**👤 第 3 步 · 管理员账号**

| 字段 | 校验 |
| --- | --- |
| 管理员用户名 | 3–30 个字符，不含空格 |
| 密码 | 至少 8 位 |
| 确认密码 | 需与上一步一致 |

**🏠 第 4 步 · 站点信息**

| 字段 | 说明 |
| --- | --- |
| 站点名称 | 显示在导航、页脚与浏览器标题 |
| 作者名 | 文章署名 |
| 联系邮箱 | 用于接收评论通知与密码找回邮件 |

提交后向导完成：建库（若需要）→ 建表 → 创建管理员 → 初始化默认设置 → 发布致谢文章 → 写入 `config/config.php` → 写入 `config/installed.lock`。

### 安装完成后

站点已生成运行时配置与安装标记，重复访问不会再进入向导。如需重装：删除 `config/installed.lock` 与 `config/config.php`，并清空数据库。

出于安全考虑，建议在 Nginx 中禁止访问安装向导（根 `index.php` 已内置 `installed.lock` 判断，删除 `install.php` 同样安全）：

```nginx
location = /install.php { return 404; }
```

### 手动安装（可选）

不想走向导时，复制 `config/config.sample.php` 为 `config/config.php` 并填写数据库信息，再执行建表脚本，然后访问站点（检测到已有管理员会自动补写安装标记）：

```bash
php bin/schema.php          # 建表（幂等）
php bin/migrate.php         # 结构迁移
```

---

## 🌐 伪静态与服务器配置

站点入口已合并为根目录的 `index.php`（前台 + 后台全部路由都在其中），因此**不再需要**把 `/admin` 前缀单独交给另一个 `admin.php`。只剩两件事要做：

1. 非真实文件的请求 → 交给 `index.php`
2. **封锁源码与数据目录** —— 运行目录就是项目根，不封锁等于把数据库密码敞开

### Nginx

宝塔面板：**网站 → 设置 → 伪静态**，粘贴以下内容（`fastcgi_pass` 的 socket 路径按实际 PHP 版本修改，宝塔一般位于 `/tmp/php-cgi-XX.sock`）。同一份内容也存于 [`config/nginx.conf.example`](config/nginx.conf.example)，可直接复制。

下面每一条围栏都有存在的理由：目录 / 模板 / 上传封锁挡的是「可被直接下载的密码与源码」，`location ^~ /avatar/` 挡的是「头像被静态后缀正则截胡」，理由见注意事项。

> ⚠️ **切勿自行追加「后缀黑名单」**，例如 `location ~* \.sql$ { deny all; }`：后台的备份下载走 `/admin/export/download/*.sql`，会被一并拦掉，表现为点「下载」返回 404。`backups/` 目录已由下面的目录级规则封锁，无需再按后缀拦。详见「常见问题 → 备份文件点下载返回 404」。

```nginx
# 敏感目录与文件：禁止 Web 访问（缺了这条 config/config.php 可被直接下载）
location ~ ^/(config|core|storage|data|backups|bin|plugins|docs|fonts)(/|$) { return 404; }
location ~ /\.            { return 404; }
location ^~ /.well-known/ { allow all; }
location ~ ^/themes/.+\.(html|json)$        { return 404; }
location ~ ^/uploads/(.*/)?\.               { return 404; }
location ~* ^/uploads/.*\.(php|phtml|phar)$ { return 404; }

# 伪静态：非真实文件 / 目录交给前端控制器
location / { try_files $uri $uri/ /index.php?$query_string; }

# 头像代理：必须 ^~（前缀优先，压过宝塔等外部 include 里按后缀的静态正则），
# 否则 /avatar/<key>/<size>.png 被当静态文件找盘 404、不进 PHP，头像全退默认
location ^~ /avatar/ { try_files $uri /index.php?$query_string; }

# 静态资源缓存（charset 不可省，否则直出的 css / js 中文乱码）
location ~* \.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf)$ {
    charset utf-8;
    charset_types application/javascript text/css;
    expires 30d;
    access_log off;
}

# 错误页：指向预渲染静态文件，不经过 PHP
error_page 404 /static/404.html;
error_page 403 /static/403.html;
error_page 500 502 503 504 /static/50x.html;

# PHP
location ~ \.php$ {
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
- **`error_page` 一律指向预渲染静态文件，绝不写 `/index.php`**。404/403/502 恰恰发生在 PHP 通道本身不可用的时候（文件没上传、fastcgi 挂了），此时让 nginx 内部再走一次 PHP 只会得到 nginx 原生页。静态页由 `php bin/build_50x.php` 从 `themes/<主题>/{404,403,502}.html` 预渲染并内联 theme.css，nginx 直接吐文件，不经过 PHP。改了主题样式后重新执行该脚本。
- **排查 404 是 nginx 原生页还是主题页**：先在项目根放一个探针 `echo '<?php echo "PHPOK";' > t.php`，访问 `https://你的域名/t.php`。返回 `PHPOK` 说明 PHP 通道正常，是文件没上传到位（1.x 升级后 `index.php` 在根、`public/` 已删）；返回 nginx 原生页才是 `location ~ \.php$` 没生效，用 `nginx -T 2>/dev/null | grep -n 'location\|fastcgi\|root '` 查实际配置。
- **不要追加「后缀黑名单」**（如 `location ~* \.sql$ { deny all; }`）：后台备份下载走 `/admin/export/download/*.sql`，被这类规则拦掉后表现为点「下载」返回 404。`backups/` 目录已由上面的目录级规则封锁，无需再按后缀拦。详见「常见问题 → 备份文件点下载返回 404」。
- **`location ^~ /avatar/` 不能删**（实测教训）：头像代理地址形如 `/avatar/<key>/<size>.png`，必须以 `.png` 结尾。nginx 中正则 location 优先于普通前缀 location，而「按后缀的静态资源规则」不止伪静态里那一段——**宝塔生成的 server 块或其它 include 常自带 `~* \.(png|jpg|…)$` 之类规则，正则按出现顺序先到先得，本项目改不到别处那段**。一旦被抢先匹配，nginx 就去磁盘找这个不存在的文件、返回 404，**请求根本不进 PHP**，表现为全站评论头像退化成默认头像、`storage/cache/avatars/` 永远为空（2.6.6 线上 bug 即此）。曾试过「干脆删掉静态资源 location、让 `/avatar/*.png` 落回 `try_files`」的简化方案，在本机可行、在宝塔环境失败——因为抢它的规则来自本文件之外。`^~` 是前缀优先匹配，命中后不再走任何正则，**与书写顺序、外部规则都无关**，是唯一稳妥的解，故保留静态资源 location 的同时必须留这一行。
- 修改后 `nginx -t` 检查语法，再 `nginx -s reload`。

### Nginx 完整示例（server 块，HTTPS 自行补 ssl 两行）

```nginx
server {
    listen 80;
    server_name infowe.site;
    root /www/wwwroot/www.infowe.site;
    index index.php;

    location ~ ^/(config|core|storage|data|backups|bin|plugins|docs|fonts)(/|$) { return 404; }
    location ~ /\.            { return 404; }
    location ^~ /.well-known/ { allow all; }
    location ~ ^/themes/.+\.(html|json)$        { return 404; }
    location ~ ^/uploads/(.*/)?\.               { return 404; }
    location ~* ^/uploads/.*\.(php|phtml|phar)$ { return 404; }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ^~ /avatar/ { try_files $uri /index.php?$query_string; }

    location ~* \.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf)$ {
        charset utf-8;
        charset_types application/javascript text/css;
        expires 30d;
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

项目根目录的 `.htaccess` 已内置全部规则（敏感目录封锁 + 主题模板封锁 + 伪静态），站点只需允许 `AllowOverride All`：

```apache
<Directory /www/wwwroot/www.infowe.site>
    AllowOverride All
</Directory>
```

规则内容见根目录 `.htaccess`，无需在面板里另写伪静态。

### 宝塔 open_basedir（重要）

网站运行目录就是项目根，因此内核与数据目录天然都在 open_basedir 白名单内，通常无需处理。

> 💡 宝塔把 open_basedir 这项叫**「防跨站攻击」**：它只限制 PHP 读文件的**路径范围**，越界时报 `open_basedir restriction` 警告 / 错误，**与页面 404、备份下载 404 无关**。若你的症状是点备份「下载」返回 404，见「常见问题 → 备份文件点下载返回 404」，不要为此关闭「防跨站攻击」。

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

验证：登录 SSH 执行 `cat /www/wwwroot/www.infowe.site/.user.ini`，应看到 `open_basedir=/www/wwwroot/www.infowe.site/:/tmp/`（**末尾不带 `/public/`**）。

---

## 📝 评论表情面板

评论区「写评论」工具条上的 🙂 按钮，点开是一个 Unicode 表情面板。模板里只有一个空容器（`_comments.html` 的 `#cmt-emoji-panel`），**全部表情与分组都由 `static/js/comments.js` 的 `EMOJI_GROUPS` 生成**，改表情只动这一处，不必碰模板。

**分组（4 组 / 共 61 个）**

| 组 | 数量 | 内容 |
| --- | --- | --- |
| 😀 笑脸 | 28 | 😀 😂 🥰 🤔 😭 一类表情脸 |
| 👍 手势 | 12 | 👍 👎 👏 🙏 ✌️ 🫡 等 |
| ❤️ 心与星 | 9 | ❤️ 💔 💕 ✨ ⭐ 💯 等 |
| 🎉 庆祝与符号 | 12 | 🎉 🎁 🔥 ✅ ❌ ⚠️ 🚀 等 |

**排版**

- 每组为「组标题 + 网格」：标题 `.cmt-emoji-title`（11px 次要色），下方 `.cmt-emoji-grid` 是 CSS Grid，`repeat(auto-fill, minmax(30px, 1fr))`，按面板宽度自适应列数，单元 30px 见方、`gap: 2px`，每格一个 `.cmt-emoji-item`。
- 组与组纵向堆叠，间距 8px；面板固定在按钮上方，宽度 `min(300px, 100vw - 56px)` 自适应窄屏，高度上限 220px，超出滚动。
- 两套主题各一份样式：`comments.css`（default 主题）与 `comments-tech.css`（tech 主题），类名一致，改样式两处都要改。

**交互**

- 点 🙂 开合面板，同步 `aria-expanded`。
- 点某个表情 → 插入正文 `textarea` 的当前光标处，插入后自动收起面板。
- 点面板以外区域也收起。

---

## 💻 本地开发

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

> ✅ `check_hidden` / `check_iploc` / `check_ip` 需要数据库连接；改动相关逻辑后先跑这三个再提交。

---

## ⬆️ 升级

- 后台「系统升级」页一键检查并升级（GitHub Releases 主源，超时或限流时自动切换 Gitee 镜像）；
- 或在服务器上 `git pull` 后重载 PHP / 清理模板缓存（`storage/cache/templates/`）。

升级只替换程序文件，`config/` 与 `storage/` 不会被覆盖。

---

## ❓ 常见问题

### 进入安装向导时报 open_basedir 错误

见上文「宝塔 open_basedir」。

### 安装时提示「未能创建单管理员守护触发器」

这是 MySQL 的独立限制，**不是建表失败**：开启 binlog 时，创建触发器需要全局 `SUPER` 权限，而数据库账号通常只被授予单库权限（`GRANT ALL ON 该库.*`）。

向导会自动跳过该触发器，单管理员约束改由程序层保证（后台不提供创建第二个账号的入口），不影响正常使用。

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

模板编译产物按文件 mtime 失效。若改动了内核模板引擎（`core/Template/`）而非模板文件本身，缓存不会自动重建，手动清空：

```bash
rm -rf storage/cache/templates/*
```

### 忘记管理员密码

通过后台登录页的「忘记密码」走邮箱找回；也可开启 OTP 后用恢复码。若两者都不可用，只能在数据库中直接重置 `users` 表的密码字段。

### 页面样式或资源 404

运行目录应指向**项目根**（含 `index.php` 的那一层）。Nginx 还需确认 `config`、`core` 等目录的 `deny all` 与 `themes/` 的模板封锁规则都未被误删。见上文「伪静态与服务器配置」。

### 备份文件点下载返回 404

后台备份的 SQL 落在站点根 `backups/` 目录，下载一律走 `/admin/export/download/<文件名>`（`ExportController::download()` 先做文件名安全化，文件不存在才 `abort404()`）。出现 404 时按此顺序排查：

1. **服务器 nginx 拦了 `.sql` 后缀**（最常见）：宝塔的「网站防火墙 / 安全加固」，或自行添加的 `location ~* \.sql$ { deny all; return 404; }`，会把备份下载一并拦掉，去掉该规则即可。正确做法是只封锁 `backups/` 目录（本项目的伪静态已含），而不是按后缀拦。确认命令：`nginx -T 2>/dev/null | grep -n 'sql'`。
2. **文件确实不存在**：备份目录被清理或迁移后，列表还显示旧记录。重新点「备份数据库」生成，或到服务器上核对 `ls -l /www/wwwroot/www.infowe.site/backups/`。
3. **前置 CDN / 反向代理的「文件下载拦截」**同样会返回 404，需在 CDN 侧放行 `.sql`。

> 与宝塔「防跨站攻击」（open_basedir）无关：那项只限制 PHP 读文件的路径范围，越界报的是 `open_basedir restriction` 警告/错误而非 404，别误关（见上文「宝塔 open_basedir」）。

### 想改 403 / 404 / 500 / 502 页面

编辑 `themes/<主题名>/403.html`、`404.html`、`500.html`、`502.html`，然后：

- **全部四个**都要执行 `php bin/build_50x.php` 重新生成 `static/{404,403,50x}.html`，因为 nginx 的 `error_page` 只读静态文件，读不到模板；
- `403` / `404` / `500` 的 PHP 侧渲染（应用内部主动 abort 时走这条路）还会带上站点名与导航，改完清空 `storage/cache/templates/*` 即生效。

`error_page` 必须指向静态文件，不能写 `/index.php`：404 / 403 / 502 恰恰发生在 PHP 通道本身不可用的时候（文件没上传、fastcgi 挂了），让 nginx 内部再走一次 PHP 只会退回 nginx 原生页。

`403` 是跨站请求被拦截时的页面（见下文「安全」的 CSRF 说明），由根 `index.php` 渲染，不需要改 nginx。

---

## 📋 版本

当前版本：**2.7.5**

### v2.7.5

**修复**

- **「取消升级」后刷新页面又回到「升级中」**：取消状态改由服务端记录（进度接口透出 `cancel_requested` / `cancel_at`），刷新后会正确显示「正在取消…」或「已中断」，不再把升级浮窗重新弹出来。
- **取消无响应死循环**：已请求取消、但进程心跳在宽限期（45 秒）内毫无推进时，判定为进程已卡死/被杀，`取消` 与 `清除升级状态` 会立即把状态收敛为终态，不再出现「点了没反应、刷新又回来」的死循环。
- **升级下载源排序**：首选平台（GitHub）处于熔断窗口时不再被排到最前，改由可用的 Gitee 优先，避免对不通的直连与慢速镜像白等连接超时（此前「长时间卡住看似失败」的根因）。

**优化**

- **评论图片浮窗支持触屏手势**：双指捏合缩放（1–5 倍）、放大后单指拖动查看、双击快速放大/还原；点空白处 / 关闭按钮 / 按 Esc 关闭，关闭后自动复位缩放与位移。
- **阅读进度条覆盖更多页面**：除文章页、项目页（锚定正文）外，首页、文章列表、标签、搜索等长页面也会显示顶部滚动进度条（锚定主内容区）；内容不足一屏的短页面进度恒为 0、不可见。

**升级说明**

- 本次无需改动数据库结构与 Nginx 配置，覆盖文件即可；静态错误页（403/404/50x）已同步重建。

<details>
<summary><b>📜 历史版本日志（点击展开）</b></summary>

### v2.7.4

**优化**

- **移动端字号整体收敛一档（≤768px，桌面端完全不变）**：手机上文字普遍偏大，正文 16px 下 375px 宽屏一行仅约 21 个中文字。本版把移动端正文降到 **14px**（行高 1.6 不变），一行约 24.5 字；同步收敛：文章 / 项目文档正文标题 24 → 20px、页面标题 24 → 20px、首页 Hero 标题 32 → 26px、Hero 描述 16 → 14px、列表标题 18 → 16px、列表摘要 15 → 14px、正文标题梯度 20/18/17/16/15.5/15 → 18/16/15/14/13.5/13、面包屑与 Logo 18 → 17px、抽屉菜单 16 → 15px；评论区字号本就更小（13.5px），未再下调。
- **项目详情页补上阅读进度条**：此前只有文章页有，项目页以「项目文档」正文为锚点补齐，滚动到文档底部即满格；进度条脚本从文章页内联搬到主题公共文件 `theme.js`，两页共用一份逻辑（锚点用 `data-reading-target` 指定）。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换程序文件即可；前台静态资源缓存串取自文件指纹，升级后自动刷新，静态错误页已同步重建。

### v2.7.3

**修复**

- **深色主题按钮对比度**：「更多文章」「提交申请」等主按钮此前在深色主题下是亮天蓝底（`#58a6ff`）+ 白字，对比度仅约 **2.4:1**，白字发虚；现改为深色字（约 **8.9:1**）。悬停反馈同步修正：浅色主题改为加深、深色主题改为提亮，两端都朝「对比度变好」的方向反馈，不再是无脑 `brightness(1.1)` 提亮。

**优化**

- **全站禁用系统点按高亮块**：Android Chrome 点按链接/按钮时默认出现的灰/蓝半透明方块已去除（`-webkit-tap-highlight-color: transparent`，在 `html` 声明一次即覆盖所有可点元素）。
- **桌面 Hover 样式收进 `@media (hover: hover) and (pointer: fine)`**：触摸设备不再出现「点一下残留 hover 态」的粘滞高亮，指向设备上悬停反馈保持不变。
- **可访问性**：顶部 Logo 补充 `aria-label` / `title`（返回首页），导航与抽屉里的首页链接统一改用 `url_for('index')`，避免站点挂在子路径时链接失效。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可；前台静态资源缓存串取自 `Context::VERSION`，升级后 URL 自动变化，无需额外清缓存。

### v2.7.2

**修复**

- **文章页 / 项目页双标题**：正文一级标题此前与页面标题字号太接近（移动端几乎一样大），且 markdown 自带的标题下边框没有被覆盖，「页面标题 + 正文大标题」两条大标题、几条横线挤在一起。现在正文一级标题明显小于页面标题、且不再带下边框；项目页 README 的标题此前按 markdown 默认 `2em`（约 32px）渲染、比页面标题还大，已与文章页统一梯度，README 里与项目名重复的首个标题降级成一行灰色说明。
- **评论锚点定位**：从后台评论管理点击 `/projects/1#comment-68` 这类链接无法定位到评论——被折叠的评论用 `hidden` 实现，隐藏元素没有布局盒子，跳转只能停在错误位置。现在带锚点访问时会自动展开命中的折叠组，再平滑滚动并短暂高亮目标。

**优化**

- **内页整体紧凑化**：正文行高 1.75 → **1.65**，段距 20px（移动 18px）→ **13px**，标题上下间距 28/14 → **20/9px**（移动 22/12 → 16/8px）；列表、引用、代码块、表格、分隔线、图片等块间距同步收紧，页面留白与「上一篇/下一篇」「相关推荐」等区块间距一并收紧。实测整页高度**减少约 11%**、正文区约**紧凑 20%**。

### v2.7.1

**修复**

- **升级下载「卡住无反馈」**：下载依次尝试 GitHub 直连、加速镜像、Gitee，现在**每切换一个源都会上报进度**（如「正在下载源码包（2/3：镜像）…」），不再长时间毫无反馈。
- **下载超时收紧**：单个下载源超时 300 秒 → **120 秒**（国内机器直连 GitHub/codeload 常「连上了却不返回数据」，旧值让每个源白等 5 分钟）；「进程无响应」判定阈值 600 秒 → **300 秒**，真卡住时能更快提示「已中断」。
- **失败原因逐源汇总**：所有下载源都失败时，按源汇总具体原因（如「GitHub：Connection timed out；镜像：HTTP 403」），方便判断卡在哪一跳。

**优化**

- **评论图片改缩略小图**：最大 160px、多图自动并排，点击后在浮层里查看原图；浮层带右上角关闭按钮，点空白处或按 Esc 可关闭；移动端留白收窄、关闭按钮热区放大到 44px。

### v2.7.0

**新增**

- **评论区图片上传**：评论框可直接上传图片（png / jpg / jpeg / gif / webp，单图 ≤ 5MB），上传后在输入框下方显示缩略图，单条最多 6 张，随评论一起送审；后台「设置」提供总开关可一键关闭。
- **评论敏感词过滤**：后台可维护词库（一行一词，`#` 开头为注释）；开启后输入即命中即变等长星号，禁用 JS / 直连提交时后端同样兜底过滤。

**修复**

- **评论「网址」协议处理**：无协议时自动补全 `https://`，输入时可下拉选择 http / https；`javascript:`、`mailto:` 等非 http(s) 协议一律拒绝。
- **评论邮箱 QQ 号补全**：填纯数字 QQ 号（5–12 位）时自动补全为 `@qq.com`。

**优化**

- **升级双平台公平竞争**：GitHub 与 Gitee 同时检测、取版本更高者；两平台版本相同时轮流下载，不再固定只走 GitHub；某个平台连不上 / 超时 / 限流会自动切换另一个。
- **升级配置项自动合并**：升级新增的配置项对老站点自动生效，`config.php` 与示例默认值深合并，无需手动补字段（已显式配置的键不受影响）。

**其他**

- 评论邮箱恢复为明文存储。

### v2.6.17

**说明**

- 本版无功能与代码逻辑变更，仅推进版本号，用于在线上实测验证 v2.6.16 的升级下载逻辑（检测来源联动下载首选平台、Gitee 打包中间页重试、失败原因汇总）。

**升级说明**

- 无数据库与 Nginx 配置变更，直接替换代码即可。

### v2.6.16

**修复**

- **检测与下载联动**：版本检测已知道更新来自哪个平台（`source`），但下载仍从 GitHub 直连开始盲试——Gitee 独有的新 tag 会先白耗 GitHub 直连超时 + 镜像 404 两跳，还顺带误标 GitHub 熔断。现在检测出的来源平台（github / gitee）作为首选下载源一路传导到 worker（`doUpgrade($tag, $preferredSource)`），其余源保留完整 fallback 链兜底。
- **Gitee 打包中间页无重试**：Gitee 对刚发布的冷门 tag 归档请求会现打包，返回 HTTP 200 + HTML「正在打包」中间页，原代码判为非 zip 后直接整体升级失败（这正是「正在下载源码包…→ 下载失败」的典型场景）。现在 Gitee 源等 3 秒重试最多 3 次，重试前检查取消标记。

**优化**

- **失败原因汇总**：所有下载源均失败时，错误信息汇总每一跳的具体原因（GitHub / 镜像 / Gitee 各自卡在哪），前端浮窗直接可见，不再只抛最后一个错误。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可；前台静态资源缓存串取自 `Context::VERSION`，升级后 URL 自动变化，无需额外清缓存。

### v2.6.15

**修复**

- **评论昵称悬停下划线**：评论区带超链接的昵称悬停时出现下划线，`comments.css` 与 `comments-tech.css` 同步去掉 `a.cmt-author:hover` 与 `.cmt-item:has(.cmt-avatar-link:hover) a.cmt-author` 两处的 `text-decoration:underline`，悬停仅保留变强调色的反馈。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可；前台静态资源缓存串取自 `Context::VERSION`，升级后 URL 自动变化，无需额外清缓存。

### v2.6.14

**修复**

- **取消必达终态**：`Upgrade::cancel()` 原先只写一个取消标记，进程若已被系统杀掉，标记永远没人消费，前端就一直停在「正在取消…」。现在该接口先看进度心跳：进程已死则就地收敛为「已取消」并清锁；进程活着才写标记等检查点。前端另加 45 秒兜底判据（以「心跳是否仍在推进」为准，正在备份时不会误判）。
- **取消后反复回到升级中页面**：进度文件残留 `running` 时，`resumeIfRunning()` 只看 `state`、不校验心跳新鲜度，每次进页面都弹浮窗、刷新后又弹回。服务端 `progress()` 现给出 `stale` 字段（与 `isRunning()` 同一套 `LOCK_TTL` 判据），前端据此转入「上次升级已中断」态，且不再自动刷新。
- **假启动**：`spawnWorker()` 改用 `ini_get('disable_functions')` 显式判断 `exec` 是否被禁——`function_exists('exec')` 对被禁函数仍返回 `true`，原写法会回 `ok:true` 而后台进程从未启动，前端一直干等。
- **取消竞态**：移除 `doUpgrade()` 里多余的那次 `clearCancel()`。同步回退模式下，用户在浮窗出现后立刻点取消写下的标记会被它抹掉，取消彻底失效。
- **锁与心跳对齐**：`writeProgress()` 顺带刷新升级锁 mtime，避免长时间升级被后来者误判为过期锁、两个升级进程并发覆盖代码；`run()` 在启动前显式清掉残留锁，防止被刷新后的残留锁挡住新 worker。

**新增**

- **清除升级状态接口**：`POST /admin/upgrade/reset`（`Upgrade::resetState()`）一次清掉进度、取消标记与升级锁，把页面恢复成可重新升级；仅在没有新鲜的进行中进程时允许执行。页面上不新增元素——浮窗里原有的按钮在中断态就地变成「清除升级状态」。

**优化**

- 升级期间禁用「重新检测」与「一键升级」按钮；版本结果行补上缓存时间，解释「来源 GitHub」与更新源实时显示 GitHub 不可用并存的现象。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可；前台静态资源缓存串取自 `Context::VERSION`，升级后 URL 自动变化，无需额外清缓存。
- 若线上正卡在「升级中、取消不掉」，可先删除 `data/upgrade_progress.json`、`data/upgrade_cancel`、`upgrade.lock` 三处残留状态后重新升级；升级到本版后页面会自行识别并给出「清除升级状态」入口。

### v2.6.13

**新增**

- 评论者填写网址时，其昵称与头像一样渲染为超链接（`a.cmt-author`，`target="_blank"` + `rel="noopener nofollow"`，与头像外链行为一致）；未填网址则保持纯文本 `span`。改的是公共片段 `core/view/shared/_comments.html`，两套主题同时生效。

**优化**

- **评论头像悬停反馈增强**（`comments.css` 与 `comments-tech.css` 同步修改）：高亮光环补 `transition` 由硬切改为渐显；悬停时叠加 `transform:scale(1.06)` 轻微放大（避开重排）；用 `:has()` 让悬停头像时昵称同步变为强调色 + 下划线，把「头像 / 昵称」两个链接在视觉上绑成一组，旧浏览器自动降级为仅头像光环；`prefers-reduced-motion:reduce` 下仅保留静态光环。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可；前台静态资源缓存串取自 `Context::VERSION`，升级后 URL 自动变化，无需额外清缓存。

### v2.6.12

**修复**

- **后台升级只认 GitHub 源**：`Upgrade::checkLatestVersion()` 原先按 `['github','gitee']` 顺序查询且命中即止——GitHub 只要返回结果（哪怕版本更旧）就 `break`，从不比较两平台版本高低，导致 Gitee 上的更高版本永远检测不到；`probeSources()` 亦有同源缺陷（两平台都探测，但缓存由后探测者无条件覆盖，可能把高版本写成低版本）。
- **按版本号取最高者**：新增 `Upgrade::bestOfSources()`——两个平台各查一次，按 `version_compare` 逐段比较取最高者；`checkLatestVersion()` 与 `probeSources()` 均改为调用它，任一源不通都不影响另一源的结果。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可；前台静态资源缓存串取自 `Context::VERSION`，升级后 URL 自动变化，无需额外清缓存。

### v2.6.11

**修复**

- **评论区三处样式问题**（`comments.css` 与 `comments-tech.css` 同步修改）：带外链的评论头像悬停时高亮光环被 flex `stretch` 撑成椭圆，改 `align-self:flex-start` 恢复正圆；移动端点按「写评论」出现系统默认蓝色高亮块，评论容器统一设 `-webkit-tap-highlight-color:transparent`；移动端楼中楼回复区丢失缩进，恢复 `var(--indent)` 左缩进（移动端 46px）。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可；前台静态资源缓存串取自 `Context::VERSION`，升级后 URL 自动变化，无需额外清缓存。

### v2.6.10

**说明**

- 无功能变更，仅进行常规代码整理和文档更新。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可。
- 如使用反向代理，请在 `config/config.php` 中补全 `trusted_proxies` 配置。

### v2.6.9

**说明**

- 无新功能，仅修复已知小问题。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，直接替换代码即可。
- 如使用反向代理，请在 `config/config.php` 中补全 `trusted_proxies` 配置。

### v2.6.8

**安全**

- **加固客户端 IP 解析：按可信代理列表采信 XFF**。根因：`Request::ip()` 原先只判断「`REMOTE_ADDR` 是否私网」就认定为反代之后并采信 `X-Forwarded-For` / `X-Real-IP`，攻击者直连时也能伪造这两个头，把基于 IP 的登录失败计数与临时锁定的键换成任意值，从而绕过防爆破。现在改为按可信代理列表判定——仅当 `REMOTE_ADDR` 命中新增配置项 `trusted_proxies` 时才解析代理头，并逐段校验为合法 IP 后才返回；未配置该列表时一律使用 `REMOTE_ADDR`。
- `config/config.sample.php` 新增 `trusted_proxies` 段，并逐项补全中文注释。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更。若站点部署在反向代理（nginx/CDN）之后，请在 `config/config.php` 补上 `'trusted_proxies' => ['代理机 IP'],`，否则客户端真实 IP 会记为代理地址、防爆破粒度变粗；直连部署无需改动。

### v2.6.7

**修复**

- **Nginx 下评论头像退化成默认头像**。根因：头像代理地址形如 `/avatar/<key>/<size>.png`，以 `.png` 结尾，nginx 中正则 location 优先于普通前缀 location，而「按后缀的静态资源规则」不止伪静态里那一段——宝塔生成的 server 块或其它 include 常自带 `~* \.(png|jpg|…)$` 之类规则，`/avatar/*.png` 于是被当成静态文件去磁盘找、找不到即 404，请求根本不进 PHP，前端只能回退默认头像；Apache 与内置服务器没有这类规则，故本地正常、线上异常。修复：新增 `location ^~ /avatar/ { try_files $uri /index.php?$query_string; }`——`^~` 是前缀优先匹配，命中后不再走任何正则，与书写顺序、外部 include 都无关。
- uploads 封锁改用两行正则 `~ ^/uploads/(.*/)?\.` 与 `~* ^/uploads/.*\.(php|phtml|phar)$`，不再需要 `^~ /uploads/` 前缀块；其 `expires` 与防盗链随之下线，需要时自行补 `valid_referers`。

**升级说明**

- 除版本号外，本次不涉及 PHP 逻辑，主要改 Nginx 配置样例与文档。请把宝塔「伪静态」整段替换为最新的 [`config/nginx.conf.example`](config/nginx.conf.example)，再 `nginx -t && nginx -s reload`。修复后首次访问仍是默认头像（前台只读磁盘缓存、未命中即 404，响应后异步预热），刷新或再访问一次即为真实头像。

### v2.6.6

**新增**

- 评论区新增精选 Unicode 表情面板（4 组 61 个），选中后自动关闭面板。

**优化**

- 评论输入纯数字 QQ 号自动补全 `@qq.com`、显示 QQ 头像预览（首访未命中自动重试 2 次）。
- 移动端视口下评论表单改为纵向排布，自适应布局优化。

**说明**

- 未对昵称加链接（避免误触），头像已承载网址跳转；CSS 版本戳随发版更新以刷新浏览器缓存。

### v2.6.5

**优化**

- **移动端目录框移至文章/项目开头**：移动端（<1400px）访问文章页与项目页时，目录框此前落在正文、页脚之后，现移入正文之前——文章页置于标题下方，项目页置于标题/简介下方、项目文档之前；窄屏上下间距调为 24px。宽屏（≥1400px）目录悬浮面板行为不变，仍固定于容器右侧。

### v2.6.4

**修复**

- **后台隐藏元素误显示**：`/admin/settings` 的「已启用信息区」、`/admin/projects` 的同步进度条在未触发时仍显示——`.hidden` 工具类被同特异性、后定义的组件规则（均声明 `display: flex`）覆盖，现移至 admin.css 末尾靠后定义取胜；不用 `!important`，以免压死 JS 直接设置的内联 `display`。
- **`/admin/projects` 控制台报错 `update is not defined`**：严格模式下 `function update()` 声明在 `if (form) { … }` 块内、块外不可见，初始化调用改置于块内。
- 补全 `themes/tech/theme.css` 中 `.tech-related-card-title` 缺少的 `line-clamp` 标准属性，与同文件另两处写法保持一致。

### v2.6.3

**修复**

- **博主评论头像与后台设置不一致**：评论区的「博主」头像此前固定按联系邮箱生成，与后台「设置」中上传的头像对不上；现统一为后台上传头像存在时优先使用，缺失时才回退 QQ/Cravatar。新增 `Avatar::adminUrl()` 收敛判定逻辑，文章页与项目页的博主身份条、评论区、后台评论管理共用同一实现。

### v2.6.2

**优化**

- **上一篇 / 下一篇分页器**：方向箭头由字符（`←` / `→`）改为内联 SVG（字符箭头在部分字体栈下会被回退渲染成彩色 emoji）；首篇 / 末篇文章补同宽同高的虚线占位，栅格不再塌半边；窄屏（≤768px）降为单列堆叠。
- **相关推荐**：展示条数由 3 篇增至 4 篇恰好铺满一行，网格改 `repeat(4, minmax(0, 1fr))` 防长标题撑破轨道；窄屏降为 2 列两行。
- **底部操作区**：许可文字（CC BY-NC-SA 4.0）移入操作按钮同排并靠右对齐；窄屏折行后恢复左对齐。

**说明**

- 纯样式与模板改动，无数据库结构变更。

### v2.6.1

**优化**

- **评论区改版**：一律「每条根评论一张卡」，楼中楼结构不变，评论表单独立成一张卡；去掉多层相邻底色叠出来的「补丁感」——卡面不再铺底色、只留 1px 描边成形，次级区块统一用一层半透明 tint 区分；回复区去底色与内距，仅靠缩进与头像尺度分层；作者行改为时间与 IP 属地随昵称同行、弱化为 11px 小字，属地补「IP属地：」前缀；卡片悬停时描边提亮。
- **目录面板（文章页 / 项目页）**：面板高度由 `calc(100vh - 160px)` 改为 `60vh` 封顶，矮视口下不再顶到视口底部；面板内滚动条收细为 6px，Firefox 走 `scrollbar-width: thin` 兜底。

**说明**

- 纯样式改动，无数据库结构变更。

### v2.6.0

**新增**

- **项目详情页目录侧栏**：项目内页新增与文章页一致的 TOC 目录——宽屏（≥1400px）固定在正文右侧常驻，窄屏折叠为「目录」框可手动展开；目录直接解析后台同步期已缓存的 README 标题生成，前台零网络请求；锚点全部命中正文标题，跳转后不会被顶部吸顶导航遮挡。

**优化**

- **后台主动发现更新**：更新横幅改为三状态（发现新版本 / 已是最新 / 暂时未知）；缓存缺失或已过期时页面加载后自动补检一次；新增「检查更新」按钮可手动强制探测。仍保持「渲染期零网络」，探测在响应结束后异步进行。
- 文章与项目页共用同一套目录生成逻辑（内部重构，输出不变）。

**升级说明**

- 无数据库结构变更，无需重新同步项目。

### v2.5.0

**优化**

- **主题资源缓存版本**：`theme.css` / `theme.js` 原先以程序版本号作缓存串，主题文件改动后 URL 不变、被长缓存挡住；现改为取主题文件最新修改时间作版本串（新增全局变量 `theme_asset_ver`），文件一改 URL 立即变化，自然绕开强缓存。
- **首页 Hero 改版**：站点名不再作页面大标题，改用 `sr-only` 的 `h1` 保留语义——首页为终端窗口 Hero，标签 / 搜索结果页为居中标题 Hero，并补一行「文章 / 标签 / 分类」统计；散落的内联样式改为类名承载。
- **文章页**：正文容器与导航对齐为 1000px；目录在 ≥1400px 悬浮于右侧、窄屏为内联折叠框，补锚点 `scroll-margin` 防被 sticky 导航遮挡；新增顶部阅读进度条，随滚动填充。
- **后台**：`admin.css` 新增工具类（`hidden` / `d-inline` / 列宽 `w-*` / 链接复位 / 验证码等宽输入等），状态页内联 `<style>` 全部下沉到 `admin.css`，多个后台模板的内联样式随之改为类名。

**修复**

- 「复制链接」的轻提示一直不显示：模板调用的是从未定义的 `window.techToast.show()`（实际接口是 `window.toast()`），已改正。
- 代码块「复制」在非 HTTPS（非安全上下文）下静默失效：补 `execCommand` 降级，文章页与项目页统一。
- 图片灯箱：打开时暂停正文中正在播放的视频（避免双声道），切换图片时暂停上一个媒体，灯箱内视频补 `muted` / `playsinline` 使自动播放生效。
- `og:image` / `twitter:image` 为绝对地址时被错误拼上站点根，现仅在站内相对路径时前置站点根。

**升级说明**

- 无数据库结构变更，无需重新同步项目。主题资源已改走 mtime 缓存串，改版后 URL 自动变化，无需手动清缓存。

### v2.4.1

**修复**

- **项目页 README 的 shields.io 徽章全部不显示**：外链图片走站内代理 `/readme-img`（前台只读磁盘缓存、绝不联网，缓存由后台同步期预热写入），但 `img.shields.io` 在国内连通性极差，逐张预热常整体失败、前台又不自愈。现改为对 `shields.io` 的 `/badge/` 地址就地按 URL 规则生成同款 SVG（左灰右彩、圆角、11px Verdana，命名色与 3/6 位 hex 均支持），零外网依赖、必定可见；其余外链图未命中时仍返回 404，改由响应后异步预热自愈，与头像代理同一口径。

**升级说明**

- 只改 `core/Controller/front/AssetController.php` 一个文件，无数据库与 Nginx 配置变更，也无需重新同步项目。

### v2.4.0

**新增**

- **附件链接按扩展名显示专属前缀图标**（编辑器与前台文章统一显示）：`static/icons/` 新增 19 枚专属 svg 图标（zip / rar / 7z / tar / gz / bz2 / pdf / doc / docx / xls / xlsx / ppt / pptx / csv / txt / md / py / js / json），风格与既有 `file` 线性图标一致；`markdown-body.css` 由类别映射改为逐扩展名一一对应，`::before` 伪元素以 `mask` 渲染、图标随链接色；`admin.css` 新增 `.vditor-reset` 同款规则，Vditor SV 分屏预览右栏同样显示；主题 `theme.css` 中附件链接的后置「⬇」标记删除。

**说明**

- 显示效果即「图标 + 文件名」（如 `📄 Test.zip`）。Vditor 的 IR 即时渲染模式（仅移动端）里链接存于文本节点，CSS 无从匹配，暂不显示图标。本版无数据库结构与 Nginx 配置变更；`markdown-body.css` 走版本号缓存，升级后自动刷新。

### v2.3.2

**修复**

- 后台更新提醒的一处类型错误：版本缓存中的 `info['version']` 是 `parseVersion()` 解析后的数组 `[int,int,int]`，上一版误按字符串做 `(string)` 转换——既触发 `Warning: Array to string conversion`，转换得到的 `'Array'` 又使解析失败、新版判断恒为 false。现改用现成数组字段并以 `is_array` 兜底，警告消除、提醒恢复生效。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更。

### v2.3.1

**修复**

- 后台仪表盘（及所有后台页面）此前不显示「发现新版本」提醒：模板条件依赖的 `upgrade_available` / `upgrade_check` 被写死为 `false` / `null` 且全项目无人填充，条件恒假、横幅永不出现。现于后台模板渲染时注入版本信息（只读 `data/upgrade_cache.json`，渲染期零网络）；平台状态过期时挂「响应后异步探测」钩子，下次进后台即可见提醒。

**升级说明**

- 首次进入后台若版本缓存为空或过期，需刷新一次才显示提醒（探测在响应发出后完成）。本版无数据库结构与 Nginx 配置变更。

### v2.3.0

本版以「任何时刻访客访问都不卡顿」为目标：前台 php-fpm 层面不再有任何外部依赖阻塞，并合并冗余 API 请求。

**新增**

- **README 外链图片站内代理（新路由 `/readme-img`）**：shields.io 徽章等外链图在渲染期重写为站内代理，前台只读磁盘缓存、绝不联网，被墙图源不再导致项目详情页整页卡死。

**优化**

- **README 代理后台预热**：项目同步期预先下载代理图片入缓存（带 5 秒总预算），前台零外部依赖。
- **头像代理「前台只读 + 响应后自愈预热」**：未命中缓存时前台直接 404，响应结束后异步预热；图源故障时写 1 小时失败标记，不再成片占用 php-fpm worker。
- **渲染前释放 session 锁**：`session_write_close()` 提前到渲染开始前，管理员同步轮询与后台多标签操作不再互相串行阻塞。
- **项目同步 GitHub API 三次并一次**：合并同步链路中重复的 repos 请求（`Project::save()` 支持复用预取数据），同步速度约提升 2/3，限流风险同比例下降。
- **RSS 缓存头**：输出加 `Cache-Control: max-age=600`，订阅器 10 分钟内不重复抓取。
- **nginx 示例补 gzip**：文本类资源传输体积降约 70%（图片类排除，防白耗 CPU）。

**升级说明**

- 升级后请后台手动「同步」一次项目，使既有 README 缓存重新生成（带代理地址与预热）；服务器需把 nginx 示例新增的 gzip 段同步到伪静态并 reload。无数据库结构变更。

### v2.2.6

本轮为安全与健壮性修复，涉及数据正确性、并发安全、XSS 与 CSRF 加固。

**安全**

- **升级说明 XSS（高）**：升级页的 Release 说明由 GitHub 拉取，此前经正则净化后以 HTML 渲染，正则可被实体编码绕过。现改为服务端 `strip_tags` 输出纯文本，前端统一转义，彻底消除注入面。
- **项目 README XSS（高）**：第三方仓库 README 中的原始 HTML 此前仅过滤字面 `javascript:`，现新增 `sanitizeExternalHtml` 整块剥离危险标签、移除事件属性并对伪协议做实体解码兜底。
- **CSRF 收紧（中）**：Origin 与 Referer 同时缺失时不再放行，视为跨站直接拒；CLI 仍放行。
- **会话 Cookie Secure 自动判定（低）**：HTTPS 请求下 `secure` 自动置 `true`，避免明文信道泄露会话；配置项仅作显式覆盖。
- **全局安全响应头（低）**：新增 `X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Referrer-Policy: strict-origin-when-cross-origin`。

**修复**

- **备份写入失败不再假报成功（严重）**：磁盘满 / 无权限时 `file_put_contents` 返回 false，此前仍返回文件名，导致导入在无有效备份下覆盖数据。现检查返回值，失败抛异常并清理空文件。
- **插件异常不再拖垮整站（高）**：`Hook::emit()` 单个监听器抛异常现被捕获并 `error_log`，后续监听器继续执行，核心流程不受影响。
- **项目同步并发安全（高）**：`ProjectSync::advance()` 与 `ProjectCache::put/touch` 的读改写加 `flock` 互斥，`ProjectCache` 改为锁内从磁盘重读 + tmp+rename 原子写，不再重复拉取、丢更新。
- **多模型 created_at 时区一致（高）**：Memory / Timeline / Link / Category / Project 的 INSERT 统一显式写 `self::now()`（PHP 时区），不再由 DB `CURRENT_TIMESTAMP` 决定、与文章时间错位。
- **模板编译缓存原子写（中）**：`View::compiled()` 改 tmp+rename，避免并发请求 `require` 到半写的 PHP 文件。
- **RSS pubDate 时区自适应（中）**：不再硬编码 `+0800`，改用 `date('O')` 与 PHP 配置时区联动。
- **后台文章列表分页越界（中）**：`page` 钳制到 `totalPages`，越界页不再返回空列表。
- **分类 slug 冲突（低）**：同秒新建同名分类时改为 `-2/-3...` 自增，不再撞唯一索引。
- **评论删除环检测（低）**：`Comment::delete` BFS 加 `visited` 去重，异常父子环不再死循环。
- **文章日期格式校验（低）**：`created_at` 非法串回退 null，避免 MySQL 严格模式报错。
- **标签 LIKE 通配符转义（低）**：标签含 `%/_` 时不再误匹配。
- **复制进度计数（低）**：`Backup::copyTree` 仅在 `copy` 成功时计数。

**优化**

- **升级改为后台异步执行**：升级由独立 PHP 进程（`bin/upgrade_worker.php`）跑，HTTP 请求立即返回，PHP-FPM worker 秒释放，其他访客不再排队；CPU 用 `nice` 降优先级。进度仍轮询 `upgrade_progress.json`，浮窗照常显示。
- **升级取消按钮**：浮窗新增「取消升级」，服务端在备份后 / 解压前 / 覆盖前检查取消标记，下载中由 curl 进度回调中止。
- **备份阶段进度打点**：大上传目录备份时按文件数刷新进度，避免前端误判「进程已中断」。

**升级说明**

- 无数据库结构变更；Nginx 无需改动。

### v2.2.5

**优化**

- **伪静态示例精简**：README 与 `config/nginx.conf.example` 中的样例由「六段带长注释」收敛为五组规则——`deny all; return 404;` 简化为 `return 404;`，去掉 `add_header Cache-Control`（`expires` 已自带该头），静态资源缓存从按前缀改为按扩展名匹配。服务器上既有的旧配置无需改动，两者功能等价。
- **补充「备份文件点下载返回 404」的排查**：后台备份下载走 `/admin/export/download/*.sql`，若服务器 / 宝塔安全加固 / 前置 CDN 对 `.sql` 后缀设了拒绝规则就会被拦成 404（`backups/` 目录本身已有目录级封锁）。同时说明：宝塔的「防跨站攻击」（open_basedir）只限制 PHP 读文件的路径范围，越界报的是 `open_basedir restriction` 而非 404，不要为此关闭它。
- **编辑器移除录音按钮**：工具栏的「开始录音 / 结束录音」在本站用不上，已在编辑器初始化处显式声明工具栏并去掉 `record` 项。

**升级说明**

- 无数据库结构变更；Nginx 只需核对是否存在 `.sql` 后缀黑名单，既有规则本身无需改动。

### v2.2.4

**修复**

- **升级检测被 PHP 警告污染**：宝塔环境下 `openssl.cafile` 不在 `open_basedir` 白名单内，`is_file()` 越界抛出 `E_WARNING`，警告文本因 `display_errors` 被输出在 JSON 之前，前端 `JSON.parse` 直接失败，于是显示「无法连接 GitHub / Gitee」，实际版本早已检测成功。现改为探测路径一律抑制警告，并在 `Response::json()` 输出前清空缓冲区，任何警告都不会再破坏 JSON 响应。
- **列表批量操作只对最后一项生效**：勾选框提交同名字段（如 `link_ids`），`a=1&a=2` 只有 `a[]` 形式才会被 PHP 解析成数组，后者覆盖前者，导致批量删除实际只处理最后一条；涉及文章、评论、友链、分类、项目、时间线、文件清理共 7 处，已全部改为数组字段。

**优化**

- **GitHub / Gitee 双平台熔断与可用性探测**：某平台连续失败后进入熔断窗口，窗口内直接走另一平台；后台异步探测两平台可用性并缓存，升级页同时展示各平台最近一次探测状态与时间。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，后台一键升级即可。

### v2.2.3

**修复**

- **检测失败后不再长期误判「无更新」**：此前检测结果无论成功与否都会写入缓存，一旦某次 GitHub 与 Gitee 都取不到数据，接下来 10 分钟（`CACHE_TTL`）内所有请求都会直接返回空结果、不再重新检测。现改为只缓存成功结果，失败不落盘、下次请求立即重试；GitHub 打不开、超时、限流或返回内容非法时，自动回退 Gitee 镜像。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，后台一键升级即可。

### v2.2.2

**修复**

- **文件清理（/admin/orphans）删不掉单个文件**：表单字段 `path` 只有一个值时 PHP 给的是字符串而非数组，`is_array()` 判断失败导致一个都删不掉；现已归一化为数组。
- **友链排序次序错乱（/admin/links）**：多条链接排序值相同时后添加的反而排到前面，现改为 `sort_order ASC, id ASC`，排序值为 0 的「项目本身」必定第一条。该排序全站共用，前台首页友链、`/links` 页面与页脚统计同步生效。

**升级说明**

- 本版无数据库结构与 Nginx 配置变更，后台一键升级即可。

### v2.2.1

**修复**

- 升级页「打开 Releases 页面」的兜底链接一直指向 Python 版仓库 `Contribuv/infowe_blog`，现改为 PHP 版仓库 `Contribuv/haozi_blog`；GitHub 检测正常时该链接取接口返回的真实地址，不受影响。

**升级说明**

- 本版仅此一处前端链接修复，无数据库结构与 Nginx 配置变更，后台一键升级即可。

### v2.2.0

图片处理与编辑器上传的一轮加固，另含一处移动端布局修复：

**新增**

- **上传白名单可在后台配置**：新增「博客设置 → 上传设置」，可自定义图片 / 音视频 / 附件允许的扩展名与单文件大小上限（1–512MB，留空回退默认）；编辑器文件选择器与后端校验共用同一份配置。`php` / `phtml` / `phar` / `htaccess` 等可执行或会改动服务器配置的扩展名会被自动忽略并提示。
- **支持 HEIC / HEIF**：PHP GD 不含 HEIF 解码能力，现改为依次借用 Imagick、`ffmpeg`、`magick`、`heif-convert` 转成 JPEG 后入库；服务器需安装其中之一，否则上传会给出明确提示。
- **编辑器上传进度浮窗**：上传文件时屏幕居中显示进度条（含百分比与 `已完成/总数`），多文件按字节聚合，完成后自动淡出。
- **附件链接显示类型图标**：前台文章中 `zip / pdf / doc / txt` 等下载链接按类型显示前置图标。

**修复**

- **图片校验**：无法解码的图片（文件损坏，或把脚本改名伪装成图片）现在直接拒绝，不再原样落盘；头像上传同口径处理。
- **GIF / WebP 保持原格式**：此前除 PNG 外一律重编码为 JPEG，导致 GIF 动画丢失、WebP 变静态图；现 GIF 原样保留动画，WebP 用 `imagewebp` 重编码（含加水印流程）。
- **移动端布局**：编辑器置顶工具栏与后台顶栏原本都吸附 `top:0`，滚动时工具栏首行被顶栏盖住，现按顶栏高度下移。

**升级说明**

- 无需改动 Nginx 配置，也没有数据库结构变更，后台一键升级即可。

### v2.1.2

**修复**

- **直接访问 js / css 时中文乱码**：nginx 直出的静态文件按 `mime.types` 默认不带 charset，浏览器直接打开时按本地默认编码（中文环境常是 GBK）解码 UTF-8 文件导致乱码；静态资源 location 现补充 `charset utf-8;` 与 `charset_types`。
- **明确静态资源 location 只能有一处**：重复定义相同的 location 会让 `nginx -t` 报 `duplicate location`、reload 失败，配置看着改了实际不生效。

**升级说明**

- 本版只涉及配置样例与文档，PHP 代码无变更。请把服务器「伪静态」中的静态资源 location 更新为 [`config/nginx.conf.example`](config/nginx.conf.example) 里的版本（补上那两行 `charset`），再执行 `nginx -t && nginx -s reload`。

### v2.1.1

**修复**

- **静态错误页生成失败（重要）**：`ErrorPages` 缺少 `use Blog\View`，导致 `php bin/build_50x.php` 与后台换主题时的重建一直失败，`static/404.html`、`static/403.html` 始终生成不出来，nginx 的 `error_page` 指向了不存在的文件、404 / 403 退回 nginx 原生页。现已修复命名空间，并把 `rebuild()` 改为逐页容错，保证三个文件一定能写出。
- **`/projects` 首屏卡顿**：自动同步原先在请求线程内同步调用 GitHub API 检测更新，项目较多时会拖慢首字节；现改为请求内只做本地判断并入队，检测与拉取在响应发出后（`fastcgi_finish_request`）异步执行。
- **升级后自动重建静态错误页**：一键升级完成后自动重建一次，避免升级包里的 `static/*.html` 与当前主题脱节。

**优化**

- **项目自动同步更省流量**：先比对远端 `pushed_at`，有更新才拉 README 与图片；手动同步按钮仍为强制全量。
- **新增 `config/nginx.conf.example`**：可直接复制到宝塔「伪静态」的配置样例；README 伪静态段落同步更新，并强调 `error_page` 必须指向 `static/{404,403,50x}.html`。

**升级说明**

- 升级后请先执行一次 `php bin/build_50x.php`（或在后台切换一次主题），确保 `static/{404,403,50x}.html` 存在；否则 404 / 403 / 502 仍可能是 nginx 原生页。

### v2.1.0

**优化**

- 后台「一键升级」改为全量替换 + 清理旧文件：磁盘上多出来的旧文件与目录一律删除，1.x 残留的 `public/`、`admin.php` 不会再留在磁盘上冒充新结构。保留 `uploads/`、`data/`、`storage/`、`backups/`、`config/config.php`、`config/installed.lock`、`.user.ini`。

**升级说明**

- 若手动覆盖上传，请自行确认旧目录已删（`ls -la` 确认无 `public/` 与 `admin.php` 残留）。

### v2.0.0

**重大变更**

- **运行目录改为项目根**（含 `index.php` 的那一层）：`public/` 已整体删除，`themes/`、`static/` 上移到根，沿用旧 nginx 配置会导致主题与静态资源 404。
- **入口合并为单一 `index.php`**：`admin.php` 已移除，`/admin` 无需额外配置。
- **错误页主题化**：nginx 的 `error_page` 指向预渲染静态页 `static/{404,403,50x}.html`，改主题样式后重跑 `php bin/build_50x.php`。
- `config/`、`storage/`、`uploads/`、`backups/`、`data/` 升级不会被覆盖。

</details>

---

## 💚 开源

- 原 Flask 版作者：[Contribuv/infowe_blog](https://github.com/Contribuv/infowe_blog)
- PHP 版：[Contribuv/haozi_blog](https://github.com/Contribuv/haozi_blog) · 镜像 [infowe/haozi_blog](https://gitee.com/infowe/haozi_blog)

模板引擎 `core/Template/` 为本项目自研实现；`core/lib/cacert.pem` 来自 curl 项目（MIT）；`fonts/wqy-microhei.ttc` 为文泉驿微米黑（Apache-2.0）；IP 归属地解析数据来自 ip2region（Apache-2.0）。

---

## 📄 License

MIT License

Copyright © 2025 [豪子](https://github.com/Contribuv)