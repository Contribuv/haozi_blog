<?php
declare(strict_types=1);

namespace Blog;

/**
 * 插件钩子总线（Typecho 风格）。
 *
 * 插件通过 Hook::on() 注册监听，核心在关键位置 Hook::emit() 触发。
 * 采用单一模型覆盖两类语义：
 *   - 动作钩子：监听器执行副作用并返回 null，载荷保持不变；
 *   - 过滤钩子：监听器返回新载荷（或改写数组载荷后返回），替换原载荷继续传递。
 */
class Hook
{
    /** @var array<string, list<array{0:int, 1:callable}>> 钩子名 => [优先级, 回调] */
    private static array $listeners = [];
    /** @var array<string, bool> 各钩子是否已按优先级排序 */
    private static array $sorted = [];

    /** 注册监听器；priority 数值越小越先执行（默认 10） */
    public static function on(string $name, callable $fn, int $priority = 10): void
    {
        self::$listeners[$name][] = [$priority, $fn];
        self::$sorted[$name] = false;
    }

    /** 指定钩子是否已注册监听器 */
    public static function has(string $name): bool
    {
        return !empty(self::$listeners[$name]);
    }

    /**
     * 触发钩子。监听器签名 callable(mixed $payload, array $ctx): mixed，
     * 返回非 null 视为新载荷并向后传递，返回 null 表示不改动载荷。
     */
    public static function emit(string $name, mixed $payload = null, array $ctx = []): mixed
    {
        if (empty(self::$listeners[$name])) {
            return $payload;
        }
        if (empty(self::$sorted[$name])) {
            usort(self::$listeners[$name], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            self::$sorted[$name] = true;
        }
        foreach (self::$listeners[$name] as [, $fn]) {
            $ret = $fn($payload, $ctx);
            if ($ret !== null) {
                $payload = $ret;
            }
        }
        return $payload;
    }

    /** 清空全部监听器（测试用） */
    public static function reset(): void
    {
        self::$listeners = [];
        self::$sorted = [];
    }
}