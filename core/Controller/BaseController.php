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
        self::releaseSession();
        Response::html(View::render($tpl, $data), $code);
    }

    /**
     * 渲染前释放 session 文件锁（session_write_close）。
     * session 锁自 session_start 起持有到请求结束，渲染全程都在锁内；
     * 管理员多标签并发（同步轮询 + 后台页操作）会互相串行排队。
     * 渲染期唯一会写 session 的是 Runtime 消费 flash（unset 不落盘会导致
     * 消息永不消失），故先在此消费并缓存到 $GLOBALS，再安全关闭。
     */
    protected static function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $GLOBALS['_flashes_takeover'] = $_SESSION['_flashes'] ?? [];
            unset($_SESSION['_flashes']);
            session_write_close();
        }
    }

    /** 404 页面（站内风格，模板缺失时自动降级） */
    protected function abort404(): void
    {
        ErrorPage::render(404);
    }
}
