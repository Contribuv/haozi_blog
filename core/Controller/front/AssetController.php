<?php
declare(strict_types=1);

namespace Blog\Controller\front;

use Blog\Controller\BaseController;
use Blog\Service\Avatar;
use Blog\Service\Upload;

/**
 * 静态资源与上传文件控制器（对照原项目 uploaded_file / theme_static /
 * /static & /share-thumb & avatar_proxy）。
 */
class AssetController extends BaseController
{
    /** /uploads/<path:filename> */
    public function upload(string $filename): void
    {
        $abs = Upload::resolve($filename);
        if ($abs === null || !is_file($abs)) {
            $this->abort404();
        }
        $this->serve($abs, 604800);
    }

    /** /static/<filename>（项目根 static/ 下资源） */
    public function static(string $filename): void
    {
        $this->serveFrom('/static', $filename);
    }

    /** /themes/<path:filename>（主题静态资源，filename 含主题目录名） */
    public function themeStatic(string $filename): void
    {
        $this->serveFrom('/themes', $filename);
    }

    /** 从 $subdir 下取文件并输出，路径穿越由 safeJoin 兜住 */
    private function serveFrom(string $subdir, string $filename): void
    {
        $abs = $this->safeJoin(PHP_BLOG_ROOT . $subdir, $filename);
        if ($abs === null || !is_file($abs)) {
            $this->abort404();
        }
        $this->serve($abs, 604800);
    }

    /** /share-thumb/<path:filename>：4:3 分享缩略图（懒生成 + 缓存） */
    public function shareThumb(string $filename): void
    {
        $out = Upload::shareThumb($filename);
        if ($out === null || !is_file($out)) {
            $this->abort404();
        }
        $this->serve($out, 86400);
    }

    /** /avatar/<key>/<int:size>.png：站内头像代理（轮换图源 + 磁盘缓存） */
    public function avatar(string $key, string $size): void
    {
        $size = (int) $size;
        if (str_starts_with($key, 'q') && ctype_digit(substr($key, 1))) {
            $qq = substr($key, 1);
            $s = Avatar::qqAvatarSize($size);
            $sources = [];
            foreach ([1, 2, 3, 4] as $i) {
                $sources[] = "https://q{$i}.qlogo.cn/g?b=qq&nk={$qq}&s={$s}";
            }
        } elseif (str_starts_with($key, 'e') && preg_match('/^[0-9a-f]{32}$/', substr($key, 1))) {
            $md5 = substr($key, 1);
            $s = max(1, min($size, 640));
            $sources = [
                "https://cravatar.cn/avatar/{$md5}?s={$s}&d=404",
                "https://weavatar.com/avatar/{$md5}?s={$s}&d=404",
            ];
        } else {
            $this->abort404();
            return;
        }

        $dir = STORAGE_PATH . '/cache/avatars';
        $maxAge = 86400 * 7;
        $file = $dir . '/' . $key . '_' . $s . '.png';
        if (is_file($file) && (time() - filemtime($file)) < $maxAge) {
            $this->serve($file, $maxAge, 'image/png');
            return;
        }
        // 前台绝不联网：未命中直接 404，响应后异步预热自愈（下次刷新可见）
        $this->warmAvatarLater($file, $sources);
        $this->abort404();
    }

    /**
     * 响应结束后异步预热头像：前台请求只读磁盘缓存，绝不在此线程 curl 外网
     * —— 外网图源慢/挂时会成片占满 php-fpm worker 拖垮整站，与 /readme-img 同一口径。
     * 全部图源失败写 1 小时 fail 标记，避免图源故障时每个访客请求都空跑一次。
     */
    private function warmAvatarLater(string $file, array $sources): void
    {
        $fail = $file . '.fail';
        if (is_file($fail) && (time() - filemtime($fail)) < 3600) {
            return;
        }
        $dir = dirname($file);
        // 非 static 闭包以便调用 fetchImage；fastcgi_finish_request 先发完响应再预热
        register_shutdown_function(function () use ($dir, $file, $fail, $sources): void {
            if (function_exists('fastcgi_finish_request')) {
                @set_time_limit(0);
                @fastcgi_finish_request();
            }
            foreach ($sources as $url) {
                [$data, $mime] = $this->fetchImage($url);
                if ($data !== null) {
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                    @file_put_contents($file, $data, LOCK_EX);
                    @unlink($fail);
                    return;
                }
            }
            @touch($fail);
        });
    }

    /** 单张代理图片大小上限：8MB，与 README 图片本地化口径一致 */
    private const README_IMG_MAX = 8388608;

    /** 代理缓存有效期（秒） */
    private const README_IMG_TTL = 604800;

