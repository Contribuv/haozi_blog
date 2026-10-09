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

    /** 客户端 IP：仅当 REMOTE_ADDR 本身是回环/私有地址（即确实在反代后面）才采信 XFF 头 */
    public static function ip(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote !== '' && self::isPrivateIp($remote)) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $k) {
                $v = $_SERVER[$k] ?? '';
                if ($v === '') {
                    continue;
                }
                // XFF 是一串「客户端, 代理1, 代理2」，最左侧才是真实来源；
                // 但仍要逐段校验格式，否则伪造头可以把任意字符串塞进日志和锁定的键。
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

    /** 判断 IP 是否回环/私有/保留段（含 IPv6 对应写法），用于判定是否处于反代之后 */
    private static function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
