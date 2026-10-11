<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Db;

/**
 * 备份服务：数据库 SQL 转储、上传文件拷贝、备份文件列举与格式化。
 * 原 Flask 版为 SQLite 文件拷贝，PHP 版改用 MySQL，故以 SQL 转储等价实现。
 */
class Backup
{
    /** 备份根目录 */
    public static function dir(): string
    {
        return PHP_BLOG_ROOT . '/backups';
    }

    public static function ensureDir(): void
    {
        $d = self::dir();
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
    }

    /** 列出备份文件（仅文件，不含 upgrade_* 目录），按文件名倒序（最新在前） */
    public static function list(): array
    {
        self::ensureDir();
        $names = [];
        foreach (scandir(self::dir()) ?: [] as $f) {
            if ($f === '.' || $f === '..' || !is_file(self::dir() . '/' . $f)) {
                continue;
            }
            $names[] = $f;
        }
        rsort($names);
        $out = [];
        foreach ($names as $f) {
            $fp = self::dir() . '/' . $f;
            $out[] = [
                'filename' => $f,
                'size' => Upload::fmtSize((int) filesize($fp)),
                'mtime' => date('Y-m-d H:i', (int) filemtime($fp)),
            ];
        }
        return $out;
    }

    /** 生成全库 SQL 转储文本（含建表与数据，utf8mb4） */
    public static function sqlDump(): string
    {
        $pdo = Db::pdo();
        $lines = [
            '-- infowe blog 数据库备份',
            '-- 生成时间: ' . date('Y-m-d H:i:s'),
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
        ];
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $name = (string) $t;
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $name) . '`')->fetch(\PDO::FETCH_ASSOC);
            $lines[] = '';
            $lines[] = 'DROP TABLE IF EXISTS `' . $name . '`;';
            $lines[] = (string) ($create['Create Table'] ?? '') . ';';
            foreach ($pdo->query('SELECT * FROM `' . $name . '`') as $row) {
                $vals = [];
                foreach (array_values($row) as $v) {
                    $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                }
                $lines[] = 'INSERT INTO `' . $name . '` VALUES (' . implode(',', $vals) . ');';
            }
        }
        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        return implode("\n", $lines) . "\n";
    }

    /** 写出数据库备份文件，返回文件名。磁盘满/无权限时抛异常，避免「假备份」后导入覆盖数据 */
    public static function writeDbBackup(?string $filename = null): string
    {
        self::ensureDir();
        $filename = $filename ?? ('blog_backup_' . date('Ymd_His') . '.sql');
        $path = self::dir() . '/' . $filename;
        $ok = @file_put_contents($path, self::sqlDump(), LOCK_EX);
        if ($ok === false || $ok === 0) {
            @unlink($path);
            throw new \RuntimeException('备份写入失败：磁盘已满或目录无写权限');
        }
        return $filename;
    }

    /** 拷贝 uploads/ 到目标目录（升级前数据备份用）。$onProgress 每复制一个文件回调一次（带已复制数量），供上层刷新进度 */
    public static function copyUploads(string $dest, ?callable $onProgress = null): void
    {
        $src = Upload::root();
        if (!is_dir($src)) {
            return;
        }
        self::copyTree($src, $dest, $onProgress);
    }

    /** 递归复制目录。$onFile 可选：每复制一个文件后回调一次（带累计数量） */
    public static function copyTree(string $src, string $dst, ?callable $onFile = null): int
    {
        $count = 0;
        if (!is_dir($dst)) {
            mkdir($dst, 0775, true);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $rel = substr($f->getPathname(), strlen($src) + 1);
            $target = $dst . '/' . $rel;
            if ($f->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                if (@copy($f->getPathname(), $target)) {
                    $count++;
                    if ($onFile !== null) {
                        $onFile($count);
                    }
                }
            }
        }
        return $count;
    }
}