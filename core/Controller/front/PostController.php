<?php
declare(strict_types=1);

namespace Blog\Controller\front;

use Blog\Controller\BaseController;
use Blog\Model\Category;
use Blog\Model\Comment;
use Blog\Model\Post;
use Blog\Model\Settings;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Avatar;
use Blog\Service\Comments;
use Blog\Service\Context;
use Blog\Service\Markdown;
use Blog\Service\Upload;

/**
 * 前台文章控制器（对照原项目 index / posts_page / post_detail / post_view /
 * post_comment / tags / search）。
 */
class PostController extends BaseController
{
    /** 首页（含 tag / q 筛选模式） */
    public function index(): void
    {
        $tag = trim((string) ($_GET['tag'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        $homeCount = (int) (Settings::get('home_posts_count', '6') ?: 6);

        if ($tag !== '' || $search !== '') {
            [$posts, $total] = Post::load('published', $tag !== '' ? $tag : null, $search !== '' ? $search : null, null, 1, Post::PAGE_SIZE);
            $featured = [];
        } else {
            $featured = Post::featured(3);
            $posts = Post::homePosts($homeCount, true);
            [, $total] = Post::load('published', null, null, null, 1);
        }

        $catNames = Category::nameMap();
        $posts = $this->decorate($posts, $catNames);
        $featured = $this->decorate($featured, $catNames);

        $homeLinks = ($tag === '' && $search === '') ? \Blog\Model\Link::load('approved') : [];

        $this->render('index.html', [
            'posts' => $posts,
            'featured' => $featured,
            'links' => $homeLinks,
            'current_tag' => $tag,
            'search_query' => $search,
            'page' => 1,
            'total_pages' => 1,
            'total' => $total,
            'total_categories' => count($catNames),
        ]);
    }

    /** 文章列表 / 归档 */
    public function posts(): void
    {
        if (!Context::navEnabled('posts')) {
            $this->abort404();
        }
        $tag = trim((string) ($_GET['tag'] ?? '')) ?: null;
        $year = trim((string) ($_GET['year'] ?? '')) ?: null;
        $perPage = (int) (Settings::get('posts_per_page', '20') ?: 20);
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $catNames = Category::nameMap();
        $allTags = Post::allTags();
        $allYears = Post::allYears();

        [, $total] = Post::load('published', $tag, null, $year, 1, $perPage);
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min($page, $totalPages);
        [$posts] = Post::load('published', $tag, null, $year, $page, $perPage);
        $posts = $this->decorate($posts, $catNames);

        $this->render('posts.html', [
            'posts' => $posts,
            'groups' => null,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filtered' => ($tag !== null || $year !== null),
            'all_tags' => $allTags,
            'all_years' => $allYears,
            'active_tag' => $tag ?? '',
            'active_year' => $year ?? '',
        ]);
    }

    /** 文章详情 */
    public function detail(string $postId): void
    {
        $id = (int) $postId;
        $post = Post::getById($id);
        if (!$post || $post['status'] !== 'published') {
            $this->abort404();
        }
        $cat = $post['category_id'] !== null ? Category::get((int) $post['category_id']) : null;
        $post['category_name'] = $cat ? (string) $cat['name'] : '';
        $post['word_count'] = mb_strlen((string) preg_replace('/\s+/', '', (string) $post['content']));

        [$content, $toc] = Markdown::renderPost((string) $post['content']);

        // 分享卡片图：封面 > 正文首图（4:3 缩略图）> 默认站图
        $share = trim((string) ($post['cover'] ?? ''));
        if ($share === '') {
            $share = Upload::firstUrl((string) $post['content']);
        }
        if (str_starts_with($share, '/uploads/')) {
            $ogPath = '/share-thumb/' . rawurlencode(substr($share, strlen('/uploads/')));
        } elseif ($share !== '') {
            $ogPath = $share;
        } else {
            $ogPath = '/static/images/og-image.png';
        }

        $related = Post::related($id, $post['tags']);
        $commentsEnabled = Comments::open();
        $isAdmin = Auth::isAdmin();
        $commenter = Comments::commenter();
        $myComments = isset($commenter['my_comments']) && is_array($commenter['my_comments']) ? $commenter['my_comments'] : [];

        if ($commentsEnabled) {
            [$comments, $commentTotal] = Comment::load($id, null, $isAdmin, $myComments);
        } else {
            [$comments, $commentTotal] = [[], 0];
        }
        $myPending = $isAdmin ? 0 : Comment::countPending($comments);

        // 上一篇（更早）/ 下一篇（更晚）
        $prev = Post::adjacent($post['created_at'], true);
        $next = Post::adjacent($post['created_at'], false);

        [$sidePosts] = Post::load('published', null, null, null, 1, 12);

        $this->render('post.html', [
            'post' => $post,
            'content' => $content,
            'toc' => $toc,
            'related' => $related,
            'comments' => $comments,
            'comment_total' => $commentTotal,
            'prev_post' => $prev,
            'next_post' => $next,
            'comments_enabled' => $commentsEnabled,
            'og_image_path' => $ogPath,
            'commenter' => $commenter,
            'is_admin' => $isAdmin,
            'admin_avatar' => $this->adminAvatar($isAdmin),
            'my_pending' => $myPending,
            'side_posts' => $sidePosts,
        ]);
    }

    /** 浏览计数上报（POST，秒开秒关不计数） */
    public function view(string $postId): void
    {
        Comments::countView((int) $postId);
        http_response_code(204);
        exit;
    }

    /** 提交评论（POST） */
    public function comment(string $postId): void
    {
        if (!Comments::open()) {
            http_response_code(403);
            exit;
        }
        $post = Post::getById((int) $postId);
        if (!$post) {
            $this->abort404();
        }
        Response::redirect(Comments::submit($post, null));
    }

    /** 标签云 */
    public function tags(): void
    {
        if (!Context::navEnabled('tags')) {
            $this->abort404();
        }
        [$posts, $total] = Post::load('published', null, null, null, 1, 99999);
        $catNames = Category::nameMap();
        $buckets = [];
        foreach ($posts as $p) {
            $p['category_name'] = $p['category_id'] !== null ? ($catNames[$p['category_id']] ?? '') : '';
            foreach ($p['tags'] as $t) {
                $buckets[$t][] = $p;
            }
        }
        $keys = array_keys($buckets);
        usort($keys, static function (string $a, string $b) use ($buckets): int {
            $c = count($buckets[$b]) <=> count($buckets[$a]);
            return $c !== 0 ? $c : strcmp($a, $b);
        });
        $groups = [];
        foreach ($keys as $k) {
            $groups[] = [$k, $buckets[$k]];
        }
        $this->render('tags.html', ['tag_groups' => $groups, 'post_total' => $total]);
    }

    /** 搜索：跳转到首页查询串 */
    public function search(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            Response::redirect('/');
        }
        Response::redirect('/?q=' . rawurlencode($q));
    }

    /** 批量补 category_name */
    private function decorate(array $posts, array $catNames): array
    {
        foreach ($posts as &$p) {
            $p['category_name'] = $p['category_id'] !== null ? ($catNames[$p['category_id']] ?? '') : '';
        }
        unset($p);
        return $posts;
    }

    /** 博主身份条头像：QQ 邮箱走 qlogo，其余 Cravatar */
    private function adminAvatar(bool $isAdmin): string
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
