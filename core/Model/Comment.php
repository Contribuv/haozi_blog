<?php
declare(strict_types=1);

namespace Blog\Model;

use Blog\Service\Avatar;

/**
 * 评论数据模型（关联文章或项目），对照 db_load_comments / db_save_comment / db_delete_comment。
 * 一次查询内存组装为树，避免 N+1；普通访客仅见已通过且非私密评论（外加本人待审核评论）。
 */
class Comment extends Model
{
    /**
     * 加载评论并组装为树。返回 [根评论列表, 评论总数]。
     * $myComments 为 {评论id: 昵称} 映射，用于回显本人待审核评论并防伪造。
     */
    public static function load(
        ?int $postId = null,
        ?int $projectId = null,
        bool $includePrivate = false,
        array $myComments = []
    ): array {
        $col = $projectId !== null ? 'project_id' : 'post_id';
        $val = $projectId !== null ? $projectId : $postId;

        if ($includePrivate) {
            $sql = "SELECT * FROM comments WHERE {$col} = ? AND (status = 'approved' OR is_private = 1)";
            $params = [$val];
        } else {
            $sql = "SELECT * FROM comments WHERE {$col} = ? AND (status = 'approved' AND is_private = 0)";
            $params = [$val];
            if ($myComments) {
                $ids = [];
                foreach (array_keys($myComments) as $k) {
                    if (ctype_digit((string) $k)) {
                        $ids[] = (int) $k;
                    }
                }
                if ($ids) {
                    // OR 分支必须圈定当前文章/项目：id 为全局自增，否则其他页待审核评论会串台；
                    // 同时排除 rejected，被拒评论不应再回显
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $sql .= " OR (id IN ({$ph}) AND {$col} = ? AND status != 'rejected')";
                    $params = array_merge($params, $ids, [$val]);
                }
            }
        }
        $sql .= ' ORDER BY created_at ASC, id ASC';
        $rows = self::rows($sql, $params);

        // 本人待审核评论：昵称须与 cookie 记录一致，防止伪造 id 偷看他人待审核评论
        if ($myComments && !$includePrivate) {
            $rows = array_values(array_filter($rows, static function (array $c) use ($myComments): bool {
                return $c['status'] === 'approved'
                    || (isset($myComments[(string) $c['id']]) && $myComments[(string) $c['id']] === $c['author']);
            }));
        }

        $authorName = (string) Settings::get('author', '');
        $contact = strtolower(trim((string) Settings::get('contact_email', '')));
        $contactHash = Avatar::emailHash($contact);
        $contactQq = Avatar::qqFromEmail($contact);
        $avatarDefault = Avatar::defaultUrl();
        $cfgAvatar = trim((string) Settings::get('avatar', ''));
        $cfgAvatarOk = Avatar::configuredAvatarExists();

        $byId = [];
        foreach ($rows as $c) {
            $byId[(int) $c['id']] = $c;
        }

        $comments = [];
        foreach ($rows as $c) {
            $c['id'] = (int) $c['id'];
            $c['parent_id'] = isset($c['parent_id']) && $c['parent_id'] !== null ? (int) $c['parent_id'] : null;
            $c['is_private'] = (int) ($c['is_private'] ?? 0);
            $c['children'] = [];
            $c['is_author'] = $authorName !== '' && $c['author'] === $authorName;

            if ($c['is_author']) {
                // 博主头像优先用后台「设置」上传的头像，缺失时回退联系邮箱生成的 Cravatar
                if ($cfgAvatar !== '' && $cfgAvatarOk) {
                    $c['avatar'] = $cfgAvatar;
                    $c['avatar_fallback'] = '';
                } else {
                    [$c['avatar'], $c['avatar_fallback']] = Avatar::urls($contactHash, $contactQq);
                }
            } else {
                [$c['avatar'], $c['avatar_fallback']] = Avatar::urls((string) ($c['email_hash'] ?? ''), (string) ($c['qq'] ?? ''));
            }
            if ($c['avatar'] === '') {
                $c['avatar'] = $avatarDefault;
                $c['avatar_fallback'] = '';
            }
            $c['avatar_default'] = $avatarDefault;
            // 明文邮箱仅用于回复通知，不出现在渲染上下文
            unset($c['email']);
            $comments[] = $c;
        }

        // 组装父子关系。
        // 注意：PHP 数组是值类型，不能用 $byId[$pid]['children'][] = $c 这种写法——
        // 那样 $roots 与 $byId 里是两份互不影响的数据，子回复会全部丢失。
        // 这里统一用「数组元素引用」对齐原 Python dict 的引用语义：$roots 与各节点的 children
        // 都指向 $comments 中同一份数据，才能反映真实树结构。
        $idxById = [];
        foreach (array_keys($comments) as $i) {
            $idxById[$comments[$i]['id']] = $i;
        }
        $roots = [];
        foreach (array_keys($comments) as $i) {
            $pid = $comments[$i]['parent_id'];
            if ($pid !== null && $pid > 0 && isset($idxById[$pid])) {
                $pi = $idxById[$pid];
                $comments[$i]['parent_author'] = $comments[$pi]['author'];
                $comments[$pi]['children'][] = &$comments[$i];
            } else {
                $comments[$i]['parent_author'] = '';
                $roots[] = &$comments[$i];
            }
        }
        unset($i);

        // 递归标注层级
        $setDepth = function (array &$node, int $d) use (&$setDepth): void {
            $node['depth'] = $d;
            foreach ($node['children'] as &$ch) {
                $setDepth($ch, $d + 1);
            }
            unset($ch);
        };
        foreach ($roots as &$r) {
            $setDepth($r, 0);
        }
        unset($r);

        // 钩子位 comment.beforeShow：插件可在评论展示前过滤/改写（如敏感词拦截）
        $payload = \Blog\Hook::emit('comment.beforeShow', [
            'comments' => $roots,
            'post_id' => $postId,
            'project_id' => $projectId,
            'total' => count($comments),
        ]);
        if (is_array($payload) && isset($payload['comments']) && is_array($payload['comments'])) {
            $roots = $payload['comments'];
        }

        return [$roots, count($comments)];
    }

