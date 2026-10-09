<?php
declare(strict_types=1);

/**
 * 生成 static/50x.html：nginx 502/504 的静态兜底页。
 * 502 时刻 PHP 已崩溃，无法渲染模板，故预渲染成纯静态文件。
 * 用法：php bin/build_50x.php
 */
require_once __DIR__ . '/../core/bootstrap.php';

use Blog\View;

View::bootstrapGlobals();
$html = View::render('502.html', []);

// 内联主题样式：静态页无 PHP 参与，CSS 只能靠相对路径引用（/themes/... 走 PHP 路由，502 时不可用）
$css = (string) file_get_contents(PHP_BLOG_ROOT . '/themes/tech/theme.css');
$html = str_replace('</head>', '<style>' . $css . '</style></head>', $html);

$out = PHP_BLOG_ROOT . '/static/50x.html';
file_put_contents($out, $html);
echo "written: {$out} (" . strlen($html) . " bytes)\n";