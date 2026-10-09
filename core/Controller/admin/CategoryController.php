<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Category;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Flash;

/**
 * 后台文章分类管理。
 * 对照原项目 app.py 的 admin_categories / admin_category_new / admin_category_edit /
 * admin_category_delete / admin_categories_bulk。
 */
class CategoryController extends BaseController
{
    /** GET /admin/categories：分类列表 */
    public function index(): void
    {
        Auth::requireAdmin();
        $this->render('admin/categories.html', [
            'categories' => Category::load(),
        ]);
    }

    /** ANY /admin/categories/new：新建分类 */
    public function create(): void
    {
        Auth::requireAdmin();
        if (Request::method() === 'POST') {
            Category::save($_POST);
            Flash::success('分类已创建');
            Response::redirect('/admin/categories');
        }
        $this->render('admin/category_edit.html', ['category' => null]);
    }

    /** ANY /admin/categories/<cat_id>/edit：编辑分类 */
    public function edit(string $catId): void
    {
        Auth::requireAdmin();
        $category = Category::get((int) $catId);
        if ($category === null) {
            $this->abort404();
            return;
        }
        if (Request::method() === 'POST') {
            Category::save($_POST, (int) $catId);
            Flash::success('分类已更新');
            Response::redirect('/admin/categories');
        }
        $this->render('admin/category_edit.html', ['category' => $category]);
    }

    /** POST /admin/categories/<cat_id>/delete：删除单个分类 */
    public function delete(string $catId): void
    {
        Auth::requireAdmin();
        Category::delete((int) $catId);
        Flash::success('分类已删除');
        Response::redirect('/admin/categories');
    }

    /** POST /admin/categories/bulk：批量删除（先解绑文章为未分类） */
    public function bulk(): void
    {
        Auth::requireAdmin();
        $action = (string) Request::post('action', '');
        $ids = $_POST['cat_ids'] ?? [];
        if ($action !== 'delete') {
            Flash::error('无效的批量操作');
            Response::redirect('/admin/categories');
        }
        if (!is_array($ids) || !$ids) {
            Flash::error('未选择任何分类');
            Response::redirect('/admin/categories');
        }
        foreach ($ids as $cid) {
            Category::delete((int) $cid);
        }
        Flash::success('已删除 ' . count($ids) . ' 个分类（该分类下文章已变为未分类）');
        Response::redirect('/admin/categories');
    }
}