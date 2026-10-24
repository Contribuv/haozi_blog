<?php
/**
 * 配置示例文件（分发给新用户时使用的模板）。
 *
 * 安装向导会读取本文件作为默认值，安装完成后生成 config/config.php 写入真实凭据；
 * 也可手动复制本文件为 config/config.php 并填写数据库信息。
 *
 * 注意：config/config.php 不应提交到代码仓库（含真实密码）。
 */
return [
    // ── 数据库连接 ──
    'db' => [
        'host' => '',            // 数据库主机，如 127.0.0.1
        'port' => 3306,          // 数据库端口，MySQL 默认 3306
        'dbname' => '',          // 数据库名
        'user' => '',            // 数据库账号
        'pass' => '',            // 数据库密码
        'charset' => 'utf8mb4',  // 连接字符集，建议 utf8mb4
        'options' => [           // PDO 连接选项
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      // 出错即抛异常
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // 默认返回关联数组
            PDO::ATTR_EMULATE_PREPARES => false,              // 关闭模拟预处理，走真正的预处理语句
        ],
    ],
    // ── 站点基础信息 ──
    'app' => [
        'name' => 'Haozi Blog',        // 站点名称，显示在页面标题等处
        'env' => 'production',         // 运行环境：production / development
        'debug' => false,              // 调试开关，生产环境务必保持 false
        'timezone' => 'Asia/Shanghai', // 时区，用于 date() 等时间函数
        'base_path' => '',             // 站点部署在子目录时的前缀，如 '/blog'；根目录留空
        // 站点正式地址（如 https://www.example.com）。留空则跟随当前访问地址，
        // 后台「博客设置 → 站点地址」也可设置；此处显式填写优先级更高，
        // 本地调试想测试 301 时可在 config.php 里临时覆盖。
        'site_url' => '',
    ],
    // ── 可信反向代理 ──
    'trusted_proxies' => [
        // 可信反向代理 IP 列表。仅当 REMOTE_ADDR 命中此列表时，
        // 才采信 X-Forwarded-For / X-Real-IP 作为客户端真实 IP。
        // 未配置时忽略代理头，直接使用 REMOTE_ADDR，防止伪造头绕过防爆破。
        // 示例：'127.0.0.1', '10.0.0.1'
    ],
    // ── 会话 Cookie ──
    'session' => [
        'name' => 'PHPBLOG_SESSID', // 会话 Cookie 名
        'lifetime' => 3600,         // 会话有效期（秒）
        'path' => '/',              // Cookie 作用路径
        'domain' => '',             // Cookie 作用域，留空表示当前域名
        'secure' => false,          // 仅 HTTP 环境需要显式开启；HTTPS 请求下程序会自动强制 Secure
        'httponly' => true,         // 禁止 JavaScript 读取，降低 XSS 窃取风险
        'samesite' => 'Lax',        // 跨站携带策略：Lax / Strict / None
    ],
    // ── 主题 ──
    'theme' => [
        'default' => 'tech', // 默认主题目录名（themes/ 下）
    ],
];