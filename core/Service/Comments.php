<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Comment;
use Blog\Model\Settings;
use Blog\Request;

/**
 * 评论提交公共逻辑（对照原项目 _handle_new_comment，文章 / 项目共用）。
 */
class Comments
{
    /** 同 IP 提交冷却窗口（秒） */
    private const COOLDOWN = 30;
    /** 浏览计数冷却窗口（秒） */
    private const VIEW_COOLDOWN = 60;

    /** 评论总开关 */
    public static function open(): bool
    {
        return Settings::bool('comments_enabled', true);
    }

    /**
     * 提交评论。$post / $project 二选一。
     * 返回回跳 URL（带 #comments）。
     */
    public static function submit(?array $post = null, ?array $project = null): string
    {
        $isProject = $project !== null;
        $targetId = $isProject ? (int) $project['id'] : (int) $post['id'];
        $back = $isProject ? '/projects/' . $targetId : '/post/' . $targetId;

        $ip = Request::ip();
        if (!self::checkCooldown('comment', $ip)) {
            Flash::error('评论太频繁，请稍后再试');
            return $back;
        }

        $isAdmin = Auth::isAdmin();
        $author = trim((string) Request::post('author', ''));
        $content = trim((string) Request::post('content', ''));
        $email = trim((string) Request::post('email', ''));
        $website = trim((string) Request::post('website', ''));
        if ($isAdmin) {
            $author = (string) Settings::get('author', '') ?: '博主';
            $email = (string) Settings::get('contact_email', '');
            $website = '';
        }
        $parentId = trim((string) Request::post('parent_id', ''));
        $isPrivate = in_array(strtolower(trim((string) Request::post('is_private', ''))), ['1', 'on', 'true', 'yes'], true);

        if ($author === '' || mb_strlen($author) > 30) {
            Flash::error('请填写昵称（30 字以内）');
            return $back;
        }
        $bloggerName = trim((string) Settings::get('author', ''));
        if (!$isAdmin && $bloggerName !== '' && $author === $bloggerName) {
            Flash::error('该昵称已被占用，请换一个');
            return $back;
        }
        if ($content === '' || mb_strlen($content) > 2000) {
            Flash::error('评论内容不能为空且不能超过2000字');
            return $back;
        }

        $emailHash = '';
        $qq = '';
        if ($email !== '') {
            if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
                Flash::error('邮箱格式不正确（选填，仅用于头像）');
                return $back;
            }
            $emailHash = Avatar::emailHash($email);
            $qq = Avatar::qqFromEmail($email);
        }
        if ($website !== '') {
            if (!preg_match('#^https?://\S+$#', $website) || mb_strlen($website) > 200) {
                Flash::error('网址格式不正确（需以 http:// 或 https:// 开头）');
                return $back;
            }
        }

        // 父级校验：必须属于同一载体
        $pid = null;
        if ($parentId !== '') {
            $cand = ctype_digit($parentId) ? (int) $parentId : 0;
            if ($cand > 0) {
                $col = $isProject ? 'project_id' : 'post_id';
                $row = \Blog\Db::query("SELECT id FROM comments WHERE id = ? AND {$col} = ?", [$cand, $targetId])->fetch();
                if ($row) {
                    $pid = $cand;
                }
            }
        }

        $adminNotify = trim((string) Settings::get('notify_email', ''));
        $status = ($email !== '' && $adminNotify !== '' && strcasecmp($email, $adminNotify) === 0) ? 'approved' : 'pending';

        $cid = Comment::save(
            $isProject ? null : $targetId,
            $author,
            $email,
            $emailHash,
            $website,
            $content,
            $pid,
            $isPrivate,
            $qq,
            $ip,
            $status,
            $isProject ? $targetId : null,
            // 离线归属地查询约 0.04ms，无需像原项目那样另起线程回填
            IpLocation::of($ip)
        );
        self::touchCooldown('comment', $ip);
        // 钩子位 comment.create：插件可在评论落库后做通知/统计等副作用
        \Blog\Hook::emit('comment.create', [
            'id' => (int) $cid,
            'post_id' => $isProject ? null : $targetId,
            'project_id' => $isProject ? $targetId : null,
            'parent_id' => $pid,
            'author' => $author,
            'email' => $email,
            'content' => $content,
            'status' => $status,
            'is_private' => $isPrivate,
        ]);

