<?php
declare(strict_types=1);

define('PHP_BLOG_ROOT', dirname(__DIR__));
define('CORE_PATH', __DIR__);
define('STORAGE_PATH', PHP_BLOG_ROOT . '/storage');

// 配置文件：优先使用安装向导生成的 config.php；缺失时回退示例配置（供向导页面读取默认值）
$configFile = PHP_BLOG_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    $configFile = PHP_BLOG_ROOT . '/config/config.sample.php';
}
define('CONFIG_FILE', $configFile);

$config = require CONFIG_FILE;

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Shanghai');

spl_autoload_register(function ($class) {
    $prefix = 'Blog\\';
    $base_dir = CORE_PATH . '/';
    $len = strlen($prefix);
    if (str_starts_with($class, $prefix)) {
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

// CLI 或已输出内容时不再启动会话，避免 "headers already sent" 警告
if (PHP_SAPI !== 'cli' && !headers_sent() && empty($_SESSION)) {
    session_set_cookie_params([
        'lifetime' => $config['session']['lifetime'] ?? 3600,
        'path' => $config['session']['path'] ?? '/',
        'domain' => $config['session']['domain'] ?? '',
        'secure' => $config['session']['secure'] ?? false,
        'httponly' => $config['session']['httponly'] ?? true,
        'samesite' => $config['session']['samesite'] ?? 'Lax',
    ]);
    session_name($config['session']['name'] ?? 'PHPBLOG_SESSID');
    session_start();
}

// 扫描并加载 plugins/* 插件（入口自行注册钩子到 Hook 总线）；安装模式跳过，避免插件查库失败
if (!defined('PHP_BLOG_SKIP_PLUGINS') || !PHP_BLOG_SKIP_PLUGINS) {
    \Blog\Plugin::loadAll();
}