<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

use Blog\App;
use Blog\Service\Install;

// 未安装：直接渲染安装向导（不重定向，避免与入口重写规则形成死循环）
if (!Install::isInstalled()) {
    require __DIR__ . '/install.php';
    exit;
}

$app = new App();
$routes = require CORE_PATH . '/routes.php';
$app->router->register($routes['admin']);
$app->run();
