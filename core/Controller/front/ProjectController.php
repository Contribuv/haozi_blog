<?php
declare(strict_types=1);

namespace Blog\Controller\front;

use Blog\Controller\BaseController;
use Blog\Model\Comment;
use Blog\Model\Project;
use Blog\Model\Settings;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Avatar;
use Blog\Service\Comments;
use Blog\Service\Context;
use Blog\Service\Github;
use Blog\Service\Lang;
use Blog\Service\ProjectCache;
use Blog\Service\ProjectSync;

/**
 * 前台项目控制器（对照原项目 projects_page / project_detail / project_comment）。
 */
class ProjectController extends BaseController
{
    /** 项目列表 */
    public function index(): void
    {
        if (!Context::navEnabled('projects')) {
            $this->abort404();
        }
        // 快照过期即排队自动同步（不阻塞本次请求，同步由后台轮询自驱动推进）
        ProjectSync::autoSync();
        $projects = array_map(
            static fn (array $p): array => Lang::decorate($p),
            Project::load()
        );
        $this->render('projects.html', [
            'projects' => $projects,
            'languages' => Lang::tally($projects),
        ]);
    }

    /** 项目详情 */
    public function detail(string $projectId): void
    {
        if (!Context::navEnabled('projects')) {
            $this->abort404();
        }
        $project = Project::get((int) $projectId);
        if (!$project) {
            $this->abort404();
        }
        $project = Lang::decorate($project);

        $slug = trim((string) ($project['github_repo'] ?? ''));
        if ($slug === '') {
            $slug = (string) (Github::parseRepo((string) ($project['url'] ?? '')) ?? '');
        }
        $cached = $slug !== '' ? ProjectCache::snapshot($slug) : null;
        if ($cached !== null) {
            $gh = $cached;
            $gh['cached'] = true;
        } else {
            $gh = ['gh' => null, 'readme' => null, 'branch' => '', 'ts' => 0, 'error' => '', 'cached' => false];
        }
        $gh['age_text'] = self::ageText((float) ($gh['ts'] ?? 0));

        $commentsEnabled = Comments::open();
        $isAdmin = Auth::isAdmin();
        $commenter = Comments::commenter();
        $myComments = isset($commenter['my_comments']) && is_array($commenter['my_comments']) ? $commenter['my_comments'] : [];
        if ($commentsEnabled) {
            [$comments, $commentTotal] = Comment::load(null, (int) $project['id'], $isAdmin, $myComments);
        } else {
            [$comments, $commentTotal] = [[], 0];
        }
        $myPending = $isAdmin ? 0 : Comment::countPending($comments);

        $this->render('project_detail.html', [
            'project' => $project,
            'gh' => $gh,
            'comments' => $comments,
            'comment_total' => $commentTotal,
            'comments_enabled' => $commentsEnabled,
            'commenter' => $commenter,
            'is_admin' => $isAdmin,
            'admin_avatar' => self::adminAvatar($isAdmin),
            'my_pending' => $myPending,
        ]);
    }

    /** 提交项目评论（POST） */
    public function comment(string $projectId): void
    {
        if (!Comments::open()) {
            http_response_code(403);
            exit;
        }
        $project = Project::get((int) $projectId);
        if (!$project) {
            $this->abort404();
        }
        Response::redirect(Comments::submit(null, $project));
    }

    /** 「更新于 X 前」文案 */
    public static function ageText(float $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        $diff = max(0, time() - $ts);
        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return (int) ($diff / 60) . ' 分钟前';
        }
        if ($diff < 86400) {
            return (int) ($diff / 3600) . ' 小时前';
        }
        if ($diff < 86400 * 30) {
            return (int) ($diff / 86400) . ' 天前';
        }
        if ($diff < 86400 * 365) {
            return (int) ($diff / (86400 * 30)) . ' 个月前';
        }
        return (int) ($diff / (86400 * 365)) . ' 年前';
    }

    /** 博主身份条头像 */
    public static function adminAvatar(bool $isAdmin): string
    {
        if (!$isAdmin) {
            return '';
        }
        $contact = strtolower(trim((string) Settings::get('contact_email', '')));
        if ($contact === '') {
            return '';
        }
        $qq = Avatar::qqFromEmail($contact);
        if ($qq !== '') {
            return '/avatar/q' . $qq . '/40.png';
        }
        return Avatar::urls(Avatar::emailHash($contact), '', 40)[0];
    }
}
