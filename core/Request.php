<?php
namespace Blog;

class Request
{
    public static function uri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        return $pos === false ? $uri : substr($uri, 0, $pos);
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return $_REQUEST[$key] ?? $default;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /** 当前主机名（含端口），对照 Flask request.host */
    public static function host(): string
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return (string) $_SERVER['HTTP_HOST'];
        }
        // CLI 或未带 Host 头时兜底：仅当端口非默认时才补 ":port"
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        return '127.0.0.1' . (($https ? 443 : 80) === $port ? '' : ':' . $port);
    }

    /** 站点根地址（scheme://host，无尾斜杠），对照 Flask request.host_url.rstrip('/') */
    public static function baseUrl(): string
    {
        return (self::isSecure() ? 'https' : 'http') . '://' . self::host();
    }

    /**
     * 当前请求是否 HTTPS。
     * 反代场景仅当 REMOTE_ADDR 命中 config 的 trusted_proxies 时才采信
     * X-Forwarded-Proto / X-Forwarded-Port，避免直连时被伪造头骗过。
     */
    public static function isSecure(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        global $config;
        $trusted = is_array($config['trusted_proxies'] ?? null) ? $config['trusted_proxies'] : [];
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote !== '' && in_array($remote, $trusted, true)) {
            $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($proto === 'https' || $proto === 'quic') {
                return true;
            }
            if ((int) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? 0) === 443) {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否本地 / 私网调试访问：localhost、127/8、私网段、*.test / *.local、IPv6 回环。
     * 用于让 127.0.0.1 调试不受后台「站点地址」影响。
     */
    public static function isLocalHost(): bool
    {
        $host = self::hostOnly(self::host());
        if ($host === '' || $host === 'localhost' || $host === '::1') {
            return true;
        }
        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return true;
        }
        return (bool) preg_match(
            '/^(?:127\.|10\.|192\.168\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.)/',
            $host
        );
    }

    /** 从 host[:port] 中取纯 host（IPv6 方括号安全） */
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

    /** 客户端 IP：仅当 REMOTE_ADDR 命中 config 的 trusted_proxies 时才采信 XFF / X-Real-IP */
    public static function ip(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        // 若请求来自可信代理（配置列表），采信 XFF/REAL_IP
        global $config;
        $trusted = $config['trusted_proxies'] ?? [];
        if (in_array($remote, $trusted, true)) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $k) {
                $v = $_SERVER[$k] ?? '';
                if ($v === '') continue;
                foreach (explode(',', $v) as $part) {
                    $first = trim($part);
                    if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                        return $first;
                    }
                }
            }
        }
        if ($remote !== '' && $remote !== 'unknown') {
            return $remote;
        }
        return 'unknown';
    }

}
