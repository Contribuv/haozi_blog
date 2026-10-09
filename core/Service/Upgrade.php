<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * 系统升级服务：版本检测（GitHub Releases 优先，失败回退 Gitee 镜像）、
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

    /** 升级时绝不覆盖的目录（用户数据 / 运行时数据 / 本地密钥配置） */
    private const SKIP_DIRS = ['data', 'uploads', 'storage', 'backups', '.git', 'vendor', 'config'];
    /** 升级时绝不覆盖的文件后缀（数据库转储 / 会话） */
    private const SKIP_EXT = ['sql', 'session'];

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

    /** 升级锁文件 */
    public static function lockFile(): string
    {
        return PHP_BLOG_ROOT . '/upgrade.lock';
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
     * 查询最新版本：GitHub Releases 优先，失败回退 Gitee 镜像；失败静默返回 null。
     * 返回 ['tag','version','html_url','body','published_at','source'] 或 null。
     */
    public static function checkLatestVersion(bool $force = false): ?array
    {
        $cache = self::loadCache();
        if (!$force && $cache['t'] > 0 && (time() - $cache['t']) < self::CACHE_TTL) {
            return $cache['info'];
        }
        $info = null;
        $gh = self::githubLatest(self::githubRepo());
        if ($gh !== null) {
            $info = $gh;
        } else {
            $gitee = self::giteeRepo();
            if ($gitee !== '') {
                $info = self::giteeLatest($gitee);
            }
        }
        self::saveCache(time(), $info);
        return $info;
    }

    /** GitHub Releases API */
    private static function githubLatest(string $repo): ?array
    {
        try {
            $data = Http::getJson(
                'https://api.github.com/repos/' . $repo . '/releases/latest',
                ['Accept: application/vnd.github+json', 'User-Agent: infowe-Blog-updater']
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
                ['User-Agent: infowe-Blog-updater']
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

    /** Releases 说明 Markdown → 安全 HTML（升级页展示） */
    public static function bodyHtml(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        return self::sanitizeHtml(Markdown::renderReadme($markdown, '', ''));
    }

    /** 剥离 script/iframe/object/embed/svg、事件属性、javascript: 与表单，抵消注入风险 */
    private static function sanitizeHtml(string $html): string
    {
        $html = (string) preg_replace('/<(script|iframe|object|embed|svg)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = (string) preg_replace('/<(script|iframe|object|embed|svg)\b[^>]*\/?>/i', '', $html);
        $html = (string) preg_replace('/\son\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string) preg_replace('/javascript\s*:/i', '', $html);
        $html = (string) preg_replace('/data\s*:\s*text\/html/i', '', $html);
        return (string) preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $html);
    }

    /**
     * 执行升级：数据备份 → 下载 → 解压 → 校验 → 覆盖代码。
     * 返回 [成功标志, 消息]。
     */
    public static function doUpgrade(string $tag): array
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

        $tmp = sys_get_temp_dir() . '/infowe_upgrade_' . bin2hex(random_bytes(5));
        try {
            @mkdir($tmp, 0775, true);
            $ts = date('Ymd_His');
            // 1. 数据备份（数据库 + 用户上传）
            $bakDir = Backup::dir() . '/upgrade_' . $ts . '_' . $tag;
            Backup::ensureDir();
            @mkdir($bakDir, 0775, true);
            file_put_contents($bakDir . '/blog.sql', Backup::sqlDump(), LOCK_EX);
            Backup::copyUploads($bakDir . '/uploads');

            // 2. 下载源码压缩包（直连优先 → 加速镜像 → Gitee 归档包）
            $zipUrl = 'https://github.com/' . self::githubRepo() . '/archive/refs/tags/v' . $tag . '.zip';
            $alt = self::giteeRepo() !== ''
                ? ['https://gitee.com/' . self::giteeRepo() . '/archive/v' . $tag . '.zip']
                : [];
            $zipPath = self::download($zipUrl, $tmp . '/release.zip', $alt);

            // 3. 安全解压
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
            $replaced = self::apply($root);
            if ($replaced === 0) {
                throw new \RuntimeException('没有可替换的文件，已中止');
            }
            return [true, '升级成功：代码已从 v' . Context::VERSION . ' 更新为 v' . $tag
                . '（替换 ' . $replaced . ' 个文件）。数据已自动备份到 backups/upgrade_' . $ts . '_' . $tag
                . '，请重启服务生效。'];
        } catch (\Throwable $e) {
            return [false, '升级失败：' . $e->getMessage()];
        } finally {
            self::rmTree($tmp);
            if (is_file($lock)) {
                @unlink($lock);
            }
        }
    }

    /** 尝试直连 → 镜像 → 备用源，全部失败抛最后一个错误 */
    private static function download(string $url, string $dest, array $altUrls = []): string
    {
        $attempts = array_merge([$url], array_map(static fn (string $m): string => $m . $url, self::mirrors()), $altUrls);
        $lastErr = null;
        foreach ($attempts as $u) {
            try {
                self::streamDownload($u, $dest);
                return $dest;
            } catch (\Throwable $e) {
                $lastErr = $e;
                if (is_file($dest)) {
                    @unlink($dest);
                }
            }
        }
        throw $lastErr ?? new \RuntimeException('下载失败');
    }

    /** 流式下载到文件，超过大小上限即中止 */
    private static function streamDownload(string $url, string $dest): void
    {
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
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'infowe-Blog-updater',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($res, $dlTotal, $dlNow): int {
                return $dlNow > self::MAX_BYTES ? 1 : 0; // 返回非 0 中止传输
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
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
        return in_array(strtolower((string) pathinfo($rel, PATHINFO_EXTENSION)), self::SKIP_EXT, true);
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