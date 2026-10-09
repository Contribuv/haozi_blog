<?php
declare(strict_types=1);

namespace Blog;

use Blog\Service\Csrf;
use Blog\Service\ErrorPage;

class App
{
    public Router $router;

    public function __construct()
    {
        $this->router = new Router();
    }

    public function run(): void
    {
        // 跨站请求防护：所有 POST 统一在此拦一道，不用逐个 action 校验 token
        if (Request::method() === 'POST' && !Csrf::isSameOrigin()) {
            ErrorPage::render(403);
            return;
        }
        // 渲染前注入模板全局上下文（站点信息 / 导航 / 主题）
        View::bootstrapGlobals();
        $this->router->dispatch();
    }
}
