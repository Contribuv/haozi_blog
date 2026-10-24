<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;
use Blog\Request;

/**
 * 站点地址（site_url）与访问规范化。
 *
 * 设计参考 Typecho：site_url 是生成绝对链接的权威地址，但程序层只做
 * 「http → https」与「同主域 www 归一」两种 301，不做任意域名强制跳转，
 * 避免用户填错地址后把自己锁在门外。
 *
 * 优先级：
 *   1. config.php 的 app.site_url（显式覆盖，本地想测 301 时用）
 *   2. 本地 / 私网 / *.test / *.local 访问 → 跟随当前请求（127.0.0.1 调试零配置）
 *   3. 后台「站点地址」设置
 *   4. 兜底跟随当前请求
 */
class SiteUrl
{
    /** 后台设置键 */
    public const SETTING_URL = 'site_url';
    public const SETTING_FORCE_HTTPS = 'force_https';
    public const SETTING_UNIFY_WWW = 'unify_www';

    /** 301 豁免前缀：后台是逃生通道，API / 静态资源 / ACME 不参与规范化 */
    private const EXEMPT_PREFIXES = [
        '/admin', '/install.php', '/.well-known/',
        '/api/', '/avatar/', '/uploads/', '/static/', '/themes/', '/bin/',
    ];

    /** 权威站点根地址（scheme://host[:port]，无尾斜杠） */
    public static function base(): string
    {
        $override = self::configOverride();
        if ($override !== '') {
            return self::upgradeScheme($override);
        }
        if (PHP_SAPI !== 'cli' && Request::isLocalHost()) {
            return Request::baseUrl();
        }
        $configured = self::dbValue();
        if ($configured === '') {
            return Request::baseUrl();
        }
        return self::upgradeScheme($configured);
    }

