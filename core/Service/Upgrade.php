<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * 系统升级服务：版本检测（GitHub / Gitee 双源都查，按版本号取最高者）、
 * 一键升级（数据备份 → 下载源码包 → 安全解压 → 校验版本 → 覆盖代码）。
 * 对照原项目 check_latest_version / do_upgrade / _upgrade_apply。
 */
class Upgrade
{
    /** 主仓库（GitHub，PHP 版独立仓库） */
    public const REPO = 'Contribuv/haozi_blog';
    /** 最新 Releases 页面（无法获取版本信息时的兜底链接） */
    public const RELEASE_URL = 'https://github.com/Contribuv/haozi_blog/releases/latest';
    /** 下载/解压大小上限 200MB */
    public const MAX_BYTES = 209715200;
    /** 升级锁超时（秒）：超过视为上次异常中断，自动清理后允许重试 */
    public const LOCK_TTL = 600;
    /** 版本检测缓存有效期（秒） */
    public const CACHE_TTL = 600;
    /** 平台失败后的熔断窗口（秒）：窗口内直接跳过该平台，不再干等连接超时 */
    private const BREAK_SECONDS = 300;
    /** 双平台可用性探测有效期（秒）：过期则由后台异步刷新 */
    private const PROBE_TTL = 600;
    /** 单次 API 探测超时（秒）：GitHub 不通时能快速失败并切换 */
    private const API_TIMEOUT = 3;

    /**
     * 升级时整棵跳过的顶层目录：用户数据 / 运行时数据 / 本地扩展 / 版本库。
     * 刻意不含 config —— config 目录要跟着升级，只有 config/config.php 单个文件保留。
     */
    private const SKIP_DIRS = ['data', 'uploads', 'storage', 'backups', '.git', 'vendor'];
    /** 升级时绝不覆盖、也绝不删除的文件（相对项目根）：本地密钥、安装标记、宝塔 open_basedir */
    private const SKIP_FILES = ['config/config.php', 'config/installed.lock', '.user.ini'];
    /** 升级时绝不覆盖的文件后缀（数据库转储 / 会话） */
    private const SKIP_EXT = ['sql', 'session'];

    /** 取消请求被识别后统一用这条消息抛出/落盘，便于上层判断是不是「取消」而非「失败」 */
    private const CANCEL_MSG = '升级已取消';

    /** GitHub 主仓库（可用环境变量 UPGRADE_GITHUB_REPO 覆盖） */
    public static function githubRepo(): string
    {
        $r = getenv('UPGRADE_GITHUB_REPO');
        return trim($r !== false && $r !== '' ? $r : self::REPO, '/');
    }

    /** Gitee 镜像仓库（可用环境变量 UPGRADE_GITEE_REPO 覆盖） */
    public static function giteeRepo(): string
    {
        $r = getenv('UPGRADE_GITEE_REPO');
        return trim($r !== false && $r !== '' ? $r : 'infowe/haozi_blog', '/');
    }

    /** 下载加速镜像前缀列表（UPGRADE_MIRRORS 逗号分隔，设空串则纯直连） */
    public static function mirrors(): array
    {
        $raw = getenv('UPGRADE_MIRRORS');
        $raw = $raw !== false && $raw !== '' ? $raw : 'https://ghproxy.net/';
        $out = [];
        foreach (explode(',', $raw) as $m) {
            $m = trim($m);
            if ($m !== '') {
                $out[] = rtrim($m, '/') . '/';
            }
        }
        return $out;
    }

    private static function cacheFile(): string
    {
        return PHP_BLOG_ROOT . '/data/upgrade_cache.json';
    }

    /** 双平台健康状态文件（data/upgrade_sources.json） */
    private static function sourcesFile(): string
    {
        return PHP_BLOG_ROOT . '/data/upgrade_sources.json';
    }

    /** 版本相同时的「公平竞争」偏好文件：记录上次选中的来源，用于轮询 */
    private static function preferFile(): string
    {
        return PHP_BLOG_ROOT . '/data/upgrade_prefer.txt';
    }

