<?php
declare(strict_types=1);
/**
 * 一致性自检：core/routes.php 与 core/Url.php::ROUTES 是否一一对应；
 * 模板中出现的 url_for('xxx') 是否都能解析。
 * 用法：D:\php83\php.exe bin\check_routes.php
 */
define('PHP_BLOG_SKIP_PLUGINS', 1);
require __DIR__ . '/../core/bootstrap.php';

$fail = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $fail;
    if (!$ok) {
        $fail++;
    }
    printf("%s %s%s\n", $ok ? 'OK  ' : 'FAIL', $name, $extra !== '' ? ' -> ' . $extra : '');
}

$routes = require PHP_BLOG_ROOT . '/core/routes.php';

// 1) 路由表 → 规范化路径集合
$pathToEndpoint = [];
foreach ($routes as $group => $list) {
    foreach ($list as $r) {
        [$methods, $path, $handler] = $r;
        $key = $group . ' ' . $path;
        $pathToEndpoint[$key] = $handler;
    }
}

// 2) Url::ROUTES → 规范化路径集合
$urlPaths = [];
foreach (\Blog\Url::ROUTES as $name => $rule) {
    $p = preg_replace('#<[^>]*>#', 'X', $rule);
    $urlPaths[$p][] = $name;
}

// 3) 双向比对
foreach ($pathToEndpoint as $key => $handler) {
    [$group, $path] = explode(' ', $key, 2);
    $norm = preg_replace('#<[^>]*>#', 'X', $path);
    if (!isset($urlPaths[$norm])) {
        check('routes 有但 Url::ROUTES 缺: ' . $key, false);
    }
}
foreach ($urlPaths as $norm => $names) {
    $hit = false;
    foreach (array_keys($pathToEndpoint) as $key) {
        $p = preg_replace('#<[^>]*>#', 'X', explode(' ', $key, 2)[1]);
        if ($p === $norm) {
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        check('Url::ROUTES 有但 routes 缺: ' . implode(',', $names) . ' (' . $norm . ')', false);
    }
}

// 4) 模板里所有 url_for('xxx') 字面量是否可解析
$names = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PHP_BLOG_ROOT . '/core/view'));
foreach ($rii as $f) {
    if (!$f->isFile() || !str_ends_with($f->getFilename(), '.html')) {
        continue;
    }
    $src = (string) file_get_contents($f->getPathname());
    if (preg_match_all("#url_for\(\s*'([a-z0-9_]+)'#i", $src, $m)) {
        foreach ($m[1] as $n) {
            $names[$n][] = $f->getFilename();
        }
    }
}
foreach ($names as $n => $files) {
    $url = \Blog\Url::urlFor($n);
    // 合法解析结果不含下划线残留（未命中会退化为 /name）
    $special = $n === 'static' || $n === 'theme_static';
    $ok = $special || !str_contains($url, '_');
    check('模板 url_for: ' . $n, $ok, '解析为 ' . $url . '（' . implode(',', array_unique($files)) . '）');
}

printf("\n失败 %d\n", $fail);
exit($fail ? 1 : 0);