    /** 只取 scheme://host[:port]，用于 301 目标（忽略配置里可能的多余路径） */
    public static function origin(): string
    {
        $p = parse_url(self::base());
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return self::base();
        }
        return strtolower((string) $p['scheme']) . '://' . strtolower((string) $p['host'])
            . (isset($p['port']) ? ':' . (int) $p['port'] : '');
    }

    /** 站点 host（不含 scheme / 端口），用于水印等只想要域名的场景 */
    public static function host(): string
    {
        $p = parse_url(self::base());
        return is_array($p) && !empty($p['host']) ? (string) $p['host'] : Request::host();
    }

    /** 后台是否开启「强制 HTTPS」；未显式保存时跟随站点地址的 scheme */
    public static function forceHttps(): bool
    {
        $v = Settings::get(self::SETTING_FORCE_HTTPS, null);
        if ($v === null || $v === '') {
            return str_starts_with(strtolower(self::origin()), 'https://');
        }
        return !in_array((string) $v, ['0', 'false', 'no'], true);
    }

    /** 后台是否开启「统一 www」 */
    public static function unifyWww(): bool
    {
        return Settings::bool(self::SETTING_UNIFY_WWW, false);
    }

    /**
     * 访问规范化：GET / HEAD 且命中以下任一情况时 301。
     * - 站点地址是 https、开启强制 HTTPS，而当前是 http；
     * - 开启统一 www，当前 host 与站点地址 host 仅差一个 www. 前缀。
     * 只跳协议与 www，不跨域名；后台 / 安装向导 / API / 已知静态目录豁免。
     */
    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        // 本地调试且没有 config 显式覆盖时不跳，保证 127.0.0.1 / localhost 正常
        if (self::configOverride() === '' && Request::isLocalHost()) {
            return;
        }
        $method = Request::method();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }
        if (self::isExempt(Request::uri())) {
            return;
        }
        $configured = self::origin();
        if ($configured === '') {
            return;
        }
        $target = parse_url($configured);
        if (!is_array($target) || empty($target['scheme']) || empty($target['host'])) {
            return;
        }
        $targetScheme = strtolower((string) $target['scheme']);
        if (!in_array($targetScheme, ['http', 'https'], true)) {
            return;
        }
        $targetHost = strtolower((string) $target['host']);
        $targetPort = isset($target['port']) ? (int) $target['port'] : null;

        $currentScheme = Request::isSecure() ? 'https' : 'http';
        $currentHostFull = strtolower(Request::host());
        $currentHost = self::hostOnly($currentHostFull);
        $currentPort = self::portOf($currentHostFull, $currentScheme);

        $wantScheme = $currentScheme;
        $wantHost = $currentHost;
        $wantPort = $currentPort;

        // 只对「同一个站点」做规范化：host 相同，或开启统一 www 时仅差 www 前缀。
        // 不同域名（临时域名、IP、预览域名）一律不跳，避免误伤与自锁。
        $sameHost = $currentHost === $targetHost
            || (self::unifyWww() && self::isWwwVariant($currentHost, $targetHost));

        // ① http → https（只升不降，避免把 https 访问者赶回 http）
        if ($sameHost && self::forceHttps() && $targetScheme === 'https' && $currentScheme !== 'https') {
            $wantScheme = 'https';
            $wantPort = $targetPort ?? 443;
        }
        // ② 同主域 www 归一；target 的 host 就是最终写法
        if (self::unifyWww() && $sameHost && self::isWwwVariant($currentHost, $targetHost)) {
            $wantHost = $targetHost;
            $wantPort = $targetPort ?? ($wantScheme === 'https' ? 443 : 80);
        }

        if ($wantScheme === $currentScheme && $wantHost === $currentHost && $wantPort === $currentPort) {
            return;
        }

        $hostOut = $wantHost;
        $defaultPort = $wantScheme === 'https' ? 443 : 80;
        if ($wantPort !== null && $wantPort !== $defaultPort) {
            $hostOut .= ':' . $wantPort;
        }
        $location = $wantScheme . '://' . $hostOut . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $location, true, 301);
        exit;
    }

    /**
     * 后台保存时校验并规范化站点地址。
     * 返回 ''（表示留空/跟随当前请求），或 scheme://host[:port]；不合法返回 null。
     */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $p = parse_url($raw);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        $scheme = strtolower((string) $p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        // 只支持站点根：不允许账号密码、query、fragment 与子目录
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $path = (string) ($p['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            return null;
        }
        $host = strtolower((string) $p['host']);
        $bare = trim($host, '[]');
        $isIp = filter_var($bare, FILTER_VALIDATE_IP) !== false;
        $isDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if (!$isIp && !$isDomain && $host !== 'localhost') {
            return null;
        }
        $port = isset($p['port']) ? (int) $p['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        $hostOut = ($isIp && str_contains($bare, ':')) ? '[' . $bare . ']' : $host;
        return $scheme . '://' . $hostOut . ($port !== null ? ':' . $port : '');
    }

    /** config.php 的 app.site_url 显式覆盖（本地调试/临时测试用） */
    private static function configOverride(): string
    {
        $cfg = $GLOBALS['config']['app']['site_url'] ?? '';
        return is_string($cfg) && trim($cfg) !== '' ? rtrim(trim($cfg), '/') : '';
    }

    /** 数据库里的后台设置（异常时静默回退，避免设置表问题拖垮全站） */
    private static function dbValue(): string
    {
        try {
            $v = trim((string) Settings::get(self::SETTING_URL, ''));
        } catch (\Throwable) {
            return '';
        }
        return $v === '' ? '' : rtrim($v, '/');
    }

    /** 当前请求已是 HTTPS 而配置仍是 http 时，把输出链接升级为 https（只升不降） */
    private static function upgradeScheme(string $url): string
    {
        if (Request::isSecure() && preg_match('#^http://#i', $url)) {
            return 'https://' . substr($url, 7);
        }
        return $url;
    }

    /** 301 豁免判断（精确前缀 + 子路径） */
    private static function isExempt(string $path): bool
    {
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /** 是否是同一个主域的 www / 非 www 两种写法 */
    private static function isWwwVariant(string $a, string $b): bool
    {
        return $a === 'www.' . $b || 'www.' . $a === $b;
    }

    /** 从 host[:port] 中取 host（IPv6 方括号安全） */
    private static function hostOnly(string $host): string
    {
        if (preg_match('#^\[([^\]]+)\](?::\d+)?$#', $host, $m)) {
            return strtolower($m[1]);
        }
        $pos = strrpos($host, ':');
        if ($pos !== false && strpos($host, ':') === $pos) {
            $host = substr($host, 0, $pos);
        }
        return strtolower(trim($host, '[]'));
    }

    /** 当前请求端口；取不到时按 scheme 默认 */
    private static function portOf(string $hostFull, string $scheme): int
    {
        if (preg_match('#^\[[^\]]+\]:(\d+)$#', $hostFull, $m)) {
            return (int) $m[1];
        }
        if (preg_match('#^[^:]+:(\d+)$#', $hostFull, $m)) {
            return (int) $m[1];
        }
        $serverPort = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        if ($serverPort > 0) {
            return $serverPort;
        }
        return $scheme === 'https' ? 443 : 80;
    }
}
