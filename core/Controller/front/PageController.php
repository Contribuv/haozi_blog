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
        $this->render('links.html', [
            'links' => \Blog\Model\Link::load('approved'),
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
            Flash::success('申请已提交，等待管理员审核');
            Response::redirect('/links');
        }
        $this->render('links_apply.html', ['form' => null]);
    }
}
