<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * 项目 GitHub 数据快照缓存（对照原项目 project_cache_snapshot / _persist_project_cache）。
 * 前台只读快照，绝不发起网络请求；写入仅由后台「同步」触发。
 */
class ProjectCache
{
    private static ?array $memo = null;

    private static function file(): string
    {
        $dir = STORAGE_PATH . '/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/project_gh.json';
    }

    /** 锁文件，put/touch 的读改写需互斥，防止并发写丢更新 */
    private static function lockFile(): string
    {
        return self::file() . '.lock';
    }

    private static function loadAll(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $f = self::file();
        if (!is_file($f)) {
            return self::$memo = [];
        }
        $d = json_decode((string) file_get_contents($f), true);
        return self::$memo = (is_array($d) ? $d : []);
    }

    /** 加锁后从磁盘读取最新快照（写操作必须走这里，不能用 memo） */
    private static function loadAllLocked(): array
    {
        $f = self::file();
        if (!is_file($f)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    /** 只读快照；无缓存返回 null */
    public static function snapshot(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }
        $all = self::loadAll();
        $entry = $all[$slug] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /** 写入某 slug 的快照并持久化（加锁，读改写原子） */
    public static function put(string $slug, array $entry): void
    {
        if ($slug === '') {
            return;
        }
        self::withLock(static function () use ($slug, $entry): void {
            $all = self::loadAllLocked();
            $all[$slug] = $entry;
            self::$memo = $all;
            self::persist($all);
        });
    }

    /**
     * 仅刷新某 slug 快照的时间戳：自动同步检测到远端无更新时调用，
     * 使该快照重新「新鲜」，避免每个冷却周期都重复打 GitHub 检测接口。
     */
    public static function touch(string $slug, int $ts): void
    {
        if ($slug === '') {
            return;
        }
        self::withLock(static function () use ($slug, $ts): void {
            $all = self::loadAllLocked();
            if (!isset($all[$slug]) || !is_array($all[$slug])) {
                return;
            }
            $all[$slug]['ts'] = $ts;
            self::$memo = $all;
            self::persist($all);
        });
    }

    /** 加锁执行写操作，异常时解锁 */
    private static function withLock(callable $fn): void
    {
        $lock = @fopen(self::lockFile(), 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }
        try {
            $fn();
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /** 原子持久化（tmp + rename） */
    private static function persist(array $all): void
    {
        $f = self::file();
        $tmp = $f . '.tmp';
        @file_put_contents($tmp, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($tmp, $f);
    }
}
