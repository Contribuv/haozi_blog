<?php
declare(strict_types=1);

/**
 * 错误页自检：验证 ErrorPage 的主题渲染、降级与防重入守卫。
 * 用法：php bin/check_errorpage.php
 *
 * ErrorPage::render() 会 exit，故用子进程（php -r）隔离探测。
 */
require_once __DIR__ . '/../core/bootstrap.php';

$fail = 0;

/** 在子进程里渲染错误页并回传正文（ErrorPage::render 会 exit） */
function probe(int $code): string
{
    // 走独立脚本而非 php -r：PowerShell 会吞掉内联代码里的引号与反斜杠
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/render_errorpage.php') . ' ' . $code . ' 2>&1';
    return (string) shell_exec($cmd);
}

$checks = [];

// 1) 404 / 403 走主题模板（含 tech-error 样式类）
$checks['404 渲染主题模板'] = str_contains(probe(404), 'tech-error-code');
$checks['403 渲染主题模板'] = str_contains(probe(403), 'tech-error-code');

// 2) 降级路径：直接调私有 fallback()，确认输出自包含 HTML（不依赖数据库与主题资源）
$m = new ReflectionMethod(\Blog\Service\ErrorPage::class, 'fallback');
$m->setAccessible(true);
$fb = (string) $m->invoke(null, 404);
$checks['模板缺失降级为自包含 HTML'] = str_contains($fb, '<h1>404</h1>')
    && str_contains($fb, '返回首页')
    && !str_contains($fb, '{%');

// 3) 502 静态兜底页存在且自带内联主题样式（PHP 崩溃时唯一能出页面的途径）
$f = PHP_BLOG_ROOT . '/static/50x.html';
$html = is_file($f) ? (string) file_get_contents($f) : '';
$checks['502 静态兜底页内联主题样式'] = str_contains($html, '<style>') && str_contains($html, 'tech-error-code');

// 4) 四个主题错误页模板齐备
foreach ([403, 404, 500, 502] as $c) {
    $checks["themes/tech/{$c}.html 存在"] = is_file(PHP_BLOG_ROOT . "/themes/tech/{$c}.html");
}

// 5) 防重入守卫：DB 故障时 ErrorPage 不会被再次触发形成递归
$checks['防重入守卫存在'] = str_contains((string) file_get_contents(CORE_PATH . '/Service/ErrorPage.php'), '$rendering');

// 6) 全局异常处理器已挂在唯一入口
$checks['index.php 已挂异常处理器'] = str_contains((string) file_get_contents(PHP_BLOG_ROOT . '/index.php'), 'set_exception_handler');

foreach ($checks as $name => $ok) {
    printf("%s %s\n", $ok ? 'OK  ' : 'FAIL', $name);
    $fail += $ok ? 0 : 1;
}

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 条\n";
exit($fail === 0 ? 0 : 1);