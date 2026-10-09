<?php
declare(strict_types=1);

namespace Blog\Model;

use Blog\Service\Upload;

/**
 * 文章数据模型，对照原 Flask 项目 db_load_posts / db_get_* / db_save_post 等函数。
 * tags 字段在库中为 JSON 文本，读出时统一解码为数组。
 */
class Post extends Model
{
    /** 每页默认条数 */
    public const PAGE_SIZE = 10;

    /** 把数据行整理为对外结构（tags 解码） */
    private static function hydrate(array $row): array
    {
        $row['tags'] = self::jsonList($row['tags'] ?? null);
        $row['id'] = (int) $row['id'];
        $row['views'] = (int) ($row['views'] ?? 0);
        $row['is_featured'] = (int) ($row['is_featured'] ?? 0);
        $row['read_time'] = (int) ($row['read_time'] ?? 3);
        $row['category_id'] = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $row['category_name'] = '';
        return $row;
    }

    /**
     * 文章列表（对齐 db_load_posts）。
     * status 传 null 表示不限制状态；返回 [posts, total]。
     */
    public static function load(
        ?string $status = 'published',
        ?string $tag = null,
        ?string $search = null,
        ?string $year = null,
        int $page = 1,
        int $perPage = self::PAGE_SIZE
    ): array {
        $conditions = [];
        $params = [];

        if ($status !== null) {
            $conditions[] = 'status = ?';
            $params[] = $status;
        }
        if ($tag !== null && $tag !== '') {
            $conditions[] = 'tags LIKE ?';
            $params[] = '%"' . $tag . '"%';
        }
        if ($search !== null && $search !== '') {
            $conditions[] = '(title LIKE ? OR content LIKE ? OR excerpt LIKE ?)';
            $s = '%' . $search . '%';
            array_push($params, $s, $s, $s);
        }
        if ($year !== null && $year !== '') {
            $conditions[] = 'SUBSTR(created_at, 1, 4) = ?';
            $params[] = $year;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $total = (int) self::scalar("SELECT COUNT(*) FROM posts {$where}", $params);

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;
        $rows = self::rows(
            "SELECT * FROM posts {$where} ORDER BY is_featured DESC, created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        // 钩子位 posts.query：插件可改写返回的文章列表
        $posts = array_map([self::class, 'hydrate'], $rows);
        $payload = \Blog\Hook::emit('posts.query', [
            'status' => $status, 'tag' => $tag, 'search' => $search, 'year' => $year,
            'page' => $page, 'per_page' => $perPage, 'posts' => $posts, 'total' => $total,
        ]);
        if (is_array($payload) && isset($payload['posts']) && is_array($payload['posts'])) {
            $posts = $payload['posts'];
        }
        return [$posts, $total];
    }

    /** 所有已发布文章的年份集合（降序） */
    public static function allYears(): array
    {
        $rows = self::rows(
            "SELECT DISTINCT SUBSTR(created_at, 1, 4) AS year FROM posts WHERE status = 'published' ORDER BY year DESC"
        );
        return array_map(static fn (array $r): string => (string) $r['year'], $rows);
    }

    /** 按 slug 取文章 */
    public static function getBySlug(string $slug): ?array
    {
        $row = self::one('SELECT * FROM posts WHERE slug = ?', [$slug]);
        return $row ? self::hydrate($row) : null;
    }

    /** 按 id 取文章 */
    public static function getById(int $id): ?array
    {
        $row = self::one('SELECT * FROM posts WHERE id = ?', [$id]);
        return $row ? self::hydrate($row) : null;
    }

    /** 相关文章：按标签逐个匹配，去重后取前 limit 篇 */
    public static function related(int $currentId, array $tags, int $limit = 3): array
    {
        $results = [];
        $seen = [];
        foreach ($tags as $tag) {
            if ($tag === '') {
                continue;
            }
            $rows = self::rows(
                "SELECT * FROM posts WHERE status = 'published' AND id != ? AND tags LIKE ? ORDER BY created_at DESC LIMIT ?",
                [$currentId, '%"' . $tag . '"%', $limit]
            );
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $results[] = self::hydrate($r);
                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }
        return array_slice($results, 0, $limit);
    }

    /** 精选文章 */
    public static function featured(int $limit = 3): array
    {
        $rows = self::rows(
            "SELECT * FROM posts WHERE status = 'published' AND is_featured = 1 ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * 首页文章：默认精选置顶其次最新。
     * excludeFeatured=true 时主列表仅取普通文章（精选由首页独立卡展示，避免重复）。
     */
    public static function homePosts(int $limit = 6, bool $excludeFeatured = false): array
    {
        $featuredRows = self::rows(
            "SELECT * FROM posts WHERE status = 'published' AND is_featured = 1 ORDER BY created_at DESC"
        );
        $featuredIds = array_map(static fn (array $r): int => (int) $r['id'], $featuredRows);

        if ($excludeFeatured) {
            if ($featuredIds) {
                $ph = implode(',', array_fill(0, count($featuredIds), '?'));
                $rows = self::rows(
                    "SELECT * FROM posts WHERE status = 'published' AND id NOT IN ({$ph}) ORDER BY created_at DESC LIMIT ?",
                    array_merge($featuredIds, [$limit])
                );
            } else {
                $rows = self::rows(
                    "SELECT * FROM posts WHERE status = 'published' ORDER BY created_at DESC LIMIT ?",
                    [$limit]
                );
            }
        } else {
            if ($featuredIds) {
                $ph = implode(',', array_fill(0, count($featuredIds), '?'));
                $latest = self::rows(
                    "SELECT * FROM posts WHERE status = 'published' AND id NOT IN ({$ph}) ORDER BY created_at DESC",
                    $featuredIds
                );
            } else {
                $latest = self::rows(
                    "SELECT * FROM posts WHERE status = 'published' ORDER BY created_at DESC"
                );
            }
            $rows = array_merge($featuredRows, $latest);
        }

        $rows = array_slice($rows, 0, $limit);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** 全部文章标签及出现次数（含草稿，按频率降序） */
    public static function allTags(): array
    {
        $rows = self::rows('SELECT tags FROM posts');
        $counts = [];
        foreach ($rows as $r) {
            foreach (self::jsonList($r['tags'] ?? null) as $tag) {
                $tag = (string) $tag;
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    /** 后台统计概览 */
    public static function stats(): array
    {
        return [
            'total' => (int) self::scalar('SELECT COUNT(*) FROM posts'),
            'published' => (int) self::scalar("SELECT COUNT(*) FROM posts WHERE status = 'published'"),
            'drafts' => (int) self::scalar("SELECT COUNT(*) FROM posts WHERE status = 'draft'"),
            'projects' => (int) self::scalar('SELECT COUNT(*) FROM projects'),
            'links' => (int) self::scalar("SELECT COUNT(*) FROM links WHERE status = 'approved'"),
        ];
    }

    /** 浏览计数 +1 */
    public static function incrementViews(int $id): void
    {
        self::exec('UPDATE posts SET views = views + 1 WHERE id = ?', [$id]);
    }

    /**
     * 保存文章（新建或更新），对齐 db_save_post。
     * $form 为表单数组，键：title/slug/content/excerpt/tags/is_featured/status/category_id/created_at。
     */
    public static function save(array $form, ?int $postId = null): int
    {
        $title = (string) ($form['title'] ?? '');
        $tags = self::jsonEncode(array_values(array_filter(
            array_map('trim', explode(',', (string) ($form['tags'] ?? ''))),
            static fn (string $t): bool => $t !== ''
        )));

        $slug = trim((string) ($form['slug'] ?? ''));
        if ($slug === '') {
            $slug = preg_replace('/[^a-zA-Z0-9\-]/', '-', mb_strtolower($title !== '' ? $title : 'untitled')) ?? '';
            $slug = mb_substr($slug, 0, 60);
        }
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
        if ($slug === '') {
            $slug = 'untitled';
        }

        $content = (string) ($form['content'] ?? '');
        // 自动链接 <https://...> 规范化为标准链接，避免富文本编辑器往返丢失
        $content = preg_replace('/<(https?:\/\/[^>\s]+)>/', '[$1]($1)', $content) ?? $content;

        $readTime = $content !== '' ? max(1, intdiv(str_word_count(strip_tags($content)), 200)) : 3;
        $isFeatured = (($form['is_featured'] ?? '') === '1') ? 1 : 0;

        $catRaw = trim((string) ($form['category_id'] ?? ''));
        $categoryId = ($catRaw !== '' && ctype_digit($catRaw)) ? (int) $catRaw : null;

        // 摘要：留空则从正文自动截取前 200 字符（去除 HTML/Markdown 标记）
        $excerpt = trim((string) ($form['excerpt'] ?? ''));
        if ($excerpt === '' && $content !== '') {
            $plain = strip_tags($content);
            $plain = preg_replace('/[#*`\[\]()!>|~-]/', '', $plain) ?? '';
            $plain = trim(preg_replace('/\s+/', ' ', $plain) ?? '');
            $excerpt = mb_substr($plain, 0, 200);
        }

        $createdAt = trim((string) ($form['created_at'] ?? ''));
        $createdAt = str_replace('T', ' ', $createdAt);
        if ($createdAt !== '' && strlen($createdAt) === 16) {
            $createdAt .= ':00';
        }
        $createdAt = $createdAt !== '' ? $createdAt : null;
        $status = (string) ($form['status'] ?? 'published');

        if ($postId !== null) {
            self::exec(
                'UPDATE posts SET title=?, slug=?, content=?, excerpt=?, tags=?, is_featured=?, read_time=?, status=?, category_id=?, created_at=COALESCE(?, created_at), updated_at=? WHERE id=?',
                [$title, $slug, $content, $excerpt, $tags, $isFeatured, $readTime, $status, $categoryId, $createdAt, self::now(), $postId]
            );
            return $postId;
        }

        // 新建：slug 冲突时追加 -2/-3... 直到唯一
        $base = $slug;
        $i = 2;
        while (self::scalar('SELECT 1 FROM posts WHERE slug = ?', [$slug]) !== null) {
            $slug = $base . '-' . $i;
            $i++;
        }
        $now = self::now();
        self::exec(
            'INSERT INTO posts (title, slug, content, excerpt, tags, is_featured, read_time, status, category_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,COALESCE(?,?),?)',
            [$title, $slug, $content, $excerpt, $tags, $isFeatured, $readTime, $status, $categoryId, $createdAt, $now, $now]
        );
        return self::insertId();
    }

    /** slug 是否已被占用 */
    public static function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return self::scalar('SELECT 1 FROM posts WHERE slug = ? AND id != ?', [$slug, $excludeId]) !== null;
        }
        return self::scalar('SELECT 1 FROM posts WHERE slug = ?', [$slug]) !== null;
    }

    /** 删除文章记录（引用的上传文件清理由调用方处理） */
    public static function delete(int $postId): void
    {
        self::exec('DELETE FROM posts WHERE id = ?', [$postId]);
    }

    /**
     * 删除文章并清理不再被引用的上传文件（对照 db_delete_post）。
     * 共享引用保护：删除后仍被其他文章引用的文件一律保留。返回实际删除的文件数。
     */
    public static function deleteWithUploads(int $postId): int
    {
        $row = self::one('SELECT title, content, excerpt, tags FROM posts WHERE id = ?', [$postId]);
        $gone = $row ? Upload::extractUrls(
            (string) $row['title'],
            (string) $row['content'],
            (string) $row['excerpt'],
            (string) $row['tags']
        ) : [];

        self::exec('DELETE FROM posts WHERE id = ?', [$postId]);
        if (!$gone) {
            return 0;
        }

        $survivors = [];
        foreach (self::rows('SELECT title, content, excerpt, tags FROM posts') as $r) {
            foreach (Upload::extractUrls(
                (string) $r['title'],
                (string) $r['content'],
                (string) $r['excerpt'],
                (string) $r['tags']
            ) as $u) {
                $survivors[$u] = true;
            }
        }

        $removed = 0;
        foreach ($gone as $u) {
            if ($u !== '' && !isset($survivors[$u]) && Upload::deleteByUrl($u)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** 批量变更状态（publish → published / unpublish → draft），返回受影响行数 */
    public static function bulkSetStatus(array $ids, string $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if (!$ids) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return self::exec("UPDATE posts SET status = ? WHERE id IN ({$ph})", array_merge([$status], $ids));
    }

    /**
     * 相邻文章（按 created_at 排序）。
     * $earlier=true 取更早发布的「上一篇」，false 取更晚的「下一篇」。
     */
    public static function adjacent(string $createdAt, bool $earlier): ?array
    {
        $sql = $earlier
            ? "SELECT id, title FROM posts WHERE created_at < ? ORDER BY created_at DESC LIMIT 1"
            : "SELECT id, title FROM posts WHERE created_at > ? ORDER BY created_at ASC LIMIT 1";
        $row = self::one($sql, [$createdAt]);
        if (!$row) {
            return null;
        }
        return ['id' => (int) $row['id'], 'title' => (string) $row['title']];
    }
}
