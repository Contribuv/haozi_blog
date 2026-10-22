<?php
declare(strict_types=1);

namespace Blog\Controller\front;

use Blog\Controller\BaseController;
use Blog\Model\Category;
use Blog\Model\Post;
use Blog\Model\Settings;
use Blog\Model\Timeline;
use Blog\Request;
use Blog\Response;
use Blog\Service\Context;
use Blog\Service\Flash;

/**
 * 前台静态页面控制器（对照原项目 about / links_page / links_apply）。
 */
class PageController extends BaseController
{
    /** 关于页 */
    public function about(): void
    {
        if (!Context::navEnabled('about')) {
            $this->abort404();
        }
        $skills = [];
        $raw = (string) Settings::get('skills', '[]');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $skills = $decoded;
        }
        [, $postTotal] = Post::load('published', null, null, null, 1, 1);
        $this->render('about.html', [
            'skills' => $skills,
            'about_intro' => Settings::get('about_intro', ''),
            'author' => Settings::get('author', ''),
            'github' => Settings::get('github_username', '') ?: Settings::get('social_github', ''),
            'timeline' => Timeline::load(),
            'post_total' => $postTotal,
            'category_total' => count(Category::load()),
        ]);
    }

    /** 友链列表 */
    public function links(): void
    {
        // 本站头像完整地址：相对路径补全站点根；未设置时回退 favicon
        $avatar = trim((string) Settings::get('avatar', ''));
        if ($avatar !== '' && !str_starts_with($avatar, 'http')) {
            $avatar = Request::baseUrl() . $avatar;
        }
        if ($avatar === '') {
            $avatar = Request::baseUrl() . '/favicon.ico';
        }
        $this->render('links.html', [
            'links' => \Blog\Model\Link::load('approved'),
            // 站点根地址（scheme://host）：供友链页「本站信息」卡片展示，方便对方复制回挂
            'site_url' => Request::baseUrl(),
            // 头像完整 URL：供「点击复制」按钮一键回填
            'avatar_url' => $avatar,
        ]);
    }

    /** 友链申请（GET 表单 / POST 提交） */
    public function linksApply(): void
    {
        if (!Context::navEnabled('links')) {
            $this->abort404();
        }
        if (Request::method() === 'POST') {
            $name = trim((string) Request::post('name', ''));
            $url = trim((string) Request::post('url', ''));
            $description = trim((string) Request::post('description', ''));
            $avatar = trim((string) Request::post('avatar', ''));
            if ($name === '' || $url === '') {
                Flash::error('请填写名称和网址');
                $this->render('links_apply.html', ['form' => $_POST]);
                return;
            }
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $url = 'http://' . $url;
            }
            \Blog\Model\Link::save(
                ['name' => $name, 'url' => $url, 'description' => $description, 'avatar' => $avatar, 'sort_order' => 0],
                null,
                'pending'
            );
            // PRG：重定向到成功页，避免刷新重复提交
            Response::redirect('/links/apply/success');
        }
        $this->render('links_apply.html', ['form' => null]);
    }

    /** 友链申请提交成功页（提示需博主上线审核后才会显示） */
    public function linksApplySuccess(): void
    {
        if (!Context::navEnabled('links')) {
            $this->abort404();
        }
        // 博主称呼：取后台「博主资料 → 博主名称」，留空依次回退站点名、通用称呼
        $reviewer = trim((string) Settings::get('author', ''));
        if ($reviewer === '') {
            $reviewer = trim((string) Settings::get('blog_name', ''));
        }
        if ($reviewer === '') {
            $reviewer = '博主';
        }
        $this->render('links_apply_success.html', ['reviewer' => $reviewer]);
    }
}
