<?php
declare(strict_types=1);

define('PHP_BLOG_ROOT', dirname(__DIR__));
define('CORE_PATH', __DIR__);
define('STORAGE_PATH', PHP_BLOG_ROOT . '/storage');

// 配置文件：优先使用安装向导生成的 config.php；缺失时回退示例配置（供向导页面读取默认值）
$sampleFile = PHP_BLOG_ROOT . '/config/config.sample.php';
$configFile = PHP_BLOG_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    $configFile = $sampleFile;
}
define('CONFIG_FILE', $configFile);

$config = require CONFIG_FILE;

// 与示例配置做深合并：升级只会往 config.sample.php 追加新的默认项，老站点的 config.php
// 不手动补也能立即拿到默认值；用户显式配置过的键以 config.php 为准（不会被默认值覆盖）。
if ($configFile !== $sampleFile && is_file($sampleFile) && is_array($config)) {
    $defaults = require $sampleFile;
    if (is_array($defaults)) {
        $config = array_replace_recursive($defaults, $config);
    }
}

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
    // secure：HTTPS 请求下强制 true（即使 config 里写了 false），避免明文信道泄露会话 cookie；
    // HTTP（本地调试）仍按配置，默认 false。
    $isHttps = \Blog\Request::isSecure();
    $secure = $isHttps || (bool) ($config['session']['secure'] ?? false);
    session_set_cookie_params([
        'lifetime' => $config['session']['lifetime'] ?? 3600,
        'path' => $config['session']['path'] ?? '/',
        'domain' => $config['session']['domain'] ?? '',
        'secure' => $secure,
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