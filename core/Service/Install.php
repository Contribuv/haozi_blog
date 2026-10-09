<?php
declare(strict_types=1);

namespace Blog\Service;

use PDO;
use PDOException;

/**
 * 安装向导服务：未安装判定、环境自检、数据库连接、建表与初始化数据。
 *
 * 判定策略（智能判定）：config/installed.lock 存在即视为已安装（快速路径）；
 * 否则读取当前生效配置，若数据库连不上或 users 表无管理员，则视为未安装；
 * 若数据库中已有管理员（老部署升级到含向导的版本），静默补写标记文件，不打扰使用者。
 */
class Install
{
    /** 最低 PHP 版本要求 */
    public const MIN_PHP = '8.0.0';

    /** 安装完成标记文件 */
    public static function lockFile(): string
    {
        return PHP_BLOG_ROOT . '/config/installed.lock';
    }

    /** 运行时配置文件（安装完成后写入真实凭据） */
    public static function configFile(): string
    {
        return PHP_BLOG_ROOT . '/config/config.php';
    }

    /** 是否需要进入安装向导 */
    public static function isInstalled(): bool
    {
        if (is_file(self::lockFile())) {
            return true;
        }
        // 尚无运行时配置（仍是示例配置）→ 未安装
        if (!is_file(self::configFile())) {
            return false;
        }
        $cfg = @include self::configFile();
        if (!is_array($cfg)) {
            return false;
        }
        $db = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
        if (trim((string) ($db['host'] ?? '')) === '' || trim((string) ($db['dbname'] ?? '')) === '') {
            return false;
        }
        try {
            $pdo = self::makePdo((string) $db['host'], (int) ($db['port'] ?? 3306), (string) $db['dbname'], (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''));
            if (self::adminCount($pdo) > 0) {
                // 老部署自愈：补写标记，后续请求走快速路径
                @file_put_contents(self::lockFile(), date('Y-m-d H:i:s') . " 由已有安装自动标记\n", LOCK_EX);
                return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /**
     * 环境自检（第一步展示）。
     *
     * @return array<int, array{label:string, ok:bool, detail:string}>
     */
    public static function environmentChecks(): array
    {
        $ok = static fn (bool $v): string => $v ? '通过' : '不通过';
        $checks = [];

        $verOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $checks[] = ['label' => 'PHP 版本不低于 ' . self::MIN_PHP, 'ok' => $verOk, 'detail' => '当前 ' . PHP_VERSION];

        $exts = [
            'pdo_mysql' => 'PDO MySQL 扩展',
            'mbstring' => 'mbstring 扩展（中文处理）',
            'curl' => 'cURL 扩展（远程请求）',
            'gd' => 'GD 图像库（水印与缩略图）',
        ];
        foreach ($exts as $ext => $label) {
            $has = extension_loaded($ext);
            $checks[] = ['label' => $label, 'ok' => $has, 'detail' => $ok($has)];
        }

        $dirs = [
            'config' => PHP_BLOG_ROOT . '/config',
            'storage' => PHP_BLOG_ROOT . '/storage',
            'storage/cache' => PHP_BLOG_ROOT . '/storage/cache',
        ];
        foreach ($dirs as $label => $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $writable = is_dir($dir) && is_writable($dir);
            $checks[] = ['label' => $label . ' 目录可写', 'ok' => $writable, 'detail' => $ok($writable)];
        }
        return $checks;
    }

    /** 环境自检是否全部通过 */
    public static function environmentPassed(array $checks): bool
    {
        foreach ($checks as $c) {
            if (!$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * 使用向导输入测试（必要时创建）数据库连接。
     *
     * @param array<string,mixed> $db host/port/dbname/user/pass
     * @return array{ok:bool, error:string, pdo:?PDO}
     */
    public static function connect(array $db): array
    {
        $host = trim((string) ($db['host'] ?? ''));
        $port = (int) ($db['port'] ?? 3306);
        $name = trim((string) ($db['dbname'] ?? ''));
        $user = (string) ($db['user'] ?? '');
        $pass = (string) ($db['pass'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            return ['ok' => false, 'error' => '主机、数据库名与用户名均为必填', 'pdo' => null];
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
            return ['ok' => false, 'error' => '数据库名只允许字母、数字、下划线与短横线', 'pdo' => null];
        }

        // 先连服务器（不带库名），以便在库不存在时自动创建
        try {
            $server = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]
            );
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => '连接数据库服务器失败：' . $e->getMessage(), 'pdo' => null];
        }

        try {
            $server->exec(
                'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name)
                . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
            );
        } catch (PDOException $e) {
            // 无建库权限时忽略，若库已存在仍可继续
        }

        try {
            $pdo = self::makePdo($host, $port, $name, $user, $pass);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => '数据库「' . $name . '」不可用：' . $e->getMessage(), 'pdo' => null];
        }
        return ['ok' => true, 'error' => '', 'pdo' => $pdo];
    }

    /**
     * 执行安装：建表 → 建单管理员触发器 → 初始化设置 → 创建管理员 → 创建感谢信 → 写配置与标记。
     *
     * @param array<string,mixed> $db    host/port/dbname/user/pass
     * @param array<string,mixed> $admin username/password
     * @param array<string,mixed> $site  blog_name/author/email
     * @return array{ok:bool, error:string, warnings:array<int,string>}
     */
    public static function install(array $db, array $admin, array $site): array
    {
        $warnings = [];
        // ── 已有数据保护：库中若已存在管理员，拒绝安装 ──
        $conn = self::connect($db);
        if (!$conn['ok'] || $conn['pdo'] === null) {
            return ['ok' => false, 'error' => $conn['error'], 'warnings' => $warnings];
        }
        /** @var PDO $pdo */
        $pdo = $conn['pdo'];

        try {
            if (self::adminCount($pdo) > 0) {
                return ['ok' => false, 'error' => '该数据库已存在博客数据（users 表已有账号），请换一个空库安装', 'warnings' => $warnings];
            }

            // 1. 建表（复用 SQLite 导入服务的 DDL，保持结构完全一致）
            foreach (SqliteImport::schema() as $def) {
                $pdo->exec($def['sql']);
            }

            // 2. 单管理员守护触发器（users 表恒为 1 条记录）
            //    开启 binlog 时创建触发器需要 SUPER 权限，共享主机常无此权限，此时降级为程序层保证
            try {
                $pdo->exec('DROP TRIGGER IF EXISTS `users_single_admin_guard`');
                $pdo->exec(
                    'CREATE TRIGGER `users_single_admin_guard` BEFORE INSERT ON `users` FOR EACH ROW '
                    . 'BEGIN IF (SELECT COUNT(*) FROM `users`) >= 1 THEN '
                    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'users 表仅允许单个管理员账号'; "
                    . 'END IF; END'
                );
            } catch (\Throwable $e) {
                $warnings[] = '未能创建单管理员守护触发器：数据库开启了 binlog，而当前账号缺少全局 SUPER 权限'
                    . '（MySQL 错误 1419），这是 MySQL 对触发器的独立限制，与建表权限无关。'
                    . '已跳过该数据库层约束，单账号限制由程序层保证。';
            }

            // 3. 初始化设置：先补默认键，再用向导输入覆盖内容类键
            $insSetting = $pdo->prepare('INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (?, ?)');
            foreach (SqliteImport::defaultSettings() as $k => $v) {
                $insSetting->execute([$k, $v]);
            }
            $overrides = [
                'blog_name' => trim((string) ($site['blog_name'] ?? '')) ?: '我的博客',
                'blog_subtitle' => '',
                'author' => trim((string) ($site['author'] ?? '')),
                'author_bio' => '',
                'about_intro' => '',
                'skills' => '[]',
                'contact_email' => trim((string) ($site['email'] ?? '')),
                'notify_email' => trim((string) ($site['email'] ?? '')),
            ];
            $updSetting = $pdo->prepare('UPDATE `settings` SET `value` = ? WHERE `key` = ?');
            foreach ($overrides as $k => $v) {
                $updSetting->execute([$v, $k]);
            }

            // 4. 创建管理员账号（bcrypt）
            $pdo->prepare('INSERT INTO `users` (`username`, `password_hash`) VALUES (?, ?)')
                ->execute([(string) $admin['username'], password_hash((string) $admin['password'], PASSWORD_DEFAULT)]);

            // 5. 创建致谢文章
            self::createLetter($pdo);

            // 6. 写运行时配置与安装标记
            if (@file_put_contents(self::configFile(), self::configCode($db), LOCK_EX) === false) {
                return ['ok' => false, 'error' => '无法写入 config/config.php，请检查 config 目录权限', 'warnings' => $warnings];
            }
            @file_put_contents(self::lockFile(), date('Y-m-d H:i:s') . " 安装完成\n", LOCK_EX);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '安装失败：' . $e->getMessage(), 'warnings' => $warnings];
        }
        return ['ok' => true, 'error' => '', 'warnings' => $warnings];
    }

    /** 管理员账号数量（表不存在返回 0） */
    public static function adminCount(PDO $pdo): int
    {
        if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) {
            return 0;
        }
        return (int) $pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn();
    }

    /** 建立到指定库的 PDO 连接 */
    private static function makePdo(string $host, int $port, string $name, string $user, string $pass): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]
        );
    }

    /** 写入「豪子致使用者」的感谢信（已发布文章） */
    private static function createLetter(PDO $pdo): void
    {
        $slug = 'a-letter-to-users';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `posts` WHERE `slug` = ?');
        $stmt->execute([$slug]);
        if ((int) $stmt->fetchColumn() > 0) {
            $slug .= '-' . date('YmdHis');
        }

        $content = "你好，我是豪子。\n\n"
            . "感谢你选择这套博客系统。\n\n"
            . "它最初只是我个人的技术博客，从 Flask 迁到 PHP，前后重构了好几轮，最终决定开源出来。\n"
            . "它不完美，但每一处细节我都尽力打磨过——干净的排版、克制的配色、能扛住日常访问的后台。\n\n"
            . "如果它能帮到你，哪怕只是省下从零搭建博客的时间，我也会很高兴。\n\n"
            . "几点使用建议：\n\n"
            . "- 后台地址是 `/admin`，用安装时填写的账号密码登录；\n"
            . "- 首次登录后，建议先到「设置」补全站点信息、邮箱与头像；\n"
            . "- 「数据管理」页可以导出数据库备份，建议定期备份；\n"
            . "- 遇到问题或有想法，欢迎到 GitHub 提 Issue。\n\n"
            . "祝写作愉快。\n\n"
            . "—— 豪子";

        $pdo->prepare(
            'INSERT INTO `posts` (`title`, `slug`, `content`, `excerpt`, `tags`, `status`, '
            . '`read_time`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            '致使用者的一封信',
            $slug,
            $content,
            '感谢你选择这套博客系统。',
            json_encode(['随笔'], JSON_UNESCAPED_UNICODE),
            'published',
            2,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);
    }

    /** 生成运行时配置文件内容（含真实凭据，勿提交仓库） */
    private static function configCode(array $db): string
    {
        $host = var_export(trim((string) ($db['host'] ?? '')), true);
        $port = (int) ($db['port'] ?? 3306);
        $name = var_export(trim((string) ($db['dbname'] ?? '')), true);
        $user = var_export((string) ($db['user'] ?? ''), true);
        $pass = var_export((string) ($db['pass'] ?? ''), true);
        $time = date('Y-m-d H:i:s');

        return <<<PHP
<?php
/**
 * 由安装向导生成（{$time}）。
 * 本文件含数据库凭据，请勿提交到代码仓库。
 */
return [
    'db' => [
        'host' => {$host},
        'port' => {$port},
        'dbname' => {$name},
        'user' => {$user},
        'pass' => {$pass},
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],
    'app' => [
        'name' => 'Haozi Blog',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Shanghai',
        'base_path' => '',
    ],
    'session' => [
        'name' => 'PHPBLOG_SESSID',
        'lifetime' => 3600,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'upload' => [
        'max_size' => 20971520,
        'dir' => __DIR__ . '/../storage/uploads',
        'base_url' => '/uploads',
    ],
    'theme' => [
        'default' => 'tech',
    ],
];
PHP;
    }
}