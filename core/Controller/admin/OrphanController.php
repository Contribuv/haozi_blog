<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Flash;
use Blog\Service\Upload;

/**
 * 后台孤儿文件清理。
 * 对照原项目 admin_orphans（GET 列表 + POST 清理，兼容 AJAX 逐项结果）。
 * 判定基准：全库文章 title/content/excerpt/tags 是否引用该 /uploads/ 相对 URL。
 */
class OrphanController extends BaseController
{
    /** GET /admin/orphans：孤儿文件列表 */
    public function index(): void
    {
        Auth::requireAdmin();
        $orphans = Upload::orphans();
        $total = 0;
        foreach ($orphans as $o) {
            $total += (int) $o['size_bytes'];
        }
        $this->render('admin/orphans.html', [
            'orphans' => $orphans,
            'total_size' => Upload::fmtSize($total),
        ]);
    }

    /** POST /admin/orphans/clean：删除所选孤儿文件 */
    public function clean(): void
    {
        Auth::requireAdmin();
        $selected = $_POST['path'] ?? [];
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

        $deleted = 0;
        $failed = 0;
        $results = [];
        if (is_array($selected)) {
            foreach ($selected as $raw) {
                $rel = (string) $raw;
                $reason = Upload::deleteRel($rel);
                if ($reason === '') {
                    $deleted++;
                    if ($isAjax) {
                        $results[] = ['rel' => $rel, 'ok' => true];
                    }
                } else {
                    $failed++;
                    if ($isAjax) {
                        $results[] = ['rel' => $rel, 'ok' => false, 'reason' => $reason];
                    }
                }
            }
        }

        if ($isAjax) {
            Response::json(['deleted' => $deleted, 'failed' => $failed, 'results' => $results]);
        }
        if ($deleted && !$failed) {
            Flash::success('已清理 ' . $deleted . ' 个孤儿文件');
        } elseif ($deleted && $failed) {
            Flash::error('已清理 ' . $deleted . ' 个孤儿文件，' . $failed . ' 个删除失败');
        } elseif ($failed) {
            Flash::error($failed . ' 个文件删除失败');
        } else {
            Flash::success('没有可清理的文件');
        }
        Response::redirect('/admin/orphans');
    }
}