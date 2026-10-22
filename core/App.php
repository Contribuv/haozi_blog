<?php
declare(strict_types=1);

namespace Blog;

use Blog\Service\Csrf;
use Blog\Service\ErrorPage;
use Blog\Service\PageCache;

class App
{
    public Router $router;

    public function __construct()
    {
        $this->router = new Router();
    }

    public function run(): void
    {
        // 全局安全响应头（不影响功能，只加防护）
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
        // 跨站请求防护：所有 POST 统一在此拦一道，不用逐个 action 校验 token
        if (Request::method() === 'POST' && !Csrf::isSameOrigin()) {
            ErrorPage::render(403);
            return;
        }
        if (Request::method() === 'POST' && Csrf::isSameOrigin()) {
            // 只拦 POST 且 CSRF 校验通过后：后台任何写操作（发 / 改 / 删文章、审核评论、
            // 改设置等）都会让下一次前台请求重建缓存，静态 HTML 永远跟最新内容一致
            PageCache::flushAll();
            // 下一次 GET（提交后的跳转页）要展示 flash 提示，标记绕过一次缓存
            PageCache::stampNextBypass();
        } else {
            // 首页 / 文章 / 友链等匿名 GET 直接吐缓存的静态 HTML（绕开路由与数据库），
            // 405+ 节点的拨测 / 压测并发因此不再打爆 PHP-FPM（502 根因）
            PageCache::begin();
        }
        // 渲染前注入模板全局上下文（站点信息 / 导航 / 主题）
        View::bootstrapGlobals();
        $this->router->dispatch();
    }
}
