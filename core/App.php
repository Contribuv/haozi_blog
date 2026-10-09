<?php
declare(strict_types=1);

namespace Blog;

class App
{
    public Router $router;

    public function __construct()
    {
        $this->router = new Router();
    }

    public function run(): void
    {
        // 渲染前注入模板全局上下文（站点信息 / 导航 / 主题）
        View::bootstrapGlobals();
        $this->router->dispatch();
    }
}
