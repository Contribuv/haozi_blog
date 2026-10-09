<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Category;
use Blog\Model\Post;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Flash;
use Blog\Service\Markdown;

/**
 * 后台文章管理。
 * 对照原项目 app.py 的 admin_posts / admin_posts_bulk / admin_post_new /
 * admin_post_edit / admin_post_delete / admin_post_preview / admin_preview_content。
 */
class PostController extends BaseController
{
    /** GET /admin/posts：文章列表（含状态筛选、搜索、分页） */
    public function index(): void
    {
        Auth::requireAdmin();

        $statusFilter = (string) ($_GET['status'] ?? 'all');
        $search = (string) ($_GET['q'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));

        if ($statusFilter === 'all') {
            [$posts, $total] = Post::load(status: null, search: $search !== '' ? $search : null, page: $page);
        } else {
            [$posts, $total] = Post::load(status: $statusFilter, search: $search !== '' ? $search : null, page: $page);
        }

        $totalPages = max(1, (int) ceil($total / Post::PAGE_SIZE));
        $this->render('admin/posts.html', [
            'posts' => $posts,
            'status_filter' => $statusFilter,
            'search_query' => $search,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
        ]);
    }

    /** POST /admin/posts/bulk：批量发布 / 下线 / 删除 */
    public function bulk(): void
    {
        Auth::requireAdmin();

        $action = (string) Request::post('action', '');
        $ids = $_POST['post_ids'] ?? [];
        if (!in_array($action, ['publish', 'unpublish', 'hide', 'delete'], true)) {
            Flash::error('无效的批量操作');
            Response::redirect('/admin/posts');
        }
        if (!is_array($ids) || !$ids) {
            Flash::error('未选择任何文章');
            Response::redirect('/admin/posts');
        }

        if ($action === 'delete') {
            foreach ($ids as $pid) {
                Post::deleteWithUploads((int) $pid);
            }
            Flash::success('已删除 ' . count($ids) . ' 篇文章');
        } else {
            $map = ['publish' => ['published', '已发布'], 'unpublish' => ['draft', '草稿'], 'hide' => ['hidden', '隐藏']];
            [$status, $label] = $map[$action];
            Post::bulkSetStatus(array_map('intval', $ids), $status);
            Flash::success('已将 ' . count($ids) . ' 篇文章设为「' . $label . '」');
        }
        Response::redirect('/admin/posts');
    }

    /** ANY /admin/posts/new：新建文章（必须选择分类） */
    public function create(): void
    {
        Auth::requireAdmin();
        $categories = Category::load();
        $allTags = Post::allTags();

        if (Request::method() === 'POST') {
            $catRaw = trim((string) Request::post('category_id', ''));
            if (!ctype_digit($catRaw)) {
                Flash::error('请选择文章分类');
                $this->render('admin/post_edit.html', [
                    'post' => null,
                    'categories' => $categories,
                    'all_tags' => $allTags,
                ]);
                return;
            }
            Post::save($_POST);
            Flash::success('文章已创建');
            Response::redirect('/admin/posts');
        }

        $this->render('admin/post_edit.html', [
            'post' => null,
            'categories' => $categories,
            'all_tags' => $allTags,
        ]);
    }

    /** ANY /admin/posts/<post_id>/edit：编辑文章 */
    public function edit(string $postId): void
    {
        Auth::requireAdmin();
        $post = Post::getById((int) $postId);
        if ($post === null) {
            Flash::error('文章不存在');
            Response::redirect('/admin/posts');
        }

        if (Request::method() === 'POST') {
            Post::save($_POST, (int) $postId);
            Flash::success('文章已更新');
            Response::redirect('/admin/posts');
        }

        // 自动链接 <URL> 规范化为标准链接，避免 Vditor 往返丢弃
        if (!empty($post['content'])) {
            $post['content'] = (string) preg_replace('/<(https?:\/\/[^>\s]+)>/', '[$1]($1)', (string) $post['content']);
        }

        $this->render('admin/post_edit.html', [
            'post' => $post,
            'categories' => Category::load(),
            'all_tags' => Post::allTags(),
        ]);
    }

    /** POST /admin/posts/<post_id>/delete：删除文章并清理无引用上传文件 */
    public function delete(string $postId): void
    {
        Auth::requireAdmin();
        $removed = Post::deleteWithUploads((int) $postId);
        if ($removed > 0) {
            Flash::success('文章已删除，并清理 ' . $removed . ' 个不再被引用的上传文件');
        } else {
            Flash::success('文章已删除（引用的上传文件仍被其他内容使用，已保留）');
        }
        Response::redirect('/admin/posts');
    }

    /** GET /admin/posts/<post_id>/preview：渲染已存文章正文为 HTML */
    public function preview(string $postId): void
    {
        Auth::requireAdmin();
        $post = Post::getById((int) $postId);
        if ($post === null) {
            Response::json(['html' => '']);
        }
        [$html] = Markdown::renderPost((string) $post['content']);
        Response::json(['html' => $html]);
    }

    /** POST /admin/posts/preview-content：渲染编辑器实时内容为 HTML */
    public function previewContent(): void
    {
        Auth::requireAdmin();
        $content = (string) Request::post('content', '');
        [$html] = Markdown::renderPost($content);
        Response::json(['html' => $html]);
    }
}