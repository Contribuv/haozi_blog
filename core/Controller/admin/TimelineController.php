<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Timeline;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Flash;

/**
 * 后台博客历程（时间线）管理。
 * 对照原项目 admin_timeline / admin_timeline_new / admin_timeline_edit /
 * admin_timeline_delete / admin_timeline_bulk。
 */
class TimelineController extends BaseController
{
    /** GET /admin/timeline：历程列表 */
    public function index(): void
    {
        Auth::requireAdmin();
        $this->render('admin/timeline.html', ['items' => Timeline::load()]);
    }

    /** ANY /admin/timeline/new：新建历程 */
    public function create(): void
    {
        Auth::requireAdmin();
        if (Request::method() === 'POST') {
            $date = trim((string) Request::post('date', ''));
            $content = trim((string) Request::post('content', ''));
            if ($date === '' || $content === '') {
                Flash::error('日期和内容不能为空');
                $this->render('admin/timeline_edit.html', ['item' => null]);
                return;
            }
            Timeline::save($_POST);
            Flash::success('历程已添加');
            Response::redirect('/admin/timeline');
        }
        $this->render('admin/timeline_edit.html', ['item' => null]);
    }

    /** ANY /admin/timeline/<item_id>/edit：编辑历程 */
    public function edit(string $itemId): void
    {
        Auth::requireAdmin();
        $item = Timeline::get((int) $itemId);
        if ($item === null) {
            Flash::error('历程不存在');
            Response::redirect('/admin/timeline');
        }
        if (Request::method() === 'POST') {
            $date = trim((string) Request::post('date', ''));
            $content = trim((string) Request::post('content', ''));
            if ($date === '' || $content === '') {
                Flash::error('日期和内容不能为空');
                $this->render('admin/timeline_edit.html', ['item' => $item]);
                return;
            }
            Timeline::save($_POST, (int) $itemId);
            Flash::success('历程已更新');
            Response::redirect('/admin/timeline');
        }
        $this->render('admin/timeline_edit.html', ['item' => $item]);
    }

    /** POST /admin/timeline/<item_id>/delete：删除单个历程 */
    public function delete(string $itemId): void
    {
        Auth::requireAdmin();
        Timeline::delete((int) $itemId);
        Flash::success('历程已删除');
        Response::redirect('/admin/timeline');
    }

    /** POST /admin/timeline/bulk：批量删除 */
    public function bulk(): void
    {
        Auth::requireAdmin();
        $action = (string) Request::post('action', '');
        $ids = $_POST['timeline_ids'] ?? [];
        if ($action !== 'delete') {
            Flash::error('无效的批量操作');
            Response::redirect('/admin/timeline');
        }
        if (!is_array($ids) || !$ids) {
            Flash::error('未选择任何历程');
            Response::redirect('/admin/timeline');
        }
        foreach ($ids as $tid) {
            Timeline::delete((int) $tid);
        }
        Flash::success('已删除 ' . count($ids) . ' 条历程');
        Response::redirect('/admin/timeline');
    }
}