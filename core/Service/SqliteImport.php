<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Db;
use PDO;

/**
 * SQLite → MySQL 数据导入服务。
 *
 * 供两处复用：命令行 bin/migrate.php（原 Flask 版库迁移）与后台「SQLite 导入」。
 * 流程：建表（IF NOT EXISTS）→ 逐表 TRUNCATE 后全量重插 → 补 settings 缺失默认键 → 行数校验。
 * 幂等：重复执行数据不翻倍。
 *
 * 注意：这是整库覆盖式操作，调用方必须先备份目标库（后台入口已自动备份）。
 */
class SqliteImport
{
    /**
     * 各表 MySQL DDL 与迁移列清单（列集合与 app.py 实际 schema 核对一致）。
     * 说明：TEXT→MEDIUMTEXT；TIMESTAMP→DATETIME；slug/key/username 等需唯一索引的
     * 短文本列用 VARCHAR(191)（MEDIUMTEXT 无法直接建不带前缀长度的唯一索引）。
     */
    public static function schema(): array
    {
        return [
            'posts' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `posts` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `title` MEDIUMTEXT NOT NULL,
                    `slug` VARCHAR(191) NOT NULL,
                    `content` MEDIUMTEXT NOT NULL,
                    `excerpt` MEDIUMTEXT,
                    `tags` MEDIUMTEXT,
                    `cover` MEDIUMTEXT,
                    `read_time` INT DEFAULT 3,
                    `views` INT DEFAULT 0,
                    `status` VARCHAR(20) DEFAULT \'published\',
                    `is_featured` INT DEFAULT 0,
                    `category_id` INT DEFAULT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_posts_slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'title', 'slug', 'content', 'excerpt', 'tags', 'cover',
                           'read_time', 'views', 'status', 'is_featured', 'category_id',
                           'created_at', 'updated_at'],
            ],
            'settings' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `settings` (
                    `key` VARCHAR(191) NOT NULL PRIMARY KEY,
                    `value` MEDIUMTEXT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['key', 'value'],
            ],
            'users' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `users` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `username` VARCHAR(191) NOT NULL,
                    `password_hash` MEDIUMTEXT NOT NULL,
                    UNIQUE KEY `uq_users_username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'username', 'password_hash'],
            ],
            'projects' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `projects` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `name` MEDIUMTEXT NOT NULL,
                    `description` MEDIUMTEXT,
                    `url` MEDIUMTEXT,
                    `stars` INT DEFAULT 0,
                    `language` MEDIUMTEXT,
                    `topics` MEDIUMTEXT,
                    `sort_order` INT DEFAULT 0,
                    `featured` INT DEFAULT 0,
                    `github_repo` MEDIUMTEXT,
                    `custom_name` INT DEFAULT 0,
                    `languages` MEDIUMTEXT,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'name', 'description', 'url', 'stars', 'language', 'topics',
                           'sort_order', 'featured', 'github_repo', 'custom_name', 'languages',
                           'created_at'],
            ],
            'links' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `links` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `name` MEDIUMTEXT NOT NULL,
                    `url` MEDIUMTEXT NOT NULL,
                    `description` MEDIUMTEXT,
                    `avatar` MEDIUMTEXT,
                    `sort_order` INT DEFAULT 0,
                    `status` VARCHAR(20) DEFAULT \'approved\',
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'name', 'url', 'description', 'avatar', 'sort_order',
                           'status', 'created_at'],
            ],
            'comments' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `comments` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `post_id` INT DEFAULT NULL,
                    `project_id` INT DEFAULT NULL,
                    `parent_id` INT DEFAULT NULL,
                    `author` VARCHAR(191) NOT NULL DEFAULT \'Anonymous\',
                    `email` MEDIUMTEXT,
                    `email_hash` MEDIUMTEXT,
                    `website` MEDIUMTEXT,
                    `content` MEDIUMTEXT NOT NULL,
                    `status` VARCHAR(20) DEFAULT \'pending\',
                    `is_private` INT DEFAULT 0,
                    `qq` MEDIUMTEXT,
                    `ip_text` MEDIUMTEXT,
                    `ip_location` MEDIUMTEXT,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_comments_post_id` (`post_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'post_id', 'project_id', 'parent_id', 'author', 'email',
                           'email_hash', 'website', 'content', 'status', 'is_private',
                           'qq', 'ip_text', 'ip_location', 'created_at'],
            ],
            'timeline' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `timeline` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `date` MEDIUMTEXT NOT NULL,
                    `content` MEDIUMTEXT NOT NULL,
                    `sort_order` INT DEFAULT 0,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'date', 'content', 'sort_order', 'created_at'],
            ],
            'memories' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `memories` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `title` MEDIUMTEXT NOT NULL,
                    `text` MEDIUMTEXT NOT NULL,
                    `image` MEDIUMTEXT NOT NULL,
                    `date` MEDIUMTEXT,
                    `sort_order` INT DEFAULT 0,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'title', 'text', 'image', 'date', 'sort_order', 'created_at'],
            ],
            'categories' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `categories` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `name` MEDIUMTEXT NOT NULL,
                    `slug` VARCHAR(191) NOT NULL,
                    `sort_order` INT DEFAULT 0,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_categories_slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'name', 'slug', 'sort_order', 'created_at'],
            ],
            'service_checks' => [
                'sql' => 'CREATE TABLE IF NOT EXISTS `service_checks` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `service_id` VARCHAR(100) NOT NULL,
                    `checked_at` DOUBLE NOT NULL,
                    `ok` INT NOT NULL DEFAULT 0,
                    `latency_ms` INT DEFAULT 0,
                    `cert_days` INT DEFAULT -1,
                    `detail` MEDIUMTEXT,
                    KEY `idx_service_checks` (`service_id`, `checked_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
                'cols' => ['id', 'service_id', 'checked_at', 'ok', 'latency_ms',
                           'cert_days', 'detail'],
            ],
        ];
    }

    /**
     * settings 默认键（对应 app.py init_db 的 defaults，仅补缺失、不覆盖已有值）。
     */
    public static function defaultSettings(): array
    {
        return [
            'blog_name' => 'infowe',
            'blog_subtitle' => 'Python · Code · Life',
            'author' => 'Linus',
            'author_bio' => "Python 全栈开发者，热爱开源，沉迷于代码美学与系统架构。\n相信每一行代码都有它的灵魂，每一个 Bug 都是成长的阶梯。",
            'skills' => '[{"name":"Python","level":95},{"name":"Flask / Django","level":90},{"name":"JavaScript","level":85},{"name":"Docker / K8s","level":75},{"name":"PostgreSQL","level":85},{"name":"Redis","level":80},{"name":"Linux","level":88}]',
            'about_intro' => 'infowe 是一个专注于 Python 生态的独立技术博客，使用 Flask 构建，文章以 Markdown 编写。',
            'avatar' => '',
            'github_username' => '',
            'social_github' => '',
            'github_token' => '',
            'contact_email' => '',
            'home_title' => '',
            'home_posts_count' => '6',
            'posts_per_page' => '20',
            'comments_enabled' => '1',
            'comment_notify' => '1',
            'smtp_host' => '',
            'smtp_sender_name' => '',
            'smtp_port' => '465',
            'smtp_user' => '',
            'smtp_pass' => '',
            'notify_email' => '',
            'nav_posts' => '1',
            'nav_tags' => '1',
            'nav_projects' => '1',
            'nav_links' => '1',
            'nav_status' => '1',
            'nav_about' => '1',
            'icp_beian' => '',
            'police_beian' => '',
            'aliyun_access_key' => '',
            'aliyun_access_secret' => '',
            'aliyun_region' => 'cn-hangzhou',
            'aliyun_instance_id' => '',
            'tencent_secret_id' => '',
            'tencent_secret_key' => '',
            'tencent_domain' => '',
            'expiry_aliyun' => '',
            'expiry_tencent' => '',
            'monitor_services' => '[]',
            'watermark_enabled' => '1',
            'watermark_text' => '',
            'watermark_position' => 'br',
            'watermark_size' => 'm',
        ];
    }

    /** 打开 SQLite 源库（只读） */
    private static function openSource(string $path): PDO
    {
        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * 预检：确认文件可作 SQLite 打开、必需表齐全，并返回各表行数。
     * 不合法时不动目标库。
     *
     * @return array{ok:bool, error:string, tables:array<string,int>}
     */
    public static function inspect(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => '文件不存在', 'tables' => []];
        }
        try {
            $src = self::openSource($path);
            $found = [];
            foreach ($src->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) {
                $found[(string) $r['name']] = true;
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '不是有效的 SQLite 数据库文件', 'tables' => []];
        }

        $missing = [];
        foreach (array_keys(self::schema()) as $t) {
            if (!isset($found[$t])) {
                $missing[] = $t;
            }
        }
        if ($missing) {
            return ['ok' => false, 'error' => '缺少数据表：' . implode('、', $missing), 'tables' => []];
        }

        $counts = [];
        foreach (array_keys(self::schema()) as $t) {
            $counts[$t] = (int) $src->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        }
        return ['ok' => true, 'error' => '', 'tables' => $counts];
    }

    /**
     * 执行导入：建表 → TRUNCATE 重插 → 补默认键 → 行数校验。
     * 任一步抛异常即中断（目标库可能处于半覆盖状态，调用方需依靠事前备份回滚）。
     *
     * @param bool $keepUsers true 时跳过 users 表，保留当前账号密码
     * @return array{tables:array<string,int>, added:int, failed:bool, verify:array<string,array{0:int,1:int}>}
     */
    public static function run(string $path, bool $keepUsers = false): array
    {
        $schema = self::schema();
        $src = self::openSource($path);
        $dst = Db::pdo();

        // 第一步：建表（IF NOT EXISTS）
        foreach ($schema as $def) {
            $dst->exec($def['sql']);
        }

        // 第二步：TRUNCATE 后全量搬运（显式带 id，保留原主键）
        $counts = [];
        $dst->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($schema as $table => $def) {
                if ($keepUsers && $table === 'users') {
                    continue;
                }
                $dst->exec("TRUNCATE TABLE `{$table}`");
                $quoted = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $def['cols']));
                $rows = $src->query("SELECT {$quoted} FROM `{$table}`")->fetchAll();
                $counts[$table] = count($rows);
                if (!$rows) {
                    continue;
                }
                $placeholders = implode(', ', array_fill(0, count($def['cols']), '?'));
                $ins = $dst->prepare("INSERT INTO `{$table}` ({$quoted}) VALUES ({$placeholders})");
                $dst->beginTransaction();
                foreach ($rows as $row) {
                    $ins->execute(array_values($row));
                }
                $dst->commit();
            }
        } finally {
            $dst->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        // 第三步：补 settings 缺失默认键（INSERT IGNORE，绝不覆盖已有值）
        $added = 0;
        $insSetting = $dst->prepare('INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (?, ?)');
        foreach (self::defaultSettings() as $k => $v) {
            $insSetting->execute([$k, $v]);
            $added += $insSetting->rowCount();
        }

        // 第四步：行数校验（settings 扣除补入的默认键，属预期差异）
        $failed = false;
        $verify = [];
        foreach ($schema as $table => $def) {
            if ($keepUsers && $table === 'users') {
                continue;
            }
            $srcCount = (int) $src->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $dstCount = (int) $dst->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            if ($table === 'settings') {
                $dstCount -= $added;
            }
            $verify[$table] = [$srcCount, $dstCount];
            if ($srcCount !== $dstCount) {
                $failed = true;
            }
        }

        return ['tables' => $counts, 'added' => $added, 'failed' => $failed, 'verify' => $verify];
    }
}
