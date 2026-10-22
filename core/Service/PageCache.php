<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Request;

/**
 * 全页缓存：对匿名前台 GET 请求整页缓存 HTML，存于 storage/cache/pages/。
 *
 * 目的：压测 / 拨测类工具（如宝塔 t.bt.cn 测速、各类 blink 监控）用几十上百个
 * 并发打前台时，PHP-FPM 的 worker 会瞬间打满，上游拒连呈现为随机 502。
 * 命中缓存时直接输出文件内容，完全不触碰数据库与路由分发。
 *
 * 何时拦截：
 * - 只拦前台 GET / HEAD（HEAD 按 GET 语义，路由层已回退），且 HTTP 200、text/html；
 * - 后台 /admin、静态资源、API、RSS 一律绕行（变化快且无高并发压力）；
 * - 带 query 的请求只缓存白名单参数（page），其余（如 search 的 q）不缓存；
 * - POST 写操作后给下一次 GET 打「免缓存标」：评论 / 友链申请提交后的跳转页
 *   要展示 flash 提示，缓存命中会把提示吞掉。
 *
 * 更新文章后页面会不会跟着更新？会：
 * - App 在每个通过 CSRF 的 POST（后台写操作）后立即 flushAll 全量失效；
 * - 另有 TTL 兜底（默认 10 分钟），任何没接到失效钩子的角落也会自动刷新。
 */
class PageCache
{
    /** 兜底过期秒数：失效钩子万一遗漏，内容最迟这么多秒后自动刷新 */
    public const TTL = 600;

    /** @var string|null 本次请求的缓存键；null 表示本次不参与缓存 */
    private static ?string $key = null;

    /** 这些前缀直接绕行：后台、API、静态资源与订阅（由 PHP 输出文件流或短请求，无缓存收益） */
    private const DENY_PREFIXES = [
        '/admin', '/api/', '/uploads/', '/static/', '/themes/', '/avatar/',
        '/share-thumb/', '/readme-img/', '/search', '/feed.xml', '/install.php', '/readme',
    ];

    /** query 白名单：进入缓存键的参数（首页 / 列表分页）。其余 query 一带无需缓存 */
    private const QUERY_ALLOW = ['page'];

    /** 请求入口：命中直接吐 HTML 并结束请求；未命中则记录缓存键供输出阶段落盘 */
    public static function begin(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $method = Request::method();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }
        $path = self::pathOnly();
        foreach (self::DENY_PREFIXES as $deny) {
            if ($path === $deny || str_starts_with($path, $deny)) {
                return;
            }
        }
        // POST 写后的下一次 GET：显示 flash 提示，不读缓存（读完即清标记）
        if (($_SESSION['pagecache_bypass'] ?? false)) {
            unset($_SESSION['pagecache_bypass']);
            return;
        }
        $key = self::buildKey($path);
        if ($key === null) {
            return;
        }
        $file = STORAGE_PATH . '/cache/pages/' . $key;
        $html = @file_get_contents($file);
        if ($html === '' || $html === false) {
            // 未命中：记下键，响应正常走完后由 Response::html 于 HTTP 200 时落盘
            self::$key = $key;
            return;
        }
        // 兜底过期：文件内容头两行是时间戳与长度（格式见 store），过期即当未命中
        $nl = strpos($html, "\n");
        $expires = (int) ($nl !== false ? substr($html, 0, $nl) : 0);
        if ($expires > 0 && time() > $expires) {
            @unlink($file);
            self::$key = $key;
            return;
        }
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Cache: HIT');
        echo substr($html, $nl + 1);
        exit;
    }

    /** Response::html 出口：仅匿名缓存计划内的 200 HTML 落盘 */
    public static function store(string $html, int $code): void
    {
        $key = self::$key;
        if ($key === null || $code !== 200) {
            return;
        }
        self::$key = null; // 只写一次
        $dir = STORAGE_PATH . '/cache/pages';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return;
        }
        // 头两行：过期时间戳、内容长度；内容跟在第三行起
        $payload = (time() + self::TTL) . "\n" . strlen($html) . "\n" . $html;
        $tmp = $dir . '/.tmp_' . uniqid('', true);
        if (@file_put_contents($tmp, $payload) !== false) {
            // 原子写：固定文件名读者可能随时在读，rename 保证中间态不可见
            @rename($tmp, $dir . '/' . $key);
        }
    }

    /** 全量失效：删除所有页面缓存（后台任一写操作都会触发） */
    public static function flushAll(): void
    {
        self::$key = null;
        $dir = STORAGE_PATH . '/cache/pages';
        if (!is_dir($dir)) {
            return;
        }
        // glob 含缓存文件与未完成的 .tmp_*；unlink 静默失败（权限）也不阻塞主流程
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** POST 写操作去向下一个 GET 打标：跳转后的 GET 页面要展示 flash，不读缓存 */
    public static function stampNextBypass(): void
    {
        if (PHP_SAPI !== 'cli' && PHP_SESSION_ACTIVE === session_status()) {
            $_SESSION['pagecache_bypass'] = true;
        }
    }

    /** 缓存键：host + path + 白名单 query。含白名单外参数的请求返回 null（不缓存） */
    private static function buildKey(string $path): ?string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return null;
        }
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            parse_str($qs, $params);
            foreach ($params as $k => $v) {
                if (!in_array($k, self::QUERY_ALLOW, true) || is_array($v)) {
                    return null;
                }
            }
            ksort($params);
            $qs = http_build_query($params);
        } else {
            $qs = '';
        }
        return md5($host . "\n" . $path . "\n" . $qs);
    }

    private static function pathOnly(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // 取反斜杠等异常输入，规范成 / 开头
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        // 结尾规范化：/links/ 与 /links 同键，避免双份缓存
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }
}
