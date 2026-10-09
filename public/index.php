<?php
declare(strict_types=1);

/**
 * 前台入口：注册前台路由并分发。
 */
require_once __DIR__ . '/../core/bootstrap.php';

use Blog\App;
use Blog\Request;
use Blog\Service\Context;
use Blog\Service\Install;
use Blog\View;

// 未安装：直接渲染安装向导（不重定向，避免与入口重写规则形成死循环）
if (!Install::isInstalled()) {
    require __DIR__ . '/install.php';
    exit;
}

$app = new App();
$routes = require CORE_PATH . '/routes.php';
$app->router->register($routes['front']);
$app->router->setFallback(static function (): void {
    // 未匹配路由：站内风格 404（后台路径交给 admin.php）
    View::bootstrapGlobals();
    \Blog\Response::html(View::render('404.html', []), 404);
});
$app->run();