    /**
     * /readme-img?u=<base64url>：README 外链图片站内代理。
     * 刻意只读磁盘缓存、绝不联网 —— 前台请求一旦 curl 外网（被墙时最长 10 秒），
     * 会成片占满 php-fpm worker，拖垮整站。缓存由后台同步期预热写入。
     */
    public function readmeImage(): void
    {
        $url = self::b64urlDecode((string) ($_GET['u'] ?? ''));
        if ($url === '') {
            $this->abort404();
        }
        $file = self::cacheFile($url);
        if (!is_file($file)) {
            // shields.io 徽章就地渲染：该图源国内连通性极差，同步预热经常整体失败，
            // 与其赌网络，不如按 URL 规则本地生成同款 SVG —— 零外网依赖，必定可见
            $badge = self::renderBadgeSvg($url);
            if ($badge !== null) {
                $this->serveImageBytes($badge, self::README_IMG_TTL);
            }
            // 其余外链图：本次 404，响应后异步预热自愈（下次刷新可见），与头像同一口径
            $this->warmReadmeImageLater($file, $url);
            $this->abort404();
        }
        $this->serveImageBytes((string) file_get_contents($file), self::README_IMG_TTL);
    }

    /**
     * 响应结束后异步预热代理缓存：前台线程绝不 curl 外网。
     * 失败写 1 小时 fail 标记，避免图源故障时每个访客请求都空跑一次。
     */
    private function warmReadmeImageLater(string $file, string $url): void
    {
        $fail = $file . '.fail';
        if (is_file($fail) && (time() - filemtime($fail)) < 3600) {
            return;
        }
        $b64 = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
        register_shutdown_function(static function () use ($file, $fail, $b64): void {
            if (function_exists('fastcgi_finish_request')) {
                @set_time_limit(0);
                @fastcgi_finish_request();
            }
            self::warmReadmeImage($b64);
            if (is_file($file)) {
                @unlink($fail);
            } else {
                @touch($fail);
            }
        });
    }

    /** 仅认 shields.io 的 /badge/ 路径；其余外链返回 null 交回网络预热 */
    private static function renderBadgeSvg(string $url): ?string
    {
        $p = parse_url($url);
        if (!is_array($p)) {
            return null;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host !== 'img.shields.io' && $host !== 'shields.io') {
            return null;
        }
        if (!preg_match('#^/badge/(.+)$#', (string) ($p['path'] ?? ''), $m)) {
            return null;
        }
        // shields 路径语法：<label>-<message>-<color>，末段恒为颜色；_ 代表空格
        $parts = explode('-', $m[1]);
        $rawColor = strtolower((string) array_pop($parts));
        $named = [
            'brightgreen' => '#4c1', 'green' => '#4c1', 'yellowgreen' => '#a3c51c',
            'yellow' => '#dfb317', 'orange' => '#fe7d37', 'red' => '#e05d44',
            'blue' => '#007ec6', 'lightgrey' => '#9f9f9f', 'grey' => '#555', 'gray' => '#555',
            'success' => '#4c1', 'important' => '#fe7d37', 'critical' => '#e05d44',
            'informational' => '#007ec6', 'inactive' => '#9f9f9f',
        ];
        if (isset($named[$rawColor])) {
            $color = $named[$rawColor];
        } elseif (preg_match('/^[0-9a-f]{3}([0-9a-f]{3})?$/', $rawColor)) {
            $color = '#' . $rawColor;
        } else {
            return null; // 不是标准 badge 语法，交回网络预热
        }
        $label = count($parts) >= 2 ? (string) array_shift($parts) : '';
        $msg = implode('-', $parts);
        $label = self::xmlText($label);
        $msg = self::xmlText($msg);

        $fs = 11.0;
        $labelW = $label === '' ? 0.0 : strlen($label) * 7.2 + 10;
        $msgW = strlen($msg) * 7.2 + 10;
        $w = round($labelW + $msgW, 1);
        $labelX = round($labelW / 2, 1);
        $msgX = round($labelW + $msgW / 2, 1);
        $ys = (string) (int) round($fs + 3.4);
        $aria = self::xmlText(trim($label . ': ' . $msg, ': '));

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="20" role="img" aria-label="' . $aria . '">'
            . '<clipPath id="r"><rect width="' . $w . '" height="20" rx="3" fill="#fff"/></clipPath>'
            . '<g clip-path="url(#r)">'
            . '<rect width="' . round($labelW, 1) . '" height="20" fill="#555"/>'
            . '<rect x="' . round($labelW, 1) . '" width="' . round($msgW, 1) . '" height="20" fill="' . $color . '"/>'
            . '</g>'
            . '<g fill="#fff" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="' . $fs . '" text-anchor="middle">'
            . ($label === '' ? '' : '<text x="' . $labelX . '" y="' . $ys . '">' . $label . '</text>')
            . '<text x="' . $msgX . '" y="' . $ys . '">' . $msg . '</text>'
            . '</g></svg>';
    }

