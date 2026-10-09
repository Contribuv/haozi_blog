<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Project;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Flash;
use Blog\Service\Github;
use Blog\Service\Lang;
use Blog\Service\ProjectCache;
use Blog\Service\ProjectSync;

/**
 * 后台项目管理。
 * 对照原项目 app.py 的 admin_projects / admin_project_new / admin_project_edit /
 * admin_project_delete / admin_projects_bulk / admin_project_sync /
 * admin_projects_sync_all / admin_projects_sync_status。
 */
class ProjectController extends BaseController
{
    /** GET /admin/projects：项目列表（补语言色带） */
    public function index(): void
    {
        Auth::requireAdmin();
        $projects = array_map([Lang::class, 'decorate'], Project::load());
        $this->render('admin/projects.html', ['projects' => $projects]);
    }

    /** ANY /admin/projects/new：新增项目（同仓库去重 + 首次同步 README） */
    public function create(): void
    {
        Auth::requireAdmin();
        if (Request::method() === 'POST') {
            $urlIn = (string) Request::post('url', '');
            $slug = Github::parseRepo($urlIn);
            if ($slug !== null && Project::repoExists($slug)) {
                Flash::error('该项目已存在，不能重复添加（' . $slug . '）');
                $this->render('admin/project_edit.html', ['project' => null]);
                return;
            }
            Project::save($_POST);
            // 同时同步 README 入缓存（管理员手动触发，前台零 GitHub 请求）
            if ($slug !== null) {
                ProjectCache::put($slug, Github::fetchProject($urlIn));
            }
            Flash::success('项目已添加（已从 GitHub 同步实时数据）');
            Response::redirect('/admin/projects');
        }
        $this->render('admin/project_edit.html', ['project' => null]);
    }

    /** ANY /admin/projects/<project_id>/edit：编辑项目 */
    public function edit(string $projectId): void
    {
        Auth::requireAdmin();
        $project = Project::get((int) $projectId);
        if ($project === null) {
            Flash::error('项目不存在');
            Response::redirect('/admin/projects');
        }
        if (Request::method() === 'POST') {
            Project::save($_POST, (int) $projectId);
            Flash::success('项目已更新');
            Response::redirect('/admin/projects');
        }
        $this->render('admin/project_edit.html', ['project' => $project]);
    }

    /** POST /admin/projects/<project_id>/delete：删除项目 */
    public function delete(string $projectId): void
    {
        Auth::requireAdmin();
        Project::delete((int) $projectId);
        Flash::success('项目已删除');
        Response::redirect('/admin/projects');
    }

    /** POST /admin/projects/bulk：批量删除 */
    public function bulk(): void
    {
        Auth::requireAdmin();
        $action = (string) Request::post('action', '');
        $ids = $_POST['project_ids'] ?? [];
        if ($action !== 'delete') {
            Flash::error('无效的批量操作');
            Response::redirect('/admin/projects');
        }
        if (!is_array($ids) || !$ids) {
            Flash::error('未选择任何项目');
            Response::redirect('/admin/projects');
        }
        foreach ($ids as $pid) {
            Project::delete((int) $pid);
        }
        Flash::success('已删除 ' . count($ids) . ' 个项目');
        Response::redirect('/admin/projects');
    }

    /** POST /admin/projects/<project_id>/sync：启动单项目后台同步 */
    public function sync(string $projectId): void
    {
        Auth::requireAdmin();
        $id = (int) $projectId;
        if (Project::get($id) === null) {
            Response::json(['started' => false, 'error' => '项目不存在']);
        }
        if (!ProjectSync::start($id)) {
            Response::json(['started' => false, 'error' => '已有同步任务正在后台执行，请等待完成']);
        }
        Response::json(['started' => true, 'total' => 1]);
    }

    /** POST /admin/projects/sync-all：启动全量后台同步 */
    public function syncAll(): void
    {
        Auth::requireAdmin();
        $projects = Project::load();
        if (!$projects) {
            Response::json(['started' => false, 'error' => '没有可同步的项目']);
        }
        if (!ProjectSync::start(null)) {
            Response::json(['started' => false, 'error' => '已有同步任务正在后台执行，请等待完成']);
        }
        Response::json(['started' => true, 'total' => count($projects)]);
    }

    /** GET /admin/projects/sync-status：同步进度（?consume=1 消费完成态）
     *  运行中则在此推进一个项目（自驱动队列），返回最新状态。 */
    public function syncStatus(): void
    {
        Auth::requireAdmin();
        if ((string) ($_GET['consume'] ?? '') === '1') {
            Response::json(ProjectSync::consume());
        }
        $st = ProjectSync::state();
        if ($st['running']) {
            $st = ProjectSync::advance();
        }
        Response::json($st);
    }
}