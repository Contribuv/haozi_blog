<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Response;
use Blog\View;
use Throwable;

/**
 * 错误页统一出口：优先渲染主题里的 <code>.html，模板或数据库不可用时降级为内置 HTML。
 *
 * 502 无法走这里 —— nginx 连不上 PHP 时 PHP 已崩溃，只能由 static/50x.html 兜底。
 */
class ErrorPage
{
    /** 防重入：DB 故障时模板渲染会再次触发本类，第二次直接降级 */
    private static bool $rendering = false;

    /** 渲染指定状态码的错误页并结束请求 */
    public static function render(int $code): void
    {
        if (self::$rendering) {
            Response::html(self::fallback($code), $code);
            return;
        }
        self::$rendering = true;
        Response::html(self::capture($code), $code);
    }

    /** 未捕获异常处理器：任何 PHP 致命错误都落到主题化的 500 页 */
    public static function handle(Throwable $e): void
    {
        error_log('[blog] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            self::render(500);
        }
    }

    /** 主题模板优先，失败降级为自包含 HTML（不依赖数据库与主题资源） */
    private static function capture(int $code): string
    {
        try {
            View::bootstrapGlobals();
            $html = View::render($code . '.html', []);
            if ($html !== '') {
                return $html;
            }
        } catch (Throwable) {
            // 模板或数据库不可用，走下面的兜底
        }
        return self::fallback($code);
    }

    /** 自包含 HTML：不依赖数据库与主题资源 */
    private static function fallback(int $code): string
    {
        [$title, $desc] = match ($code) {
            403 => ['请求被拒绝', '检测到跨站提交的表单，请求已拦截。'],
            404 => ['页面不存在', '你访问的页面可能已被删除或从未存在过。'],
            default => ['服务器错误', '服务器遇到意外错误，请稍后重试。'],
        };
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $code . ' ' . $title . '</title><style>'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#0d1117;color:#c9d1d9;font:16px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace}'
            . '.e{text-align:center}h1{font-size:64px;margin:0;color:#58a6ff}p{color:#8b949e}'
            . 'a{display:inline-block;margin-top:16px;padding:8px 20px;border:1px solid #30363d;'
            . 'border-radius:6px;color:#58a6ff;text-decoration:none}</style></head><body><div class="e">'
            . '<h1>' . $code . '</h1><p>' . $desc . '</p><a href="/">返回首页</a>'
            . '</div></body></html>';
    }
}