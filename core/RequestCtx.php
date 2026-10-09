<?php
namespace Blog;

/**
 * 模板内的 request 全局对象（等价 Flask request: path/url/url_root/args）
 */
class RequestCtx
{
    public string $path;
    public string $url;
    public string $url_root;
    public string $method;
    public array $args;

    public function __construct()
    {
        $this->path = Request::uri();
        $this->method = Request::method();
        $this->args = $_GET;
        $https = strtolower($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ('127.0.0.1' . (($https ? 443 : 80) === (int)($_SERVER['SERVER_PORT'] ?? 80) ? '' : ':' . $_SERVER['SERVER_PORT']));
        $root = $scheme . '://' . $host;
        $this->url_root = $root . '/';
        $this->url = $root . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    /** dict 语义：request.args.get(k, d) */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->args[$key] ?? $default;
    }

    /** 未声明属性访问时返回 null（Jinja 宽松语义） */
    public function __get(string $name): mixed
    {
        return null;
    }
}
