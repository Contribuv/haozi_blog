<?php
declare(strict_types=1);

namespace Blog;

/**
 * 路由分发器：path 规则兼容 Flask 风格占位符
 *   <name>        → 单段（不含 /）
 *   <int:name>    → 数字
 *   <float:name>  → 浮点
 *   <path:name>   → 任意（可含 /）
 * 其余静态字符做 preg_quote，避免 . 等正则元字符误判。
 */
class Router
{
    /** @var array<string, array<string, callable|string>> */
    private array $routes = [];

    /** @var callable|null 404 处理器 */
    private $fallback = null;

    public function get(string $path, callable|string $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable|string $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    /** 同时注册 GET 与 POST */
    public function any(string $path, callable|string $handler): void
    {
        $this->get($path, $handler);
        $this->post($path, $handler);
    }

    /**
     * 批量注册。
     * @param array<int, array{0:string,1:string,2:callable|string}> $list [method, path, handler]
     */
    public function register(array $list): void
    {
        foreach ($list as $item) {
            $method = strtoupper($item[0]);
            if ($method === 'ANY') {
                $this->any($item[1], $item[2]);
            } elseif ($method === 'POST') {
                $this->post($item[1], $item[2]);
            } else {
                $this->get($item[1], $item[2]);
            }
        }
    }

    public function setFallback(callable $handler): void
    {
        $this->fallback = $handler;
    }

    public function dispatch(): void
    {
        $method = Request::method();
        $uri = Request::uri();
        $handlers = $this->routes[$method] ?? [];
        foreach ($handlers as $path => $handler) {
            if ($this->match($path, $uri, $params)) {
                $this->invoke($handler, $params);
                return;
            }
        }
        if ($this->fallback !== null) {
            ($this->fallback)();
            return;
        }
        http_response_code(404);
        echo 'Not Found';
        exit;
    }

    /** 调用处理器：字符串 'front/XxxController@action' 或闭包 */
    private function invoke(callable|string $handler, array $params): void
    {
        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }
        [$class, $action] = explode('@', $handler);
        $cls = 'Blog\\Controller\\' . str_replace('/', '\\', $class);
        (new $cls())->$action(...array_values($params));
    }

    /**
     * 规则匹配。捕获组按名解码后写入 $params（值已 rawurldecode）。
     */
    private function match(string $path, string $uri, ?array &$params): bool
    {
        $params = [];
        if ($path === $uri) {
            return true;
        }
        $pattern = $this->buildPattern($path);
        if (preg_match($pattern, $uri, $matches)) {
            foreach ($matches as $k => $v) {
                if (is_int($k)) {
                    continue;
                }
                $params[$k] = rawurldecode((string) $v);
            }
            return true;
        }
        return false;
    }

    /** 把规则编译为正则：静态部分 preg_quote，占位符支持 int/float/path/string */
    private function buildPattern(string $path): string
    {
        $out = '';
        $offset = 0;
        if (preg_match_all('#<((?:int|float|path|string):)?(\w+)>#', $path, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $idx => $whole) {
                $pos = (int) $whole[1];
                $out .= preg_quote(substr($path, $offset, $pos - $offset), '#');
                $type = str_replace(':', '', (string) $m[1][$idx][0]);
                $name = (string) $m[2][$idx][0];
                $out .= match ($type) {
                    'int' => '(?P<' . $name . '>\d+)',
                    'float' => '(?P<' . $name . '>[0-9]+(?:\.[0-9]+)?)',
                    'path' => '(?P<' . $name . '>.+)',
                    default => '(?P<' . $name . '>[^/]+)',
                };
                $offset = $pos + strlen((string) $whole[0]);
            }
        }
        $out .= preg_quote(substr($path, $offset), '#');
        return '#^' . $out . '$#';
    }
}
