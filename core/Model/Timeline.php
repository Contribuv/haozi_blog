<?php
declare(strict_types=1);

namespace Blog\Model;

/**
 * 时间线数据模型（timeline 表：date / content / sort_order）。
 */
class Timeline extends Model
{
    /** 时间线列表（对照原项目：sort_order 升序，其次日期倒序） */
    public static function load(): array
    {
        $rows = self::rows('SELECT * FROM timeline ORDER BY sort_order ASC, date DESC');
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sort_order'] = (int) ($r['sort_order'] ?? 0);
        }
        return $rows;
    }

    /** 按 id 取单条 */
    public static function get(int $id): ?array
    {
        $row = self::one('SELECT * FROM timeline WHERE id = ?', [$id]);
        return $row ?: null;
    }

    /** 新建或更新时间线条目 */
    public static function save(array $form, ?int $itemId = null): int
    {
        $date = (string) ($form['date'] ?? '');
        $content = (string) ($form['content'] ?? '');
        $sortOrder = (int) ($form['sort_order'] ?? 0);

        if ($itemId !== null) {
            self::exec('UPDATE timeline SET date=?, content=?, sort_order=? WHERE id=?', [$date, $content, $sortOrder, $itemId]);
            return $itemId;
        }
        self::exec('INSERT INTO timeline (date, content, sort_order) VALUES (?,?,?)', [$date, $content, $sortOrder]);
        return self::insertId();
    }

    /** 删除单条 */
    public static function delete(int $id): void
    {
        self::exec('DELETE FROM timeline WHERE id = ?', [$id]);
    }
}
