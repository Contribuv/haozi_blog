<?php
/**
 * PHP 内置服务器调试路由：php -S 0.0.0.0:8000 -t . bin/router.php
 *
 * 仅用于本地开发。URL 与磁盘一一对应，故这里只做两件事：
 * ① 静态文件自行输出并带正确 MIME（Windows 的 mime_content_type 不认 .css）；
 * ② 白名单外的路径一律交给前端控制器，避免 config/core 等源码被直接读到。
 */
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = $root . $path;

if (is_file($file) && !str_ends_with($path, '.php')) {
    // 安全兜底：只允许 static/、themes/、uploads/ 下的常规文件被直接输出
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    // 主题模板源码（.html）与 info.json 不外露，与 .htaccess / nginx 的封锁规则保持一致
    if (!preg_match('#^(static|themes|uploads)/#', $rel)
        || str_contains($rel, '/.')
        || preg_match('#^themes/.+\.(html|json)$#', $rel)) {
        http_response_code(404);
        return true;
    }
    $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
        'css' => 'text/css',
        'js' => 'text/javascript',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'json' => 'application/json',
        'html' => 'text/html',
        'woff2' => 'font/woff2',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    readfile($file);
    return true;
}

require $root . '/index.php';
return true;