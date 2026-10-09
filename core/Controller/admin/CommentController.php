<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Comment;
use Blog\Model\Settings;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Avatar;
use Blog\Service\Flash;
use Blog\Service\Notify;

/**
 * 后台评论管理。
 * 对照原项目 admin_comments / admin_comment_approve / admin_comment_delete /
 * admin_comments_bulk。
 */
class CommentController extends BaseController
{
    /** GET /admin/comments：评论列表（含后台头像） */
    public function index(): void
    {
        Auth::requireAdmin();
        $comments = Comment::adminList();

        $authorName = (string) Settings::get('author', '');
        $cfgAvatar = trim((string) Settings::get('avatar', ''));
        $cfgAvatarOk = Avatar::configuredAvatarExists();
        $defaultUrl = Avatar::defaultUrl();

        foreach ($comments as &$c) {
            $isAuthor = $authorName !== '' && (string) ($c['author'] ?? '') === $authorName;
            if ($isAuthor && $cfgAvatar !== '' && $cfgAvatarOk) {
                // 博主评论优先用后台「设置」上传的头像
                $c['admin_avatar'] = $cfgAvatar;
            } else {
                [$c['admin_avatar']] = Avatar::urls((string) ($c['email_hash'] ?? ''), (string) ($c['qq'] ?? ''));
            }
            // 无任何头像来源（无邮箱无 QQ）→ 直接给博客默认头像
            if ($c['admin_avatar'] === '') {
                $c['admin_avatar'] = $defaultUrl;
            }
            $c['admin_avatar_default'] = $defaultUrl;
        }
        unset($c);

        $this->render('admin/comments.html', ['comments' => $comments]);
    }

    /** POST /admin/comments/<comment_id>/approve：单条通过 */
    public function approve(string $commentId): void
    {
        Auth::requireAdmin();
        Comment::setStatus((int) $commentId, 'approved');
        // 审核通过后补发被回复者通知（若适用），延后到响应结束后发送不阻塞
        if (Notify::on()) {
            $cid = (int) $commentId;
            $baseUrl = Request::baseUrl();
            Notify::defer(static function () use ($cid, $baseUrl): void {
                Notify::replyRecipient($cid, $baseUrl);
            });
        }
        Flash::success('评论已通过');
        Response::redirect('/admin/comments');
    }

    /** POST /admin/comments/<comment_id>/delete：删除评论及其回复 */
    public function delete(string $commentId): void
    {
        Auth::requireAdmin();
        Comment::delete((int) $commentId);
        Flash::success('评论及其回复已删除');
        Response::redirect('/admin/comments');
    }

    /** POST /admin/comments/bulk：approve 批量通过 / delete 批量删除 */
    public function bulk(): void
    {
        Auth::requireAdmin();
        $action = (string) Request::post('action', '');
        $ids = $_POST['comment_ids'] ?? [];
        if (!in_array($action, ['approve', 'delete'], true)) {
            Flash::error('无效的批量操作');
            Response::redirect('/admin/comments');
        }
        if (!is_array($ids) || !$ids) {
            Flash::error('未选择任何评论');
            Response::redirect('/admin/comments');
        }
        if ($action === 'approve') {
            Comment::bulkApprove($ids);
            // 批量通过后逐条补发被回复者通知（延后执行）
            if (Notify::on()) {
                $cidList = array_map('intval', $ids);
                $baseUrl = Request::baseUrl();
                Notify::defer(static function () use ($cidList, $baseUrl): void {
                    foreach ($cidList as $cid) {
                        Notify::replyRecipient($cid, $baseUrl);
                    }
                });
            }
            Flash::success('已通过 ' . count($ids) . ' 条评论');
        } else {
            foreach ($ids as $cid) {
                Comment::delete((int) $cid);
            }
            Flash::success('已删除 ' . count($ids) . ' 条评论及其回复');
        }
        Response::redirect('/admin/comments');
    }
}