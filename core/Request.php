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
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        return (string) ($_SERVER['HTTP_HOST']
            ?? ('127.0.0.1' . (($https ? 443 : 80) === (int) ($_SERVER['SERVER_PORT'] ?? 80) ? '' : ':' . ($_SERVER['SERVER_PORT'] ?? '80'))));
    }

    /** 站点根地址（scheme://host，无尾斜杠），对照 Flask request.host_url.rstrip('/') */
    public static function baseUrl(): string
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        return ($https ? 'https' : 'http') . '://' . self::host();
    }

    /** 客户端 IP（优先取反代头，对照原项目 _client_ip） */
    public static function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            $v = $_SERVER[$k] ?? '';
            if ($v !== '') {
                $first = trim(explode(',', $v)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }
        return 'unknown';
    }
}
