<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\View;

/**
 * 预渲染静态错误页：static/404.html、403.html、50x.html。
 *
 * 为什么必须预渲染：404 / 403 / 502 恰恰发生在 PHP 通道本身不可用的时候
 * （文件没上传、fastcgi 挂了）。若 nginx 的 error_page 指向 index.php，
 * PHP 一挂就退回 nginx 原生页 —— 这正是「404 还是默认 nginx 页」的成因。
 * 静态文件由 nginx 直接吐，不经过 PHP，后端挂到什么程度都是主题样式。
 *
 * 为什么不能只靠 CLI 生成：换主题后静态页会停留在旧主题样式。故换主题动作
 * （ThemeController::activate）与升级动作都会自动调用 rebuild()。
 * 物理约束是 502 时 PHP 已崩溃，无法实时渲染，故 502 用的只能是上次构建的版本。
 */
class ErrorPages
{
    /** 状态码 => 输出文件名。502/503/504 共用一个模板（此刻 PHP 已崩，区分不出来） */
    private const PAGES = [404 => '404.html', 403 => '403.html', 502 => '50x.html'];

    /**
     * 按当前启用主题重新生成三个静态页。
     *
     * 关键：三个文件必须「一定写出来」。它们缺失时 nginx 的 error_page 会扑空，
     * 直接退回原生错误页 —— 这正是「404/403/502 不跟主题走」的根源。
     * 故单页渲染失败（例如主题没提供某个错误页模板）只降级为自包含 HTML，不让整体中断。
     *
     * @return array<string,string> 输出路径 => 错误信息（成功时为空数组）
     */
    public static function rebuild(): array
    {
        $errs = [];
        try {
            View::bootstrapGlobals();
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        // 静态页无 PHP 参与，主题 CSS 只能内联；/themes/... 走路由，PHP 挂了取不到
        $css = (string) @file_get_contents(PHP_BLOG_ROOT . '/themes/'
            . Context::activeTheme() . '/theme.css');
        foreach (self::PAGES as $code => $name) {
            try {
                $html = View::render($code . '.html', []);
                if ($html === '') {
                    $html = ErrorPage::fallback($code);
                }
            } catch (\Throwable) {
                // 主题缺少该错误页模板时退到自包含 HTML：宁可样式朴素，也不能缺文件
                $html = ErrorPage::fallback($code);
            }
            if ($css !== '') {
                $html = str_replace('</head>', '<style>' . $css . '</style></head>', $html);
            }
            $path = PHP_BLOG_ROOT . '/static/' . $name;
            if (@file_put_contents($path, $html) === false) {
                $errs[$path] = '写入失败，请检查 static/ 目录权限';
            }
        }
        return $errs;
    }
}
