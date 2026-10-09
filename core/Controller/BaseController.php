<?php
declare(strict_types=1);

namespace Blog\Controller;

use Blog\Response;
use Blog\Service\ErrorPage;
use Blog\View;

/**
 * 控制器基类：统一渲染与错误页。
 */
abstract class BaseController
{
    /** 渲染模板并输出（自动加站点全局上下文） */
    protected function render(string $tpl, array $data = [], int $code = 200): void
    {
        Response::html(View::render($tpl, $data), $code);
    }

    /** 404 页面（站内风格，模板缺失时自动降级） */
    protected function abort404(): void
    {
        ErrorPage::render(404);
    }
}