    /**
     * 双平台可用性状态：['github' => ['ok'=>bool,'checked'=>int,'fails'=>int], 'gitee' => 同]。
     * ok=false 且仍在 BREAK_SECONDS 窗口内即视为「熔断」，调用时直接跳过，
     * 避免 GitHub 不通时每个请求都干等一次连接超时（这正是卡死 PHP worker 的根因）。
     */
    public static function sourceState(): array
    {
        $out = [
            'github' => ['ok' => true, 'checked' => 0, 'fails' => 0],
            'gitee' => ['ok' => true, 'checked' => 0, 'fails' => 0],
        ];
        $file = self::sourcesFile();
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                foreach (array_keys($out) as $k) {
                    if (isset($data[$k]) && is_array($data[$k])) {
                        $out[$k] = [
                            'ok' => (bool) ($data[$k]['ok'] ?? true),
                            'checked' => (int) ($data[$k]['checked'] ?? 0),
                            'fails' => (int) ($data[$k]['fails'] ?? 0),
                        ];
                    }
                }
            }
        }
        return $out;
    }

    /** 记录一次平台调用结果（成功即清零失败计数） */
    public static function markSource(string $src, bool $ok): void
    {
        $state = self::sourceState();
        if (!isset($state[$src])) {
            return;
        }
        $state[$src]['ok'] = $ok;
        $state[$src]['checked'] = time();
        $state[$src]['fails'] = $ok ? 0 : ((int) $state[$src]['fails'] + 1);
        $file = self::sourcesFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file . '.tmp', json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($file . '.tmp', $file);
    }

    /** 平台是否处于熔断窗口内 */
    private static function broken(string $src, array $state): bool
    {
        $s = $state[$src] ?? null;
        return is_array($s) && (int) $s['fails'] > 0
            && (time() - (int) $s['checked']) < self::BREAK_SECONDS;
    }

    /** 平台尝试顺序：健康的在前、熔断的在后；$ignoreBreak=true 时按固定顺序（手动重新检测用） */
    private static function order(bool $ignoreBreak = false): array
    {
        $all = ['github', 'gitee'];
        if ($ignoreBreak) {
            return $all;
        }
        $state = self::sourceState();
        $healthy = [];
        $broken = [];
        foreach ($all as $src) {
            if (self::broken($src, $state)) {
                $broken[] = $src;
            } else {
                $healthy[] = $src;
            }
        }
        return array_merge($healthy, $broken);
    }

    /** 状态是否过期（需要后台重新探测） */
    public static function sourcesStale(): bool
    {
        $s = self::sourceState();
        $oldest = min((int) $s['github']['checked'], (int) $s['gitee']['checked']);
        return $oldest === 0 || (time() - $oldest) > self::PROBE_TTL;
    }

    /**
     * 探测两个平台可用性并落盘（短超时）；供后台在响应发出后调用。
     * 缓存只写「版本最高」的那份结果：原来两个平台探测完由后探测者无条件覆盖缓存，
     * 若 GitHub 版本更高而 Gitee 更低，页面反而会显示低版本。
     */
    public static function probeSources(): array
    {
        $info = self::bestOfSources(true);
        if ($info !== null) {
            self::saveCache(time(), $info);
        }
        return self::sourceState();
    }

    /** 挂一个「响应结束后再探测」的钩子，探测不会占用用户的等待时间 */
    public static function scheduleProbe(): void
    {
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @set_time_limit(30);
            self::probeSources();
        });
    }

    /** 升级锁文件 */
    public static function lockFile(): string
    {
        return PHP_BLOG_ROOT . '/upgrade.lock';
    }

    /** 是否已有升级在进行：进度处于 running 且未超出锁有效期（进程被杀时不致永久误判） */
    public static function isRunning(): bool
    {
        $p = self::progress();
        if (($p['state'] ?? '') !== 'running') {
            return false;
        }
        $ts = (int) ($p['ts'] ?? 0);
        return $ts > 0 && (time() - $ts) <= self::LOCK_TTL;
    }

    /**
     * 以独立后台进程启动升级，脱离 HTTP 请求：PHP-FPM worker 立即释放，其它访客不再排队。
     * 返回 false 表示当前环境无法起后台进程（如 disable_functions 禁了 exec），调用方需回退为同步执行。
     */
    public static function spawnWorker(string $tag, string $preferredSource = ''): bool
    {
        // disable_functions 禁掉 exec 时 function_exists 仍返回 true，只认它会「假启动」：
        // 接口回 ok=true、前端开始轮询，而后台进程从未跑起来
        if (!function_exists('exec') || self::functionDisabled('exec')) {
            return false;
        }
        // Windows 下没有 nohup/nice，起不了后台进程；直接回退同步执行，免得假报「已启动」让前端空等
        if (DIRECTORY_SEPARATOR === '\\') {
            return false;
        }
        $php = self::phpBinary();
        $script = PHP_BLOG_ROOT . '/bin/upgrade_worker.php';
        if ($php === '' || !is_file($script)) {
            return false;
        }
        // 后台进程的 stdout/stderr 落日志，排障时能看到失败原因，而非无声丢弃
        $log = PHP_BLOG_ROOT . '/data/upgrade_worker.log';
        $logDir = dirname($log);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        // 参数一律 escapeshellarg；nice 压低 CPU 优先级，少抢前台。末尾 & 让 exec 立即返回。
        // 第二参数为首选下载平台（版本检测结果里哪个平台有包），worker 据此决定下载顺序
        $cmd = 'nohup nice -n 10 ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($tag)
            . ($preferredSource !== '' ? ' ' . escapeshellarg($preferredSource) : '')
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        @exec($cmd);
        // exec 无法可靠得知子进程是否起活，交给进度轮询兜底（isRunning 据进度判断）
        return true;
    }

    /** 某函数是否被 disable_functions 禁用（function_exists 对被禁函数的判断不可靠） */
    private static function functionDisabled(string $fn): bool
    {
        $list = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array($fn, $list, true);
    }

    /** 找到可用的 PHP CLI 解释器。FPM 下 PHP_BINARY 指向 php-fpm 不能跑脚本，需回退到 PHP_BINDIR/php */
    private static function phpBinary(): string
    {
        $candidates = [];
        if (PHP_SAPI === 'cli' && defined('PHP_BINARY')) {
            $candidates[] = PHP_BINARY;
        }
        if (defined('PHP_BINDIR')) {
            $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
        }
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '' && is_file($c)) {
                return $c;
            }
        }
        return '';
    }

    /** 取消标记文件：前端点「取消升级」时写入，升级流程在各检查点读取 */
    public static function cancelFile(): string
    {
        return PHP_BLOG_ROOT . '/data/upgrade_cancel';
    }

    /** 请求取消当前升级：只落标记，真正中止由升级流程在下一个检查点执行 */
    public static function requestCancel(): void
    {
        $file = self::cancelFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, (string) time(), LOCK_EX);
    }

    /** 当前是否有未处理的取消请求 */
    public static function isCancelled(): bool
    {
        return is_file(self::cancelFile());
    }

    /**
     * 请求取消升级，并保证「必达终态」：
     * 进程还活着 → 只写标记，由它在下一个检查点自行中止并写终态；
     * 进程心跳已过期（多半被系统杀掉）→ 标记永远不会有人消费，直接就地收敛为「已取消」，
     * 否则前端一直停在「正在取消…」、刷新后又弹回浮窗，用户感受就是「取消不掉」。
     * 返回 [是否成功, 提示语]。
     */
    public static function cancel(): array
    {
        $p = self::progress();
        if (($p['state'] ?? '') !== 'running') {
            // 已经不在运行（done / failed / cancelled / idle）：没有可取消的对象，顺手清干净
            self::clearCancel();
            return [true, '当前没有正在进行的升级'];
        }
        $ts = (int) ($p['ts'] ?? 0);
        if ($ts <= 0 || (time() - $ts) > self::LOCK_TTL) {
            self::clearCancel();
            self::clearLock();
            self::writeProgress([
                'state' => 'cancelled',
                'phase' => 'cancelled',
                'label' => '升级已取消',
                'percent' => -1,
                'message' => '升级进程已中断，残留状态已清除',
            ]);
            return [true, '升级进程已中断，已清除残留状态'];
        }
        self::requestCancel();
        return [true, '取消请求已提交，正在等待当前步骤结束'];
    }

    /** 主动释放升级锁（进程已死的判定成立、或确认要重新开始升级时用，免得干等锁自然过期） */
    public static function clearLock(): void
    {
        $lock = self::lockFile();
        if (is_file($lock)) {
            @unlink($lock);
        }
    }

    /**
     * 清除升级残留状态：进度、取消标记、升级锁一并清掉，把页面恢复成「可以重新升级」。
     * 只在没有新鲜的进行中进程时允许，避免把真正在跑的升级清掉。
     * 返回 [是否成功, 提示语]。
     */
    public static function resetState(): array
    {
        if (self::isRunning()) {
            return [false, '升级仍在进行中，请等待它结束或稍后再试'];
        }
        self::clearProgress();
        self::clearCancel();
        self::clearLock();
        return [true, '升级状态已清除，可以重新升级了'];
    }

    /** 清除取消标记（升级开始/结束时调用，免得残留影响下一次升级） */
    public static function clearCancel(): void
    {
        $file = self::cancelFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** 检查点：收到取消请求即抛异常中止升级。只在覆盖代码(apply)之前调用——apply 后中途停会留半覆盖，故不查 */
    private static function abortIfCancelled(): void
    {
        if (self::isCancelled()) {
            throw new \RuntimeException(self::CANCEL_MSG);
        }
    }

    /** 升级进度文件（data/upgrade_progress.json）：升级期间供前端轮询显示真实进度 */
    public static function progressFile(): string
    {
        return PHP_BLOG_ROOT . '/data/upgrade_progress.json';
    }

    /**
     * 写入/合并升级进度。$patch 与现有内容合并，避免各阶段只写部分字段把别的字段抹掉；
     * 始终补 ts（前端可据此判断进度是否卡死）。原子写：先写 .tmp 再 rename。
     */
    public static function writeProgress(array $patch): void
    {
        $file = self::progressFile();
        $cur = [];
        if (is_file($file)) {
            $d = json_decode((string) @file_get_contents($file), true);
            if (is_array($d)) {
                $cur = $d;
            }
        }
        $data = array_merge($cur, $patch, ['ts' => time()]);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($file . '.tmp', $file);
        // 顺带刷新升级锁的 mtime：锁的「过期」判定依赖它。若锁 mtime 停在升级开始那一刻，
        // 长时间升级会被后来者当成过期锁清掉，两个升级进程并发覆盖代码
        $lock = self::lockFile();
        if (is_file($lock)) {
            @touch($lock);
        }
    }

    /** 读取当前升级进度；无进度文件或内容损坏时返回 idle */
    public static function progress(): array
    {
        $idle = ['state' => 'idle', 'phase' => '', 'label' => '', 'percent' => -1, 'ts' => 0, 'stale' => false];
        $file = self::progressFile();
        if (!is_file($file)) {
            return $idle;
        }
        $d = json_decode((string) @file_get_contents($file), true);
        if (!is_array($d)) {
            return $idle;
        }
        // running 但心跳已过期：进程多半被系统杀掉（FPM 重启 / request_terminate_timeout 到点），
        // 前端据此不再无限等待、也不再反复把浮窗弹回来
        $d['stale'] = ($d['state'] ?? '') === 'running'
            && (int) ($d['ts'] ?? 0) > 0
            && (time() - (int) $d['ts']) > self::LOCK_TTL;
        return $d;
    }

    /** 清除进度文件（升级开始前调用，避免上次的陈旧终态被前端读到） */
    public static function clearProgress(): void
    {
        $file = self::progressFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** 'v1.2.3' / '1.2.3' → [1,2,3]；无法解析返回 null */
    public static function parseVersion(string $v): ?array
    {
        if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', trim($v), $m)) {
            return null;
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /** 版本数组转 'x.y.z' */
    public static function versionString(array $v): string
    {
        return implode('.', $v);
    }

    /** 读取磁盘缓存（重启/多进程不丢） */
    private static function loadCache(): array
    {
        $file = self::cacheFile();
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && is_numeric($data['t'] ?? null)) {
                return ['t' => (int) $data['t'], 'info' => $data['info'] ?? null];
            }
        }
        return ['t' => 0, 'info' => null];
    }

    /**
     * 只读版本缓存（零网络）：供后台页面渲染更新提醒，绝不触发请求。
     * 缓存缺失/过期时由调用方 scheduleProbe 在响应后异步刷新。
     */
    public static function cachedLatest(): ?array
    {
        return self::loadCache()['info'];
    }

    /** 版本缓存写入时间戳（升级页用它说明「结果来自缓存」）；无缓存返回 0 */
    public static function cachedAt(): int
    {
        return (int) self::loadCache()['t'];
    }

    /** 原子写入缓存 */
    private static function saveCache(int $t, ?array $info): void
    {
        $file = self::cacheFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file . '.tmp', json_encode(['t' => $t, 'info' => $info], JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($file . '.tmp', $file);
    }

    /**
     * 查询最新版本：GitHub / Gitee 双源都查一遍、按版本号取最高者（任一源不通也不影响）；
     * 都失败则静默返回 null。
     * 返回 ['tag','version','html_url','body','published_at','source'] 或 null。
     */
    public static function checkLatestVersion(bool $force = false): ?array
    {
        $cache = self::loadCache();
        // 只认「成功结果」的缓存：失败不再落缓存，否则一次网络抖动会让接下来整个
        // CACHE_TTL 内直接返回 null，表现为「明明有 Gitee 镜像却查不到更新」
        if (!$force && $cache['info'] !== null && $cache['t'] > 0 && (time() - $cache['t']) < self::CACHE_TTL) {
            return $cache['info'];
        }
        $info = self::bestOfSources($force);
        if ($info !== null) {
            self::saveCache(time(), $info);
        }
        return $info;
    }

    /** GitHub Releases API */
    private static function githubLatest(string $repo): ?array
    {
        try {
            $data = Http::getJson(
                'https://api.github.com/repos/' . $repo . '/releases/latest',
                ['Accept: application/vnd.github+json', 'User-Agent: infowe-Blog-updater'],
                self::API_TIMEOUT
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        $ver = self::parseVersion((string) ($data['tag_name'] ?? ''));
        if ($ver === null) {
            return null;
        }
        return [
            'tag' => self::versionString($ver),
            'version' => $ver,
            'html_url' => (string) ($data['html_url'] ?? self::RELEASE_URL),
            'body' => mb_substr(trim((string) ($data['body'] ?? '')), 0, 2000),
            'published_at' => mb_substr((string) ($data['published_at'] ?? ''), 0, 10),
            'source' => 'github',
        ];
    }

    /** Gitee 镜像 Releases API */
    private static function giteeLatest(string $repo): ?array
    {
        try {
            $data = Http::getJson(
                'https://gitee.com/api/v5/repos/' . $repo . '/releases/latest',
                ['User-Agent: infowe-Blog-updater'],
                self::API_TIMEOUT
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        $ver = self::parseVersion((string) ($data['tag_name'] ?? ''));
        if ($ver === null) {
            return null;
        }
        return [
            'tag' => self::versionString($ver),
            'version' => $ver,
            'html_url' => (string) ($data['html_url'] ?? ('https://gitee.com/' . $repo . '/releases')),
            'body' => mb_substr(trim((string) ($data['body'] ?? '')), 0, 2000),
            'published_at' => mb_substr((string) ($data['published_at'] ?? $data['created_at'] ?? ''), 0, 10),
            'source' => 'gitee',
        ];
    }

    /**
     * 查询双平台并取「版本最高」者。
     * 关键：绝不能「谁先成功就用谁」——GitHub 一旦返回结果就 break，会让 Gitee 上的
     * 更高版本永远检测不到（反之亦然），表现为「更新只从 GitHub 获取」。
     * 两个平台各查一次：版本不同取高；**版本相同则公平竞争（轮询平台）**，避免在
     * 两平台都可用且同步发版时永远只命中 GitHub。都失败返回 null，结果里的 source 标明来源。
     */
    private static function bestOfSources(bool $ignoreBreak = false): ?array
    {
        $gitee = self::giteeRepo();
        $found = [];
        foreach (self::order($ignoreBreak) as $src) {
            if ($src === 'gitee' && $gitee === '') {
                continue;
            }
            $info = $src === 'github' ? self::githubLatest(self::githubRepo()) : self::giteeLatest($gitee);
            self::markSource($src, $info !== null);
            if ($info !== null) {
                $found[$src] = $info;
            }
        }
        if ($found === []) {
            return null;
        }
        $gh = $found['github'] ?? null;
        $gt = $found['gitee'] ?? null;
        // 仅一个平台有结果：直接用它
        if ($gh === null || $gt === null) {
            return $gh ?? $gt;
        }
        // 两平台都有结果：版本高者优先
        $cmp = version_compare(self::versionString($gh['version']), self::versionString($gt['version']));
        if ($cmp > 0) {
            return $gh;
        }
        if ($cmp < 0) {
            return $gt;
        }
        // 版本相同（两平台同步发版）：公平竞争，轮流选平台
        return self::alternate($gh, $gt);
    }

    /**
     * 双平台版本相同时的「公平竞争」：交替选择来源。
     * 此前固定「保留先到的 GitHub」，两平台同步发版时会永远只走 GitHub；
     * 这里按上次选择轮询到另一个平台，让两边流量均衡、也顺带轮流验证两条下载链。
     */
    private static function alternate(array $gh, array $gt): array
    {
        $file = self::preferFile();
        $last = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        // 上次选 github 这次就选 gitee，反之亦然；首次选 github（保持与旧版一致的起始行为）
        $pick = $last === 'github' ? 'gitee' : 'github';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, $pick, LOCK_EX);
        return $pick === 'gitee' ? $gt : $gh;
    }

    /** Releases 说明 Markdown → 纯文本（升级页展示）。彻底剥离 HTML，杜绝 XSS */
    public static function bodyHtml(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        // strip_tags 去掉全部标签，再压缩多余空白，保留可读的纯文本说明
        $text = strip_tags(Markdown::renderReadme($markdown, '', ''));
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * 执行升级：数据备份 → 下载 → 解压 → 校验 → 覆盖代码。
     * 返回 [成功标志, 消息]。
     */
    public static function doUpgrade(string $tag, string $preferredSource = ''): array
    {
        $lock = self::lockFile();
        // 过期锁（上次异常中断残留）自动清理，避免永久卡「升级进行中」
        if (is_file($lock) && (time() - (int) filemtime($lock)) > self::LOCK_TTL) {
            @unlink($lock);
        }
        $fp = @fopen($lock, 'x');
        if ($fp === false) {
            return [false, '升级任务已在进行中，请稍候再试'];
        }
        fwrite($fp, (string) time());
        fclose($fp);

        // 清掉上次残留的进度后再写本次起点：备份阶段耗时不可预估，先亮出「备份中」。
        // 刻意不清取消标记——该清理由 run() 在拉起本进程前完成；若在这里再清一次，
        // 用户看到浮窗后立刻点「取消」写下的标记会被抹掉，取消彻底失效（同步回退模式下必现）
        self::clearProgress();
        self::writeProgress(['state' => 'running', 'phase' => 'backup', 'label' => '正在备份数据…', 'percent' => -1]);

        $tmp = sys_get_temp_dir() . '/infowe_upgrade_' . bin2hex(random_bytes(5));
        try {
            @mkdir($tmp, 0775, true);
            $ts = date('Ymd_His');
            // 1. 数据备份（数据库 + 用户上传）
            $bakDir = Backup::dir() . '/upgrade_' . $ts . '_' . $tag;
            Backup::ensureDir();
            @mkdir($bakDir, 0775, true);
            file_put_contents($bakDir . '/blog.sql', Backup::sqlDump(), LOCK_EX);
            // 大上传目录备份可能耗时数分钟。期间不刷新进度，前端会误判「进程已中断」，
            // 故按文件数打点，但节流到最多每 2 秒写一次，避免频繁 IO
            $lastTick = 0;
            Backup::copyUploads($bakDir . '/uploads', function (int $n) use (&$lastTick): void {
                $now = time();
                if ($now - $lastTick >= 2) {
                    $lastTick = $now;
                    self::writeProgress(['phase' => 'backup', 'label' => '正在备份上传文件（已 ' . $n . ' 个）…', 'percent' => -1]);
                }
            });
            // 备份不可中断，只能整段跑完后才检查取消；此时取消，备份已留在磁盘但代码未动
            self::abortIfCancelled();

            // 2. 下载源码压缩包
            //    检测更新时已知道哪个平台返回了这份版本信息（$preferredSource），就优先从该平台
            //    下载——否则 Gitee 独有的新 tag 也要先白试一遍 GitHub 直连+镜像（超时/404 各耗
            //    数秒），还顺带误标 GitHub 熔断。download() 内部仍保留完整 fallback 链兜底。
            //    Gitee 的直连归档路径是 /repository/archive/{ref}.zip，写成 /archive/{ref}.zip 会 404
            self::writeProgress(['phase' => 'download', 'label' => '正在下载源码包…', 'percent' => -1]);
            $zipUrl = 'https://github.com/' . self::githubRepo() . '/archive/refs/tags/v' . $tag . '.zip';
            $alt = self::giteeRepo() !== ''
                ? ['https://gitee.com/' . self::giteeRepo() . '/repository/archive/v' . $tag . '.zip']
                : [];
            $zipPath = self::download($zipUrl, $tmp . '/release.zip', $alt, $preferredSource);

            // 3. 安全解压
            self::abortIfCancelled();
            self::writeProgress(['phase' => 'extract', 'label' => '正在解压…', 'percent' => -1]);
            self::extract($zipPath, $tmp);
            @unlink($zipPath);

            // 4. 定位代码根目录并校验新版本
            $entries = [];
            foreach (scandir($tmp) ?: [] as $d) {
                if ($d !== '.' && $d !== '..' && is_dir($tmp . '/' . $d)) {
                    $entries[] = $d;
                }
            }
            $root = count($entries) === 1 ? $tmp . '/' . $entries[0] : $tmp;
            $ctxFile = $root . '/core/Service/Context.php';
            if (!is_file($ctxFile)) {
                throw new \RuntimeException('压缩包中未找到 core/Service/Context.php，已中止');
            }
            $hit = preg_match("/VERSION\s*=\s*'([^']+)'/", (string) file_get_contents($ctxFile), $m);
            $newVer = $hit ? self::parseVersion($m[1]) : null;
            $curVer = self::parseVersion(Context::VERSION);
            if ($newVer === null || ($curVer !== null && $newVer <= $curVer)) {
                throw new \RuntimeException('下载的版本不高于当前版本，已中止');
            }

            // 5. 覆盖代码（跳过数据/用户目录）
            self::abortIfCancelled();
            self::writeProgress(['phase' => 'apply', 'label' => '正在覆盖代码…', 'percent' => -1]);
            $replaced = self::apply($root);
            if ($replaced === 0) {
                throw new \RuntimeException('没有可替换的文件，已中止');
            }
            // 6. 清理旧版本残留：只覆盖不删除，1.x 的 public/、admin.php 会留在磁盘上
            self::writeProgress(['phase' => 'prune', 'label' => '正在清理旧文件…', 'percent' => -1]);
            $removed = self::prune($root);
            // 7. 重建静态错误页：升级包里的 static/{404,403,50x}.html 可能滞后于当前主题，
            //    且文件缺失时 nginx 的 error_page 会扑空退回原生页
            ErrorPages::rebuild();
            if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        $msg = '升级完成';
        self::writeProgress(['state' => 'done', 'phase' => 'done', 'label' => '升级完成', 'percent' => 100, 'message' => $msg]);
        return [true, $msg];
        } catch (\Throwable $e) {
            // 取消用独立状态，前端据此显示「已取消」而非「失败」；其余异常仍按失败上报
            $cancelled = $e->getMessage() === self::CANCEL_MSG;
            $msg = $cancelled ? self::CANCEL_MSG : ('升级失败：' . $e->getMessage());
            self::writeProgress([
                'state' => $cancelled ? 'cancelled' : 'failed',
                'phase' => 'failed',
                'label' => $cancelled ? '升级已取消' : '升级失败',
                'percent' => -1,
                'message' => $msg,
            ]);
            return [false, $msg];
        } finally {
            self::rmTree($tmp);
            self::clearCancel();
            if (is_file($lock)) {
                @unlink($lock);
            }
        }
    }

    /**
     * 按顺序尝试各下载源，全部失败抛出汇总了每个源原因的异常。
     * $preferredSource 为版本检测出的来源平台（'github'/'gitee'），把它排到最前：
     * 检测时该平台刚成功返回过数据，从它下载成功率最高，避免对不通的平台白耗超时。
     */
    private static function download(string $url, string $dest, array $altUrls = [], string $preferredSource = ''): string
    {
        $githubChain = array_merge([$url], array_map(static fn (string $m): string => $m . $url, self::mirrors()));
        if ($preferredSource === 'gitee') {
            // 检测结果是 Gitee 给的（GitHub 大概率不通或没有这个 tag）：Gitee 归档放最前
            $attempts = array_merge($altUrls, $githubChain);
        } elseif ($preferredSource === 'github') {
            $attempts = array_merge($githubChain, $altUrls);
        } else {
            // 无首选信息时按健康状态排：GitHub 处于熔断窗口则 Gitee 提前，省掉连接超时空等
            $attempts = self::broken('github', self::sourceState())
                ? array_merge($altUrls, $githubChain)
                : array_merge($githubChain, $altUrls);
        }
        $lastErr = null;
        $errors = [];
        foreach ($attempts as $u) {
            // 上一轮下载可能因用户取消而中断，换源前先确认没被取消
            self::abortIfCancelled();
            $isDirectGithub = ($u === $url);
            $isGitee = str_contains($u, 'gitee.com');
            $label = $isGitee ? 'Gitee' : ($isDirectGithub ? 'GitHub' : '镜像');
            // Gitee 对冷门 tag 的归档请求会现打包：返回 HTTP 200 +「正在打包」HTML 中间页，
            // 打包通常几秒~几十秒完成，等几秒重试即可拿到真 zip——不重试的话整个升级直接失败
            $tries = $isGitee ? 3 : 1;
            try {
                for ($i = 1; $i <= $tries; $i++) {
                    try {
                        self::streamDownload($u, $dest);
                        // 必须确认拿到的是真 zip：中间页 / 错误页都是 HTTP 200
                        if (self::looksLikeZip($dest)) {
                            if ($isDirectGithub) {
                                self::markSource('github', true);
                            }
                            if ($isGitee) {
                                self::markSource('gitee', true);
                            }
                            return $dest;
                        }
                        $lastErr = new \RuntimeException('响应不是 zip 压缩包（' . ($isGitee ? 'Gitee 正在打包，' : '') . '已等待重试 ' . ($i - 1) . ' 次）');
                    } catch (\Throwable $e) {
                        $lastErr = $e;
                    }
                    if ($i < $tries) {
                        self::abortIfCancelled();
                        sleep(3);
                    }
                }
                throw $lastErr ?? new \RuntimeException('下载失败');
            } catch (\Throwable $e) {
                // 只按「直连」结果熔断：加速镜像失败不代表该平台本身不可用
                if ($isDirectGithub) {
                    self::markSource('github', false);
                }
                if ($isGitee) {
                    self::markSource('gitee', false);
                }
                $errors[] = $label . '：' . mb_substr($e->getMessage(), 0, 120);
                if (is_file($dest)) {
                    @unlink($dest);
                }
            }
        }
        // 汇总每个源各自的失败原因，排障时不用再猜卡在哪一跳
        throw new \RuntimeException('所有下载源均失败 — ' . implode('；', $errors));
    }

    /** 本地文件是否为 zip（校验文件头魔数） */
    private static function looksLikeZip(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = (string) fread($fh, 4);
        fclose($fh);
        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06" || $magic === "PK\x07\x08";
    }

    /** 流式下载到文件，超过大小上限即中止 */
    private static function streamDownload(string $url, string $dest): void
    {
        self::abortIfCancelled();
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('无法写入临时文件');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            throw new \RuntimeException('初始化下载失败');
        }
        Http::applyCurl($ch);
        // 下载进度上报节流用：记录上一次落盘的时间点
        $lastReport = 0.0;
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // 限速 1MB/s：源码包可达数十 MB，窄带宽服务器上全速拉包会把出入口带宽
            // 吃满，同机其它站点/服务即时表现为「网络不可达」。限速后下载慢些，但不
            // 至于拖垮整台服务器。故单个请求的总时限相应放宽（53MB / 1MB/s ≈ 53 秒），
            // 另加低速保护：20 秒内平均低于 1KB/s 视为停滞立即中止，免得源站挂起时
            // 白等满 300 秒。
            CURLOPT_MAX_RECV_SPEED_LARGE => 1048576,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 20,
            // Gitee 对浏览器类 UA 会返回「正在打包」的中间页（HTTP 200 + HTML），
            // 只有 curl 类 UA 才会直接给出 zip 包，故这里沿用 curl 风格 UA
            CURLOPT_USERAGENT => 'curl/8.21.0',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($res, $dlTotal, $dlNow) use (&$lastReport): int {
                // 用户点了取消：返回非 0 立即中止传输
                if (self::isCancelled()) {
                    return 1;
                }
                // 超过大小上限立即中止传输（返回非 0）
                if ($dlNow > self::MAX_BYTES) {
                    return 1;
                }
                // 下载进度节流上报：该回调每收一个数据块就触发（每秒可达上百次），
                // 每 0.3 秒才落一次盘，免得频繁写文件反把下载拖慢
                $now = microtime(true);
                if ($dlTotal > 0 && $now - $lastReport >= 0.3) {
                    $lastReport = $now;
                    self::writeProgress([
                        'state' => 'running',
                        'phase' => 'download',
                        'label' => '正在下载源码包…',
                        'percent' => (int) floor($dlNow / $dlTotal * 100),
                        'dl_now' => $dlNow,
                        'dl_total' => $dlTotal,
                    ]);
                }
                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        // 传输中断若是因用户取消，抛取消异常（而非报「传输失败 / HTTP 错误」）
        self::abortIfCancelled();
        if ($ok === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException($err !== '' ? $err : ('HTTP ' . $code));
        }
        if ((int) filesize($dest) > self::MAX_BYTES) {
            throw new \RuntimeException('下载内容超过大小上限，已取消');
        }
    }

    /** 安全解压：拒绝路径穿越与超大文件 */
    private static function extract(string $zipPath, string $tmp): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('压缩包无法打开');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (str_starts_with($name, '/') || str_contains($name, '..')) {
                    throw new \RuntimeException('压缩包内含非法路径，已中止');
                }
                if (($stat['size'] ?? 0) > self::MAX_BYTES) {
                    throw new \RuntimeException('压缩包内单个文件过大，已中止');
                }
                $target = $tmp . '/' . $name;
                if (str_ends_with($name, '/')) {
                    if (!is_dir($target)) {
                        mkdir($target, 0775, true);
                    }
                    continue;
                }
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                $out = fopen($target, 'wb');
                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }

    /** 把解压后的新代码覆盖到项目根，跳过数据/用户目录，返回替换的文件数 */
    private static function apply(string $root): int
    {
        $base = PHP_BLOG_ROOT;
        $replaced = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            if ($rel === '' || self::skip($rel)) {
                continue;
            }
            $dst = $base . '/' . $rel;
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0775, true);
            }
            if (@copy($f->getPathname(), $dst)) {
                $replaced++;
            }
        }
        return $replaced;
    }

    /** 判断相对路径是否属于不覆盖范围 */
    private static function skip(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $top = explode('/', $rel)[0];
        if (in_array($top, self::SKIP_DIRS, true) || $rel === 'upgrade.lock' || str_starts_with($top, '.')) {
            return true;
        }
        if (in_array($rel, self::SKIP_FILES, true)) {
            return true;
        }
        return in_array(strtolower((string) pathinfo($rel, PATHINFO_EXTENSION)), self::SKIP_EXT, true);
    }

    /**
     * 删掉旧版本残留：新包里不存在的文件与目录一律移除。
     * 这是 1.x → 2.0.0 这类破坏性升级的关键 —— 只覆盖不删除，
     * 被移除的旧文件（如 public/、admin.php）会留在磁盘上，目录结构看起来没升级。
     * 返回删除的条目数。
     */
    private static function prune(string $root): int
    {
        return self::pruneBetween(PHP_BLOG_ROOT, $root, '');
    }

    /**
     * 删掉旧版本残留：$base 下存在、$root（新包）里没有的条目一律移除。
     * 这是 1.x → 2.0.0 这类破坏性升级的关键 —— 只覆盖不删除，
     * 被移除的旧文件（如 public/、admin.php）会留在磁盘上，目录结构看起来没升级。
     * $rel 是 $base 相对项目根的路径，用于命中 SKIP_FILES（config/config.php 等）。
     * 返回删除的条目数。
     */
    private static function pruneBetween(string $base, string $root, string $rel): int
    {
        $removed = 0;
        foreach (self::children($base) as $name) {
            $cur = $base . '/' . $name;
            $sub = $rel === '' ? $name : $rel . '/' . $name;
            // SKIP_DIRS 只认顶层目录名；SKIP_FILES 用完整相对路径
            if (explode('/', $sub)[0] === $sub && in_array($sub, self::SKIP_DIRS, true)) {
                continue;
            }
            if ($sub === 'upgrade.lock' || self::preserved($sub)) {
                continue;
            }
            if (!file_exists($root . '/' . $name)) {
                if (is_dir($cur)) {
                    self::rmTree($cur);
                    $removed++;
                } elseif (@unlink($cur)) {
                    $removed++;
                }
                continue;
            }
            if (is_dir($cur) && is_dir($root . '/' . $name)) {
                $removed += self::pruneBetween($cur, $root . '/' . $name, $sub);
            }
        }
        return $removed;
    }

    /** 该相对路径是否升级中绝不可动 */
    private static function preserved(string $rel): bool
    {
        return in_array($rel, self::SKIP_FILES, true);
    }

    /** 列目录子项，去掉 . 与 .. */
    private static function children(string $dir): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $n) {
            if ($n !== '.' && $n !== '..') {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** 递归删除临时目录 */
    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }
}