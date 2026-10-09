<?php
declare(strict_types=1);

namespace Blog\Model;

/**
 * 站点设置：settings 表为 key-value 结构，整表一次读入内存缓存，避免每请求反复查询。
 */
class Settings extends Model
{
    /** @var array<string,string>|null 全表键值缓存 */
    private static ?array $cache = null;

    /** 读取全部设置（首次调用加载并缓存） */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (parent::rows('SELECT `key`, `value` FROM settings') as $r) {
                self::$cache[(string) $r['key']] = (string) $r['value'];
            }
        }
        return self::$cache;
    }

    /** 读取单项，不存在返回默认值 */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** 读取布尔开关（值非空且不为 '0' 视为真） */
    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        return !in_array($v, ['0', 'false', 'False', 'no'], true);
    }

    /** 读取整数设置 */
    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, null);
        return is_numeric($v) ? (int) $v : $default;
    }

    /** 写入单项（存在则覆盖），同步更新内存缓存 */
    public static function set(string $key, mixed $value): void
    {
        $val = is_string($value) ? $value : (string) $value;
        self::exec(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $val]
        );
        self::all();
        self::$cache[$key] = $val;
    }

    /** 批量写入 */
    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            self::set((string) $k, $v);
        }
    }

    /** 删除单项 */
    public static function remove(string $key): void
    {
        self::exec('DELETE FROM settings WHERE `key` = ?', [$key]);
        if (self::$cache !== null) {
            unset(self::$cache[$key]);
        }
    }

    /** 清空缓存（测试或外部修改后强制重载） */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
