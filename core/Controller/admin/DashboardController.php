<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Post;
use Blog\Service\Auth;

/**
 * 后台仪表盘：统计概览 + 最近更新文章。
 * 对照原项目 app.py 的 admin_dashboard。
 */
class DashboardController extends BaseController
{
    /** GET /admin/dashboard */
    public function index(): void
    {
        Auth::requireAdmin();

        // 统计：文章总数 / 已发布 / 草稿 / 项目数 / 友链数
        $stats = Post::stats();

        // 最近更新：取全部文章按 updated_at 倒序前 5 篇
        [$posts] = Post::load(status: null, perPage: 1000);
        usort($posts, static fn (array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        $recent = array_slice($posts, 0, 5);

        // 钩子位 admin.dashboard：插件可改写统计与最近文章
        $payload = \Blog\Hook::emit('admin.dashboard', ['stats' => $stats, 'recent' => $recent]);
        if (is_array($payload)) {
            $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : $stats;
            $recent = is_array($payload['recent'] ?? null) ? $payload['recent'] : $recent;
        }

        $this->render('admin/dashboard.html', [
            'stats' => $stats,
            'recent' => $recent,
        ]);
    }
}