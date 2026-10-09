<?php
declare(strict_types=1);

namespace Blog\Model;

/**
 * 文章分类数据模型，对照 db_load_categories / db_get_category / db_save_category / db_delete_category。
 */
class Category extends Model
{
    /** 分类列表（含各分类下文章数） */
    public static function load(): array
    {
        $rows = self::rows(
            'SELECT c.*, (SELECT COUNT(*) FROM posts p WHERE p.category_id = c.id) AS post_count '
            . 'FROM categories c ORDER BY c.sort_order ASC, c.id ASC'
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['post_count'] = (int) $r['post_count'];
        }
        return $rows;
    }

    /** id => name 映射 */
    public static function nameMap(): array
    {
        $map = [];
        foreach (self::load() as $c) {
            $map[$c['id']] = $c['name'];
        }
        return $map;
    }

    /** 按 id 取分类 */
    public static function get(int $id): ?array
    {
        $row = self::one('SELECT * FROM categories WHERE id = ?', [$id]);
        return $row ?: null;
    }

    /** 新建或更新分类 */
    public static function save(array $form, ?int $catId = null): int
    {
        $name = trim((string) ($form['name'] ?? ''));
        $slug = trim((string) ($form['slug'] ?? ''));
        if ($slug === '') {
            $slug = mb_substr(preg_replace('/[^\w\-]/u', '-', mb_strtolower($name)) ?? '', 0, 40);
            if ($slug === '' || $slug === '-') {
                $slug = 'category';
            }
        }
        // slug 唯一化
        if ($catId !== null) {
            $exists = self::scalar('SELECT id FROM categories WHERE slug = ? AND id != ?', [$slug, $catId]);
        } else {
            $exists = self::scalar('SELECT id FROM categories WHERE slug = ?', [$slug]);
        }
        if ($exists !== null) {
            $slug .= '-' . ($catId ?? time());
        }
        $sortOrder = (int) ($form['sort_order'] ?? 0);

        if ($catId !== null) {
            self::exec('UPDATE categories SET name=?, slug=?, sort_order=? WHERE id=?', [$name, $slug, $sortOrder, $catId]);
            return $catId;
        }
        self::exec('INSERT INTO categories (name, slug, sort_order) VALUES (?,?,?)', [$name, $slug, $sortOrder]);
        return self::insertId();
    }

    /** 删除分类并解除文章绑定 */
    public static function delete(int $catId): void
    {
        self::exec('UPDATE posts SET category_id = NULL WHERE category_id = ?', [$catId]);
        self::exec('DELETE FROM categories WHERE id = ?', [$catId]);
    }
}
