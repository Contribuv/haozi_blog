<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Link;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Flash;

/**
 * 后台友情链接管理。
 * 对照原项目 admin_links / admin_link_approve / admin_link_reject / admin_link_new /
 * admin_link_edit / admin_link_delete / admin_links_bulk。
 */
class LinkController extends BaseController
{
    /** GET /admin/links：友链列表（待审核 + 已通过） */
    public function index(): void
    {
        Auth::requireAdmin();
        $this->render('admin/links.html', [
            'links' => Link::load('approved'),
            'pending' => Link::load('pending'),
        ]);
    }

    /** POST /admin/links/<link_id>/approve */
    public function approve(string $linkId): void
    {
        Auth::requireAdmin();
        Link::setStatus((int) $linkId, 'approved');
        Flash::success('友链已通过审核');
        Response::redirect('/admin/links');
    }

    /** POST /admin/links/<link_id>/reject */
    public function reject(string $linkId): void
    {
        Auth::requireAdmin();
        Link::setStatus((int) $linkId, 'rejected');
        Flash::success('友链已拒绝');
        Response::redirect('/admin/links');
    }

    /** ANY /admin/links/new：新建友链 */
    public function create(): void
    {
        Auth::requireAdmin();
        if (Request::method() === 'POST') {
            Link::save($_POST);
            Flash::success('链接已添加');
            Response::redirect('/admin/links');
        }
        $this->render('admin/link_edit.html', ['link' => null]);
    }

    /** ANY /admin/links/<link_id>/edit：编辑友链 */
    public function edit(string $linkId): void
    {
        Auth::requireAdmin();
        $link = Link::get((int) $linkId);
        if ($link === null) {
            Flash::error('链接不存在');
            Response::redirect('/admin/links');
        }
        if (Request::method() === 'POST') {
            Link::save($_POST, (int) $linkId);
            Flash::success('链接已更新');
            Response::redirect('/admin/links');
        }
        $this->render('admin/link_edit.html', ['link' => $link]);
    }

    /** POST /admin/links/<link_id>/delete：删除单个友链 */
    public function delete(string $linkId): void
    {
        Auth::requireAdmin();
        Link::delete((int) $linkId);
        Flash::success('链接已删除');
        Response::redirect('/admin/links');
    }

    /** POST /admin/links/bulk：approve 通过 / reject 拒绝 / delete 删除 */
    public function bulk(): void
    {
        Auth::requireAdmin();
        $action = (string) Request::post('action', '');
        $ids = $_POST['link_ids'] ?? [];
        if (!in_array($action, ['approve', 'reject', 'delete'], true)) {
            Flash::error('无效的批量操作');
            Response::redirect('/admin/links');
        }
        if (!is_array($ids) || !$ids) {
            Flash::error('未选择任何链接');
            Response::redirect('/admin/links');
        }
        if ($action === 'delete') {
            foreach ($ids as $lid) {
                Link::delete((int) $lid);
            }
            Flash::success('已删除 ' . count($ids) . ' 条链接');
        } else {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            foreach ($ids as $lid) {
                Link::setStatus((int) $lid, $status);
            }
            Flash::success('已将 ' . count($ids) . ' 条链接设为「' . ($action === 'approve' ? '已通过' : '已拒绝') . '」');
        }
        Response::redirect('/admin/links');
    }
}