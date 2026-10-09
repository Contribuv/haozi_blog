<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Project;

/**
 * 项目 GitHub 同步任务（对照原项目 project_sync_state / _sync_projects_background /
 * _start_project_sync）。
 *
 * PHP 无跨请求常驻线程，改为「自驱动队列」：start() 仅登记任务并入队，
 * 由前端轮询的 /admin/projects/sync-status 每次推进一个项目，
 * 状态经 data/project_sync_state.json 持久化，跨进程/重启可读。
 */
class ProjectSync
{
    /** 完成后保留提示的最长时间（秒）：超过则视为空闲，避免常驻「同步完成」条 */
    private const FINISHED_TTL = 600;

    /** 运行中但长时间无心跳视为中断（秒）：防止进程被中断后永久卡在「同步中」 */
    private const STUCK_TTL = 120;

    /** 状态默认值 */
    private static function defaults(): array
    {
        return [
            'running' => false, 'finished' => false,
            'total' => 0, 'done' => 0, 'ok' => 0, 'failed' => 0,
            'current' => '', 'summary' => '', 'saved_at' => 0,
            'remaining' => [],
        ];
    }

    private static function stateFile(): string
    {
        $dir = PHP_BLOG_ROOT . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/project_sync_state.json';
    }

    /** 从磁盘读取原始状态（不做陈旧清理） */
    public static function read(): array
    {
        $f = self::stateFile();
        if (!is_file($f)) {
            return self::defaults();
        }
        $data = json_decode((string) file_get_contents($f), true);
        if (!is_array($data)) {
            return self::defaults();
        }
        $st = self::defaults();
        foreach ($st as $k => $_) {
            if (array_key_exists($k, $data)) {
                $st[$k] = $data[$k];
            }
        }
        $st['remaining'] = is_array($st['remaining']) ? array_map('intval', $st['remaining']) : [];
        return $st;
    }

    /** 原子写入状态（tmp + rename） */
    public static function write(array $st): void
    {
        $f = self::stateFile();
        $tmp = $f . '.tmp';
        @file_put_contents($tmp, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($tmp, $f);
    }

    /** 对外状态：完成超时视为空闲；运行中长时间无心跳视为中断 */
    public static function state(): array
    {
        $st = self::read();
        if ($st['running'] && (time() - (int) $st['saved_at']) > self::STUCK_TTL) {
            $st = self::defaults();
            self::write($st);
            return $st;
        }
        if ($st['finished'] && (time() - (int) $st['saved_at']) > self::FINISHED_TTL) {
            $st['finished'] = false;
            $st['summary'] = '';
        }
        return $st;
    }

    /** 消费完成状态（页面自动刷新前调用）：运行中不可消费 */
    public static function consume(): array
    {
        $st = self::state();
        if ($st['finished'] && !$st['running']) {
            $st = self::defaults();
            $st['saved_at'] = time();
            self::write($st);
        }
        return $st;
    }

    /** 登记同步任务入队；本进程或磁盘显示已在同步则返回 false */
    public static function start(?int $singleId = null): bool
    {
        if (self::state()['running']) {
            return false;
        }
        $ids = [];
        foreach (Project::load() as $p) {
            if ($singleId === null || (int) $p['id'] === $singleId) {
                $ids[] = (int) $p['id'];
            }
        }
        $st = self::defaults();
        $st['running'] = true;
        $st['total'] = count($ids);
        $st['remaining'] = $ids;
        $st['saved_at'] = time();
        self::write($st);
        return true;
    }

    /** 推进一个项目（由轮询接口调用）。返回推进后的状态。 */
    public static function advance(): array
    {
        $st = self::read();
        if (!$st['running']) {
            return $st;
        }
        $remaining = $st['remaining'];
        if (!$remaining) {
            return self::finalize($st);
        }

        $id = (int) array_shift($remaining);
        $st['remaining'] = $remaining;
        $p = Project::get($id);

        if ($p === null) {
            $st['done']++;
            $st['saved_at'] = time();
            self::write($st);
            return self::read();
        }

        $url = (string) ($p['url'] ?? '');
        $slug = Github::parseRepo($url) ?? '';
        $name = trim((string) ($p['name'] ?? ''));
        $st['current'] = $name !== '' ? $name : ($slug !== '' ? $slug : $url);

        if ($slug === '') {
            $st['done']++;
            $st['saved_at'] = time();
            self::write($st);
            return self::read();
        }

        try {
            $gh = Github::fetchRepo($url);
            if (!$gh) {
                $st['failed']++;
            } else {
                // 拉 README 并本地化图片（写磁盘缓存）+ 更新项目实时数据
                ProjectCache::put($slug, Github::fetchProject($url));
                Project::save([
                    'url' => $gh['url'],
                    'sort_order' => (int) ($p['sort_order'] ?? 0),
                    'featured' => !empty($p['featured']) ? '1' : '',
                ], $id, true);
                $st['ok']++;
            }
        } catch (\Throwable $e) {
            $st['failed']++;
        }
        $st['done']++;
        $st['saved_at'] = time();

        if (!$remaining) {
            return self::finalize($st);
        }
        self::write($st);
        return self::read();
    }

    /** 收尾：写入完成摘要并结束运行态 */
    private static function finalize(array $st): array
    {
        $st['running'] = false;
        $st['finished'] = true;
        $st['current'] = '';
        $st['summary'] = '同步完成：成功 ' . $st['ok'] . ' 个，失败 ' . $st['failed'] . ' 个';
        $st['saved_at'] = time();
        $st['remaining'] = [];
        self::write($st);
        return $st;
    }

    /**
     * 全量同步工作体（由 bin/project_sync.php CLI 调用，供 cron/运维手动触发）。
     * $singleId 为 null 表示同步全部；否则仅同步该 id。
     */
    public static function runWorker(?int $singleId = null): void
    {
        if (self::state()['running']) {
            return;
        }
        if (!self::start($singleId)) {
            return;
        }
        while (true) {
            $st = self::advance();
            if (!$st['running']) {
                break;
            }
            if (!$st['remaining']) {
                break;
            }
        }
    }
}