        // 评论邮件通知：开启时延后到响应结束后发送（通知博主 + 被回复者），不阻塞提交、失败静默
        if (Notify::on()) {
            $title = $isProject ? (string) ($project['name'] ?? '') : (string) ($post['title'] ?? '');
            $label = $isProject ? '项目' : '文章';
            $detailPath = ($isProject ? '/projects/' : '/post/') . $targetId . '#comments';
            $baseUrl = Request::baseUrl();
            Notify::defer(static function () use ($isProject, $targetId, $title, $label, $detailPath, $baseUrl, $author, $content, $pid, $email, $status): void {
                Notify::commentCreated(
                    $isProject ? null : $targetId,
                    $isProject ? $targetId : null,
                    $title,
                    $baseUrl,
                    $author,
                    $content,
                    $pid,
                    $email,
                    $status,
                    $detailPath,
                    $label
                );
            });
        }

        // 记住评论者信息
        $data = ['author' => $author, 'email' => $email, 'website' => $website];
        $myComments = [];
        $raw = $_COOKIE['blog_commenter'] ?? '';
        if ($raw !== '') {
            $old = json_decode((string) $raw, true);
            if (is_array($old)) {
                foreach (['author', 'email', 'website'] as $k) {
                    if (!empty($old[$k])) {
                        $data[$k] = $old[$k];
                    }
                }
                if (isset($old['my_comments']) && is_array($old['my_comments'])) {
                    $myComments = $old['my_comments'];
                }
            }
        }
        if (!$isPrivate) {
            $myComments[(string) $cid] = $author;
            if (count($myComments) > 20) {
                $myComments = array_slice($myComments, -20, null, true);
            }
        }
        $data['my_comments'] = $myComments;
        if (!headers_sent()) {
            setcookie('blog_commenter', json_encode($data, JSON_UNESCAPED_UNICODE), [
                'expires' => time() + 30 * 24 * 3600,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        if ($isPrivate) {
            Flash::success('私密评论已提交，仅管理员可见');
        } elseif ($status === 'approved') {
            Flash::success('博主评论已直接显示');
        } else {
            Flash::success('评论已提交，审核通过后显示');
        }
        return $back . '#comments';
    }

    /** 读取记住的评论者信息 */
    public static function commenter(): array
    {
        $raw = $_COOKIE['blog_commenter'] ?? '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** 浏览计数（带 IP 冷却，返回是否已计数） */
    public static function countView(int $postId): bool
    {
        $ip = Request::ip();
        if (!self::checkCooldown('view', $ip . ':' . $postId, self::VIEW_COOLDOWN)) {
            return false;
        }
        self::touchCooldown('view', $ip . ':' . $postId);
        \Blog\Model\Post::incrementViews($postId);
        return true;
    }

    /** 冷却检查（文件级简单实现，避免依赖扩展） */
    private static function checkCooldown(string $bucket, string $key, int $window = self::COOLDOWN): bool
    {
        $data = self::cooldownRead($bucket);
        $last = (float) ($data[$key] ?? 0);
        return (microtime(true) - $last) >= $window;
    }

    private static function touchCooldown(string $bucket, string $key): void
    {
        $data = self::cooldownRead($bucket);
        $now = microtime(true);
        foreach ($data as $k => $t) {
            if ($now - (float) $t > 3600) {
                unset($data[$k]);
            }
        }
        $data[$key] = $now;
        self::cooldownWrite($bucket, $data);
    }

    private static function cooldownFile(string $bucket): string
    {
        $dir = STORAGE_PATH . '/cache/cooldown';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/' . $bucket . '.json';
    }

    private static function cooldownRead(string $bucket): array
    {
        $f = self::cooldownFile($bucket);
        if (!is_file($f)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    private static function cooldownWrite(string $bucket, array $data): void
    {
        file_put_contents(self::cooldownFile($bucket), json_encode($data), LOCK_EX);
    }
}
