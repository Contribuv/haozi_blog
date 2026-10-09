<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Project;

/**
 * 项目 GitHub 同步任务（对照原项目 project_sync_state / _sync_projects_background /
 * _start_project_sync）。
 *
 * PHP 无跨请求常驻线程，改为「自驱动队列」：start() 仅登记任务并入队；
 * 推进由两处驱动 —— 后台轮询的 /admin/projects/sync-status，以及前台触发后
 * 在响应结束（fastcgi_finish_request）时执行的 drain()。
 * 状态经 data/project_sync_state.json 持久化，跨进程/重启可读。
 */
class ProjectSync
{
    /** 完成后保留提示的最长时间（秒）：超过则视为空闲，避免常驻「同步完成」条 */
    private const FINISHED_TTL = 600;

    /** 运行中但长时间无心跳视为中断（秒）：防止进程被中断后永久卡在「同步中」 */
    private const STUCK_TTL = 120;

    /** 自动同步间隔（秒）：项目快照早于此时长即视为过期 */
    private const AUTO_TTL = 21600;

    /** 状态默认值 */
    private static function defaults(): array
    {
        return [
            'running' => false, 'finished' => false,
            'total' => 0, 'done' => 0, 'ok' => 0, 'failed' => 0,
            'current' => '', 'summary' => '', 'saved_at' => 0,
            'remaining' => [], 'force' => false,
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

    /**
     * 登记同步任务入队；本进程或磁盘显示已在同步则返回 false。
     * $onlyIds 非空时只把这些 id 入队（自动同步用，避免把未过期的项目也重拉一遍）。
     * $force=false 表示自动同步：推进时先检测远端，无更新就跳过拉取；
     * $force=true（默认）表示手动同步：强制全量，不看是否有更新。
     */
    public static function start(?int $singleId = null, array $onlyIds = [], bool $force = true): bool
    {
        if (self::state()['running']) {
            return false;
        }
        $ids = [];
        foreach (Project::load() as $p) {
            $id = (int) $p['id'];
            if ($onlyIds !== []) {
                if (in_array($id, array_map('intval', $onlyIds), true)) {
                    $ids[] = $id;
                }
                continue;
            }
            if ($singleId === null || $id === $singleId) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            return false;
        }
        $st = self::defaults();
        $st['running'] = true;
        $st['total'] = count($ids);
        $st['remaining'] = $ids;
        $st['force'] = $force;
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
            // 自动同步：先轻量检测远端是否有更新，无变化就跳过拉取（省流量与 API 配额）
            if (self::shouldSkip($slug, $st)) {
                $st['done']++;
                $st['saved_at'] = time();
                if (!$remaining) {
                    return self::finalize($st);
                }
                self::write($st);
                return self::read();
            }
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
     * 前台访问时按需触发自动同步：只做「本地判断 + 入队」，绝不在请求线程内访问 GitHub。
     *
     * 快照缺失或超过 AUTO_TTL 的项目入队；是否真的有更新，留到队列推进时
     * （advance → shouldSkip）再用一次轻量 pushed_at 比对决定 —— 无变化就不拉 README
     * 与图片。这样页面首字节永远不等网络，访客无感。
     *
     * 队列在响应发出后（fastcgi_finish_request）由 drain 推进，所以纯前台访客也能把
     * 自动同步跑完，不再依赖管理员打开后台轮询。手动同步（force=true）不受此限制。
     *
     * 频率控制：冷却令牌记录上次触发时刻（5 分钟），避免访客每次刷新都排队。
     */
    public static function autoSync(): void
    {
        if (self::state()['running']) {
            return;
        }
        $dir = STORAGE_PATH . '/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $gate = $dir . '/project_autosync.json';
        $last = is_file($gate) ? (int) json_decode((string) file_get_contents($gate), true) : 0;
        // 至少间隔 5 分钟，避免每次访问都排队
        if ($last !== 0 && (time() - $last) < 300) {
            return;
        }
        $expired = self::expiredIds();
        @file_put_contents($gate, (string) time(), LOCK_EX);
        // force=false：推进阶段先检测远端 pushed_at，无更新即跳过拉取
        if ($expired === [] || !self::start(null, $expired, false)) {
            return;
        }
        // 页面渲染输出由调用方完成，这里只登记「请求结束后」的推进
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @set_time_limit(0);
                // 先把页面发给客户端，再慢慢同步，访客无感知
                @fastcgi_finish_request();
            }
            self::drain();
        });
    }

    /**
     * 快照缺失或已过期的项目 id（纯本地判断，不发网络请求，故可安全在请求线程内调用）。
     * 是否真的有更新，交给推进阶段的 shouldSkip() 检测。
     */
    private static function expiredIds(): array
    {
        $cutoff = time() - self::AUTO_TTL;
        $ids = [];
        foreach (Project::load() as $p) {
            $slug = trim((string) ($p['github_repo'] ?? ''));
            if ($slug === '') {
                $slug = (string) (Github::parseRepo((string) ($p['url'] ?? '')) ?? '');
            }
            if ($slug === '') {
                continue;
            }
            $snap = ProjectCache::snapshot($slug);
            if ($snap === null || (int) ($snap['ts'] ?? 0) < $cutoff) {
                $ids[] = (int) $p['id'];
            }
        }
        return $ids;
    }

    /**
     * 自动同步（非 force）时判断某项目能否跳过拉取：先轻量比对远端 pushed_at 与本地快照。
     * 无变化 → 跳过并刷新时间戳；检测失败（网络/限流）→ 跳过但保留旧快照，下轮再试。
     * 手动同步（force）永远返回 false，即强制全量。
     */
    private static function shouldSkip(string $slug, array $st): bool
    {
        if (!empty($st['force'])) {
            return false;
        }
        $snap = ProjectCache::snapshot($slug);
        if ($snap === null) {
            return false; // 从未同步过，必须拉
        }
        $remote = Github::remotePushedAt($slug);
        if ($remote === null) {
            return true; // 检测失败：保留旧快照，下轮再检测
        }
        if ($remote !== (string) ($snap['gh']['pushed_at'] ?? '')) {
            return false; // 远端有新提交，需要拉
        }
        ProjectCache::touch($slug, time()); // 无变化：刷新时间戳，6 小时内不再检测
        return true;
    }

    /** 推进队列直至完成或超时（请求结束后调用，避免长时间占用 fpm 进程） */
    private static function drain(): void
    {
        $deadline = time() + 100;
        while (time() < $deadline) {
            $st = self::advance();
            if (!$st['running'] || $st['remaining'] === []) {
                break;
            }
        }
    }

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