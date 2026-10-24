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
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        return ($https ? 'https' : 'http') . '://' . self::host();
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
