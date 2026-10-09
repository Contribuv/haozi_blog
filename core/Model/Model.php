<?php
declare(strict_types=1);

namespace Blog\Model;

use Blog\Db;

/**
 * Model 基类：封装 PDO 查询与常见行处理，供各数据模型复用。
 */
abstract class Model
{
    /** 执行查询并返回全部行 */
    protected static function rows(string $sql, array $params = []): array
    {
        return Db::query($sql, $params)->fetchAll();
    }

    /** 执行查询并返回首行，无则返回 null */
    protected static function one(string $sql, array $params = []): ?array
    {
        $row = Db::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 执行查询并返回首列标量值 */
    protected static function scalar(string $sql, array $params = []): mixed
    {
        $v = Db::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 执行写操作，返回受影响行数 */
    protected static function exec(string $sql, array $params = []): int
    {
        return Db::query($sql, $params)->rowCount();
    }

    /** 插入并返回自增主键 */
    protected static function insertId(): int
    {
        return (int) Db::pdo()->lastInsertId();
    }

    /** 解析 JSON 数组字段，失败或无值返回空数组 */
    protected static function jsonList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $v = json_decode($raw, true);
        return is_array($v) ? $v : [];
    }

    /** 结构化数组编码为 JSON（中文不转义） */
    protected static function jsonEncode(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /** 当前时间，格式 YYYY-MM-DD HH:MM:SS */
    protected static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
