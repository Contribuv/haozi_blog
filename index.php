<?php
declare(strict_types=1);

/**
 * 全站唯一入口：注册前台 + 后台全部路由并分发。
 *
 * 服务器运行目录（nginx root / Apache DocumentRoot）直接指向项目根目录，
 * 由 .htaccess 或 nginx 的 try_files 把非静态请求交给本文件。
 */
require_once __DIR__ . '/core/bootstrap.php';

use Blog\App;
use Blog\Service\ErrorPage;
use Blog\Service\Install;

// 未安装：直接渲染安装向导（不重定向，避免与入口重写规则形成死循环）
if (!Install::isInstalled()) {
    require __DIR__ . '/install.php';
    exit;
}

// 未捕获异常一律渲染主题化的 500 页，避免 nginx 默认错误页
set_exception_handler([ErrorPage::class, 'handle']);

$app = new App();
$routes = require CORE_PATH . '/routes.php';
$app->router->register($routes['front']);
$app->router->register($routes['admin']);

// 未匹配路由：站内风格 404
$app->router->setFallback(static function (): void {
    ErrorPage::render(404);
});

$app->run();