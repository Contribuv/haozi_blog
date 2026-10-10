<?php
declare(strict_types=1);

namespace Blog\Model;

/**
 * 友情链接数据模型，对照 db_load_links / db_save_link / db_set_link_status / db_delete_link。
 */
class Link extends Model
{
    /** 链接列表，status 传 null 取全部 */
    public static function load(?string $status = null): array
    {
        if ($status !== null) {
            $rows = self::rows(
                'SELECT * FROM links WHERE status = ? ORDER BY sort_order ASC, id ASC',
                [$status]
            );
        } else {
            $rows = self::rows('SELECT * FROM links ORDER BY sort_order ASC, id ASC');
        }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sort_order'] = (int) ($r['sort_order'] ?? 0);
        }
        return $rows;
    }

    /** 按 id 取链接 */
    public static function get(int $id): ?array
    {
        $row = self::one('SELECT * FROM links WHERE id = ?', [$id]);
        return $row ?: null;
    }

    /** 新建或更新链接；$status 仅在新建时生效 */
    public static function save(array $form, ?int $linkId = null, ?string $status = null): int
    {
        $name = (string) ($form['name'] ?? '');
        $url = (string) ($form['url'] ?? '');
        $desc = (string) ($form['description'] ?? '');
        $avatar = trim((string) ($form['avatar'] ?? ''));
        $sortOrder = (int) ($form['sort_order'] ?? 0);

        if ($linkId !== null) {
            self::exec(
                'UPDATE links SET name=?, url=?, description=?, avatar=?, sort_order=? WHERE id=?',
                [$name, $url, $desc, $avatar, $sortOrder, $linkId]
            );
            return $linkId;
        }
        self::exec(
            'INSERT INTO links (name, url, description, avatar, sort_order, status) VALUES (?,?,?,?,?,?)',
            [$name, $url, $desc, $avatar, $sortOrder, $status ?? 'approved']
        );
        return self::insertId();
    }

    /** 变更审核状态 */
    public static function setStatus(int $id, string $status): void
    {
        self::exec('UPDATE links SET status = ? WHERE id = ?', [$status, $id]);
    }

    /** 删除链接 */
    public static function delete(int $id): void
    {
        self::exec('DELETE FROM links WHERE id = ?', [$id]);
    }
}
