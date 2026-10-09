<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Db;
use Blog\Model\Settings;

/**
 * 评论邮件通知（对照原项目 _send_comment_notify / _notify_reply_recipient / _notify_html）。
 *
 * 触发两处：新评论提交时通知博主（新评论/回复），以及「待审核评论被审核通过后」
 * 补发被回复者通知（避免对方点进链接却看不到待审核回复）。
 * PHP 无后台线程，改为注册到请求结束后的 shutdown 回调执行，不阻塞提交响应；失败静默。
 */
class Notify
{
    /** 评论邮件通知开关 */
    public static function on(): bool
    {
        return Settings::bool('comment_notify', false);
    }

    /**
     * 延后到本次响应结束后执行（尽力不阻塞主流程）。
     * FPM 下先结束请求再发信；内置服务器/CLI 下于脚本收尾时执行。
     */
    public static function defer(callable $fn): void
    {
        if (!headers_sent()) {
            @ignore_user_abort(true);
        }
        register_shutdown_function(static function () use ($fn): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @set_time_limit(0);
            try {
                $fn();
            } catch (\Throwable $e) {
                error_log('[评论通知] 邮件发送失败：' . $e->getMessage());
            }
        });
    }

    /**
     * 新评论提交通知：通知博主；若评论已通过（博主免审核）同时通知被回复者。
     * $detailPath 为详情页路径（/post/<id>#comments 或 /projects/<id>#comments）。
     */
    public static function commentCreated(
        ?int $postId,
        ?int $projectId,
        string $title,
        string $baseUrl,
        string $author,
        string $content,
        ?int $parentId,
        string $commenterEmail,
        string $status,
        string $detailPath,
        string $label
    ): void {
        try {
            $postUrl = rtrim($baseUrl, '/') . ($detailPath !== '' ? $detailPath : '/post/' . (int) $postId . '#comments');
            [$blogger, $bloggerEmails] = self::bloggerEmails();
            $reply = $parentId !== null && $parentId > 0;
            $commenterLower = strtolower(trim($commenterEmail));

            // 1) 通知博主（博主自己评论/回复时不打扰，仅访客互动才通知）
            if ($blogger !== '' && ($commenterLower === '' || !in_array($commenterLower, $bloggerEmails, true))) {
                $kind = $reply ? '回复通知' : '新评论通知';
                Mail::send(
                    $blogger,
                    '[' . self::blogName() . '] ' . $kind . $label . '《' . $title . '》',
                    self::html($kind, $title, $postUrl, $author, $content, $label)
                );
            }

            // 2) 通知被回复者（仅已通过评论；待审核的由审核通过后补发）
            if ($reply && $status === 'approved') {
                $row = Db::query('SELECT author, email FROM comments WHERE id = ?', [$parentId])->fetch();
                if ($row && !empty($row['email'])) {
                    $pe = strtolower((string) $row['email']);
                    if (!in_array($pe, $bloggerEmails, true) && $pe !== $commenterLower) {
                        Mail::send(
                            (string) $row['email'],
                            '[' . self::blogName() . '] 你的评论收到新回复' . $label . '《' . $title . '》',
                            self::html('你的评论收到一条新回复', $title, $postUrl, $author, $content, $label)
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[评论通知] ' . $e->getMessage());
        }
    }

    /**
     * 审核通过后补发被回复者通知（若其评论填过邮箱且不是博主本人、也不是评论者自己）。
     * 同时兼容文章评论与项目详情页评论。
     */
    public static function replyRecipient(int $commentId, string $baseUrl): void
    {
        try {
            $row = Db::query(
                'SELECT c.post_id, c.project_id, c.author, c.content, c.parent_id, c.email AS commenter_email, '
                . 'p.title AS post_title, pj.name AS project_name '
                . 'FROM comments c LEFT JOIN posts p ON p.id = c.post_id '
                . 'LEFT JOIN projects pj ON pj.id = c.project_id WHERE c.id = ?',
                [$commentId]
            )->fetch();
            if (!$row || empty($row['parent_id'])) {
                return;
            }
            $parent = Db::query('SELECT author, email FROM comments WHERE id = ?', [(int) $row['parent_id']])->fetch();
            if (!$parent || empty($parent['email'])) {
                return;
            }
            [, $bloggerEmails] = self::bloggerEmails();
            $pe = strtolower((string) $parent['email']);
            if (in_array($pe, $bloggerEmails, true)) {
                return;
            }
            if ($pe === strtolower((string) ($row['commenter_email'] ?? ''))) {
                return;
            }
            if (!empty($row['project_id'])) {
                $label = '项目';
                $title = (string) ($row['project_name'] ?? '') !== '' ? (string) $row['project_name'] : '项目';
                $detailPath = '/projects/' . (int) $row['project_id'] . '#comments';
            } else {
                $label = '文章';
                $title = (string) ($row['post_title'] ?? '');
                $detailPath = '/post/' . (int) $row['post_id'] . '#comments';
            }
            $postUrl = rtrim($baseUrl, '/') . $detailPath;
            Mail::send(
                (string) $parent['email'],
                '[' . self::blogName() . '] 你的评论收到新回复' . $label . '《' . $title . '》',
                self::html('你的评论收到一条新回复', $title, $postUrl, (string) $row['author'], (string) $row['content'], $label)
            );
        } catch (\Throwable $e) {
            error_log('[评论通知] ' . $e->getMessage());
        }
    }

    /** 通知邮件 HTML（内嵌样式，兼容主流邮箱客户端） */
    public static function html(string $head, string $postTitle, string $postUrl, string $author, string $content, string $label = '文章'): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $blogName = self::blogName();
        $av = $author !== '' ? mb_strtoupper(mb_substr($author, 0, 1)) : '匿';
        return '<!doctype html><html><head><meta charset="utf-8"><style>' . Mail::NOTIFY_CSS . '</style></head><body>'
            . '<div class="wrap">'
            . '<div class="brand">' . $e($blogName) . '</div>'
            . '<div class="card">'
            . '<div class="head">' . $e($head) . '<span class="head-sub">' . $e($label) . '《' . $e($postTitle) . '》</span></div>'
            . '<div class="body">'
            . '<div class="cta-wrap"><a class="cta" href="' . $e($postUrl) . '">查看详情</a></div>'
            . '<div class="quote">'
            . '<div class="q-author"><span class="q-avatar">' . $e($av) . '</span>' . $e($author !== '' ? $author : '匿名') . '</div>'
            . '<div class="q-content">' . $e($content) . '</div>'
            . '</div></div></div>'
            . '<div class="foot">本邮件由 ' . $e($blogName) . ' 自动发送，请勿直接回复。</div>'
            . '</div></body></html>';
    }

    /** 站点名（空则回退 Blog） */
    private static function blogName(): string
    {
        $n = trim((string) Settings::get('blog_name', ''));
        return $n !== '' ? $n : 'Blog';
    }

    /**
     * 博主身份邮箱：notify_email 优先，空则 contact_email；返回 [主收件邮箱, 小写邮箱集合]。
     */
    private static function bloggerEmails(): array
    {
        $notify = trim((string) Settings::get('notify_email', ''));
        $contact = trim((string) Settings::get('contact_email', ''));
        $blogger = $notify !== '' ? $notify : $contact;
        $set = [];
        foreach ([$notify, $contact] as $x) {
            if ($x !== '') {
                $set[] = strtolower($x);
            }
        }
        return [$blogger, $set];
    }
}