    /** 递归统计树中待审核条数（用于"你的评论正在审核中"提示） */
    public static function countPending(array $nodes): int
    {
        $n = 0;
        foreach ($nodes as $c) {
            if (($c['status'] ?? '') === 'pending') {
                $n++;
            }
            $n += self::countPending($c['children'] ?? []);
        }
        return $n;
    }

    /** 新增评论，返回新评论 id。post_id / project_id 二选一。 */
    public static function save(
        ?int $postId,
        string $author,
        string $email,
        string $emailHash,
        string $website,
        string $content,
        ?int $parentId = null,
        bool $isPrivate = false,
        string $qq = '',
        string $ipText = '',
        string $status = 'pending',
        ?int $projectId = null,
        string $ipLocation = ''
    ): int {
        self::exec(
            'INSERT INTO comments (post_id, project_id, parent_id, author, email, email_hash, website, content, status, is_private, qq, ip_text, ip_location, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$postId, $projectId, $parentId, $author, $email, $emailHash, $website, $content, $status, $isPrivate ? 1 : 0, $qq, $ipText, $ipLocation, self::now()]
        );
        return self::insertId();
    }

    /** 删除评论及其全部子回复（整棵子树） */
    public static function delete(int $commentId): void
    {
        $ids = [$commentId];
        $frontier = [$commentId];
        while ($frontier) {
            $ph = implode(',', array_fill(0, count($frontier), '?'));
            $rows = self::rows("SELECT id FROM comments WHERE parent_id IN ({$ph})", $frontier);
            $frontier = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $ids = array_merge($ids, $frontier);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        self::exec("DELETE FROM comments WHERE id IN ({$ph})", $ids);
    }

    /** 变更评论状态（approve / reject / pending） */
    public static function setStatus(int $id, string $status): void
    {
        self::exec('UPDATE comments SET status = ? WHERE id = ?', [$status, $id]);
    }

    /**
     * 后台评论列表（扁平，含文章/项目标题与父评论作者）。
     * 对照原项目 admin_comments 的查询：待审核置顶，其次私密，再按时间倒序。
     */
    public static function adminList(): array
    {
        $rows = self::rows(
            "SELECT c.*, p.title AS post_title, p.id AS post_id, pj.name AS project_name, pa.author AS parent_author "
            . "FROM comments c "
            . "LEFT JOIN posts p ON c.post_id = p.id "
            . "LEFT JOIN projects pj ON c.project_id = pj.id "
            . "LEFT JOIN comments pa ON c.parent_id = pa.id "
            . "ORDER BY (c.status = 'pending') DESC, c.is_private DESC, c.created_at DESC"
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['post_id'] = isset($r['post_id']) && $r['post_id'] !== null ? (int) $r['post_id'] : null;
            $r['project_id'] = isset($r['project_id']) && $r['project_id'] !== null ? (int) $r['project_id'] : null;
            $r['is_private'] = (int) ($r['is_private'] ?? 0);
            if (!isset($r['parent_author']) || $r['parent_author'] === null) {
                $r['parent_author'] = '';
            }
        }
        unset($r);
        return $rows;
    }

    /** 批量通过评论 */
    public static function bulkApprove(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if (!$ids) {
            return;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        self::exec("UPDATE comments SET status = 'approved' WHERE id IN ({$ph})", $ids);
    }

    /** 待审核评论数 */
    public static function pendingCount(): int
    {
        return (int) self::scalar("SELECT COUNT(*) FROM comments WHERE status = 'pending'");
    }
}
