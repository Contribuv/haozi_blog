<?php
declare(strict_types=1);

namespace Blog\Model;

/**
 * 记忆卡片数据模型（memories 表：title / text / image / date / sort_order）。
 */
class Memory extends Model
{
    /** 全部记忆卡片（按排序与日期） */
    public static function load(): array
    {
        $rows = self::rows('SELECT * FROM memories ORDER BY sort_order ASC, date DESC, id DESC');
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sort_order'] = (int) ($r['sort_order'] ?? 0);
        }
        return $rows;
    }

    /** 按 id 取单条 */
    public static function get(int $id): ?array
    {
        $row = self::one('SELECT * FROM memories WHERE id = ?', [$id]);
        return $row ?: null;
    }

    /** 新建或更新记忆卡片 */
    public static function save(array $form, ?int $id = null): int
    {
        $title = (string) ($form['title'] ?? '');
        $text = (string) ($form['text'] ?? '');
        $image = (string) ($form['image'] ?? '');
        $date = (string) ($form['date'] ?? '');
        $sortOrder = (int) ($form['sort_order'] ?? 0);

        if ($id !== null) {
            self::exec(
                'UPDATE memories SET title=?, text=?, image=?, date=?, sort_order=? WHERE id=?',
                [$title, $text, $image, $date, $sortOrder, $id]
            );
            return $id;
        }
        self::exec(
            'INSERT INTO memories (title, text, image, date, sort_order) VALUES (?,?,?,?,?)',
            [$title, $text, $image, $date, $sortOrder]
        );
        return self::insertId();
    }

    /** 删除单条 */
    public static function delete(int $id): void
    {
        self::exec('DELETE FROM memories WHERE id = ?', [$id]);
    }
}
