<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * 会话闪存消息（等价 Flask flash()）。
 * 结构：$_SESSION['_flashes'][] = [category, message]，由 Runtime::getFlashed 读取并清空。
 */
class Flash
{
    public static function add(string $message, string $category = 'message'): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['_flashes'][] = [$category, $message];
    }

    public static function success(string $message): void
    {
        self::add($message, 'success');
    }

    public static function error(string $message): void
    {
        self::add($message, 'error');
    }

    public static function info(string $message): void
    {
        self::add($message, 'info');
    }
}
