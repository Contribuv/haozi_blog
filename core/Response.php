<?php
namespace Blog;

class Response
{
    public static function html(string $html, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    public static function json(array $data, int $code = 200): void
    {
        // 兜底：PHP 的 Warning/Notice 可能已被 display_errors 写进输出缓冲，
        // 混在 JSON 前会让前端 JSON.parse 失败（例如 open_basedir 警告），先清空再输出。
        if (ob_get_length() !== false) {
            ob_clean();
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $url, int $code = 302): void
    {
        http_response_code($code);
        header('Location: ' . $url);
        exit;
    }
}