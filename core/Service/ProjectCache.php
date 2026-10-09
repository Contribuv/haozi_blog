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

    /** 写入某 slug 的快照并持久化 */
    public static function put(string $slug, array $entry): void
    {
        if ($slug === '') {
            return;
        }
        $all = self::loadAll();
        $all[$slug] = $entry;
        self::$memo = $all;
        @file_put_contents(self::file(), json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
        $all = self::loadAll();
        if (!isset($all[$slug]) || !is_array($all[$slug])) {
            return;
        }
        $all[$slug]['ts'] = $ts;
        self::$memo = $all;
        @file_put_contents(self::file(), json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
