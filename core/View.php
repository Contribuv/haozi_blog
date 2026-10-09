<?php
declare(strict_types=1);

namespace Blog;

use Blog\Template\Compiler;
use Blog\Template\Markup;
use Blog\Template\Runtime;
use Blog\Url;

/**
 * 视图门面：全局变量、主题解析、编译缓存、输出转义。
 * 模板由 Compiler 编译为闭包缓存文件，Runtime 执行渲染。
 */
class View
{
    /** @var array 跨请求共享全局变量 */
    private static array $globals = [];
    private static string $theme = 'tech';
    /** 是否已注入全局上下文（每请求一次） */
    private static bool $bootstrapped = false;

    /**
     * 首次渲染前注入全局上下文（站点信息 / 导航开关 / 主题），并同步当前主题。
     * 对照原项目 context_processor 的 inject_globals。
     */
    public static function bootstrapGlobals(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;
        self::$theme = \Blog\Service\Context::activeTheme();
        self::$globals = \Blog\Service\Context::globals();
    }

    public static function shareGlobal(string $k, mixed $v): void
    {
        self::$globals[$k] = $v;
    }

    public static function setTheme(string $t): void
    {
        self::$theme = $t;
    }

    public static function theme(): string
    {
        return self::$theme;
    }

    /** 输出转义：null→''、bool→'True'/'False'，等价 Flask str+escape；Markup 视为安全 HTML 原样输出 */
    public static function e(mixed $v): string
    {
        if ($v instanceof Markup) {
            return (string) $v;
        }
        return htmlspecialchars(Runtime::str($v), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function render(string $tpl, array $data = []): string
    {
        self::bootstrapGlobals();
        $ctx = array_replace(self::$globals, $data);
        $ctx['request'] = new RequestCtx();
        $loader = static fn (string $name): array => self::compiled($name);
        $rt = new Runtime($ctx, $loader);
        $html = $rt->renderTemplate($tpl, $ctx);
        // 输出注入类钩子：page.header 追加到 </head> 前，page.footer 追加到 </body> 前
        $head = Hook::emit('page.header', '', ['template' => $tpl]);
        if (is_string($head) && $head !== '') {
            $html = str_replace('</head>', $head . '</head>', $html);
        }
        $foot = Hook::emit('page.footer', '', ['template' => $tpl]);
        if (is_string($foot) && $foot !== '') {
            $html = str_replace('</body>', $foot . '</body>', $html);
        }
        return $html;
    }

    /** 编译缓存：源 mtime 失效重编译，命中直接 require */
    private static function compiled(string $tpl): array
    {
        $src = self::resolve($tpl);
        if ($src === null) {
            throw new \RuntimeException("模板不存在: {$tpl}");
        }
        $cacheDir = STORAGE_PATH . '/cache/templates';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $code = file_get_contents($src);
        $file = $cacheDir . '/' . sha1($tpl . '|' . $src) . '.php';
        if (!is_file($file) || filemtime($file) < filemtime($src)) {
            $php = Compiler::compile($code, $tpl);
            file_put_contents($file, $php, LOCK_EX);
        }
        $m = require $file;
        if (!is_array($m) || !isset($m['fn'], $m['meta'])) {
            throw new \RuntimeException("模板缓存损坏: {$file}");
        }
        return $m;
    }

    /** 模板解析链：主题 → core/view/shared → core/view */
    private static function resolve(string $tpl): ?string
    {
        $tpl = ltrim($tpl, '/');
        foreach ([
            PHP_BLOG_ROOT . '/themes/' . self::$theme . '/' . $tpl,
            CORE_PATH . '/view/shared/' . $tpl,
            CORE_PATH . '/view/' . $tpl,
        ] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    public static function url_for(string $name, array $params = []): string
    {
        // 模板统一用 filename= 传参（Flask 的 static/theme_static 亦支持带 / 的路径）
        if ($name === 'static' || $name === 'theme_static') {
            $file = (string) ($params['filename'] ?? $params['path'] ?? '');
            unset($params['filename'], $params['path']);
            $base = $name === 'static' ? '/static/' : '/themes/';
            $url = $base . implode('/', array_map('rawurlencode', explode('/', ltrim($file, '/'))));
            $qs = http_build_query($params);
            return $qs !== '' ? $url . '?' . $qs : $url;
        }
        return Url::urlFor($name, $params);
    }

    public static function icon(string $name, int $size = 20, string $class = ''): string
    {
        return Runtime::iconSvg($name, $size, $class);
    }
}
