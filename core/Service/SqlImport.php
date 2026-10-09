<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Db;

/**
 * MySQL SQL 转储导入服务（对应后台「数据库备份」生成的 .sql 文件）。
 *
 * 与 SqliteImport 的差别：SQLite 导入是按已知表结构逐表搬运数据；
 * 本服务是直接执行 SQL 语句（DROP TABLE / CREATE TABLE / INSERT / SET ...），
 * 因此能完整还原表结构与数据，也兼容其他工具（mysqldump、phpMyAdmin）导出的文件。
 *
 * 注意：MySQL 的 DDL 会隐式提交，导入中途失败无法整体回滚，
 *       调用方必须先备份当前库（后台入口已自动备份）。
 */
class SqlImport
{
    /**
     * 预检：文件存在、非空且能解析出建表语句。不合法时完全不碰目标库。
     *
     * @return array{ok:bool, error:string, tables:array<int,string>, statements:int}
     */
    public static function inspect(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => '文件不存在', 'tables' => [], 'statements' => 0];
        }
        if ((int) filesize($path) === 0) {
            return ['ok' => false, 'error' => '文件为空', 'tables' => [], 'statements' => 0];
        }
        $sql = @file_get_contents($path);
        if ($sql === false) {
            return ['ok' => false, 'error' => '文件读取失败', 'tables' => [], 'statements' => 0];
        }

        $statements = self::split($sql);
        $tables = self::tablesOf($statements);
        if (!$tables) {
            return [
                'ok' => false,
                'error' => '未发现 CREATE TABLE 语句，不是可用的 SQL 备份文件',
                'tables' => [],
                'statements' => 0,
            ];
        }
        return ['ok' => true, 'error' => '', 'tables' => $tables, 'statements' => count($statements)];
    }

    /**
     * 执行导入：逐条执行 SQL，成功后补齐 settings 缺失的默认键。
     *
     * @param bool $keepUsers true 时跳过所有涉及 users 表的语句，保留当前账号密码
     * @return array{statements:int, done:int, skipped:int, tables:array<int,string>, added:int}
     */
    public static function run(string $path, bool $keepUsers = false): array
    {
        $sql = (string) file_get_contents($path);
        $all = self::split($sql);
        if (!$all) {
            throw new \RuntimeException('未解析到可执行的 SQL 语句');
        }

        $dst = Db::pdo();
        $done = 0;
        $skipped = 0;
        foreach ($all as $stmt) {
            if ($keepUsers && self::touchesUsers($stmt)) {
                $skipped++;
                continue;
            }
            try {
                $dst->exec($stmt);
            } catch (\Throwable $e) {
                // 已执行部分无法回滚（DDL 隐式提交），由调用方用事前备份恢复
                throw new \RuntimeException('第 ' . ($done + 1) . ' 条语句执行失败：' . $e->getMessage(), 0, $e);
            }
            $done++;
        }

        // 补齐 settings 默认键（INSERT IGNORE，绝不覆盖备份中的已有值）
        $added = 0;
        if ((bool) $dst->query("SHOW TABLES LIKE 'settings'")->fetchColumn()) {
            $ins = $dst->prepare('INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (?, ?)');
            foreach (SqliteImport::defaultSettings() as $k => $v) {
                $ins->execute([$k, $v]);
                $added += $ins->rowCount();
            }
        }

        return [
            'statements' => count($all),
            'done' => $done,
            'skipped' => $skipped,
            'tables' => self::tablesOf($all),
            'added' => $added,
        ];
    }

    /**
     * 把 SQL 文本切分为独立语句：忽略注释，且不把引号 / 反引号内的分号当分隔符。
     * 兼容单引号（支持 '' 与 \' 两种转义）、双引号、反引号、-- / # 行注释、块注释。
     *
     * @return array<int,string>
     */
    private static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inSingle = $inDouble = $inBacktick = $inLine = $inBlock = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLine) {                       // 行注释：到换行为止
                if ($ch === "\n") {
                    $inLine = false;
                }
                continue;
            }
            if ($inBlock) {                      // 块注释：到 */ 为止
                if ($ch === '*' && $next === '/') {
                    $inBlock = false;
                    $i++;
                }
                continue;
            }
            if ($inSingle) {
                $buf .= $ch;
                if ($ch === '\\') {              // \x 转义：整体保留
                    $buf .= $next;
                    $i++;
                } elseif ($ch === "'") {
                    if ($next === "'") {         // '' 转义
                        $buf .= $next;
                        $i++;
                    } else {
                        $inSingle = false;
                    }
                }
                continue;
            }
            if ($inDouble) {
                $buf .= $ch;
                if ($ch === '\\') {
                    $buf .= $next;
                    $i++;
                } elseif ($ch === '"') {
                    $inDouble = false;
                }
                continue;
            }
            if ($inBacktick) {
                $buf .= $ch;
                if ($ch === '`') {
                    $inBacktick = false;
                }
                continue;
            }

            // 普通状态
            if ($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                $inLine = true;
                $i++;
                continue;
            }
            if ($ch === '#') {
                $inLine = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlock = true;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                $buf .= $ch;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $buf .= $ch;
                continue;
            }
            if ($ch === '`') {
                $inBacktick = true;
                $buf .= $ch;
                continue;
            }
            if ($ch === ';') {
                $trim = trim($buf);
                if ($trim !== '') {
                    $out[] = $trim;
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }

        $trim = trim($buf);
        if ($trim !== '') {
            $out[] = $trim;
        }
        return $out;
    }

    /**
     * 提取语句中 CREATE TABLE 的表名。
     *
     * @param array<int,string> $statements
     * @return array<int,string>
     */
    private static function tablesOf(array $statements): array
    {
        $tables = [];
        foreach ($statements as $s) {
            if (preg_match(
                '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
                $s,
                $m
            )) {
                $tables[] = $m[1];
            }
        }
        return $tables;
    }

    /** 判断语句是否针对 users 表（用于 --keep-users 时整体跳过该表） */
    private static function touchesUsers(string $stmt): bool
    {
        return (bool) preg_match(
            '/^\s*(?:DROP\s+TABLE|CREATE\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE\s+TABLE)'
            . '\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?users`?\b/i',
            $stmt
        );
    }
}