    /** 徽章文案转义 + 下划线还原为空格（shields 语法） */
    private static function xmlText(string $s): string
    {
        $s = str_replace('_', ' ', rawurldecode($s));
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** 代理缓存文件路径：md5 定名，键恒为 32 位十六进制，无法路径穿越 */
    private static function cacheFile(string $url): string
    {
        return STORAGE_PATH . '/cache/readme-img/' . md5($url);
    }

    /**
     * 后台同步期预热：下载并校验后写入缓存，供前台只读。
     * 仅在同步进程（ProjectSync / 后台加项目）中调用，可容忍网络等待；
     * README 由第三方仓库控制，必须走 SSRF 校验与魔数校验，否则此函数会变成打内网的跳板。
     */
    public static function warmReadmeImage(string $b64): void
    {
        $url = self::b64urlDecode($b64);
        if ($url === '' || !self::isSafeRemoteUrl($url)) {
            return;
        }
        $file = self::cacheFile($url);
        if (is_file($file) && (time() - filemtime($file)) < self::README_IMG_TTL) {
            return;
        }
        $data = self::fetchRemoteImage($url);
        // 非图片（HTML/脚本等）不入缓存，避免前台把任意内容当图片输出
        if ($data === null || self::detectImageMime($data) === null) {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, $data, LOCK_EX);
    }

    /** 输出图片字节：MIME 按魔数判定（非图片拒绝）；SVG 追加沙箱 CSP，防直接访问执行脚本 */
    private function serveImageBytes(string $data, int $maxAge): void
    {
        $mime = self::detectImageMime($data);
        if ($mime === null) {
            $this->abort404();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: public, max-age=' . $maxAge);
        header('X-Content-Type-Options: nosniff');
        if ($mime === 'image/svg+xml') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        echo $data;
        exit;
    }

    /** 按魔数识别图片 MIME；非图片（含 HTML/脚本）返回 null */
    private static function detectImageMime(string $data): ?string
    {
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($data, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }
        if (in_array(substr($data, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return 'image/gif';
        }
        if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($data, "\x00\x00\x01\x00")) {
            return 'image/x-icon';
        }
        // SVG：shields.io 徽章即此格式，文本判定；输出侧已加沙箱 CSP
        if (stripos(substr($data, 0, 512), '<svg') !== false) {
            return 'image/svg+xml';
        }
        return null;
    }

    /** 校验可安全代理的远端地址：http(s) + 公网主机 + 80/443 端口 */
    private static function isSafeRemoteUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['host'])) {
            return false;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port !== 80 && $port !== 443) {
            return false;
        }
        $host = trim((string) $p['host'], '[]');
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }
        // 域名先解析再逐个校验，任一落在内网/保留段即拒绝
        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }
        return true;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** 下载远端图片字节；仅后台预热调用（前台已改为只读缓存）；失败或超限返回 null */
    private static function fetchRemoteImage(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        \Blog\Service\Http::applyCurl($ch); // 注入 CA 证书，Windows 下 curl 默认不带 CA 包
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => self::README_IMG_MAX,
            CURLOPT_USERAGENT => 'infowe-blog',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $data = (string) $body;
        return ($data === '' || strlen($data) > self::README_IMG_MAX) ? null : $data;
    }

    /** base64url 解码（HTTP query 里 padding 已去掉），非法输入返回 '' */
    private static function b64urlDecode(string $s): string
    {
        if ($s === '' || preg_match('/[^A-Za-z0-9\-_]/', $s)) {
            return '';
        }
        $d = base64_decode(strtr($s, '-_', '+/'), true);
        return $d === false ? '' : $d;
    }

    /** 输出文件（含条件请求与缓存头） */
    private function serve(string $abs, int $maxAge, ?string $mime = null): void
    {
        $mime = $mime ?? $this->mimeOf($abs);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: public, max-age=' . $maxAge);
        readfile($abs);
        exit;
    }

    private function mimeOf(string $abs): string
    {
        $ext = strtolower((string) pathinfo($abs, PATHINFO_EXTENSION));
        return match ($ext) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'txt', 'md' => 'text/plain; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    /** 拼接并校验路径落在 $base 内，防路径穿越 */
    private function safeJoin(string $base, string $rel): ?string
    {
        $rel = ltrim(str_replace('\\', '/', rawurldecode($rel)), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $abs = realpath($base . '/' . $rel);
        $baseReal = realpath($base);
        if ($abs === false || $baseReal === false || !str_starts_with($abs, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abs;
    }

    /** 抓取图片字节，返回 [data|null, mime] */
    private function fetchImage(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [null, ''];
        }
        \Blog\Service\Http::applyCurl($ch); // 注入 CA 证书（跨命名空间完全限定）
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'infowe-blog',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300 || $body === '') {
            return [null, ''];
        }
        return [(string) $body, $mime !== '' ? $mime : 'image/png'];
    }
}
