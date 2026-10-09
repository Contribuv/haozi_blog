<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Settings;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Context;
use Blog\Service\ErrorPages;
use Blog\Service\Flash;

/**
 * 后台「外观与主题」：卡片网格展示全部主题，选择后保存即切换。
 * 对照原项目 admin_themes。
 */
class ThemeController extends BaseController
{
    /** GET /admin/themes：展示全部主题 */
    public function index(): void
    {
        Auth::requireAdmin();
        // themes / active_theme 已由模板全局上下文注入
        $this->render('admin/themes.html', []);
    }

    /** POST /admin/themes：切换启用主题 */
    public function activate(): void
    {
        Auth::requireAdmin();
        $newTheme = trim((string) Request::post('active_theme', ''));

        $validKeys = [];
        foreach (Context::listThemes() as $t) {
            $validKeys[] = (string) $t['key'];
        }

        if ($newTheme !== '' && in_array($newTheme, $validKeys, true)) {
            Settings::set('active_theme', $newTheme);
            // nginx 的 error_page 直接吐静态文件，不走 PHP，故换主题后必须重建，
            // 否则 404/403/502 仍是上一个主题的样式
            $errs = ErrorPages::rebuild();
            if (isset($errs['error'])) {
                Flash::error('主题已切换，但静态错误页重建失败：' . $errs['error'] . '，请手动执行 php bin/build_50x.php');
            } else {
                Flash::success('主题已切换为：' . $newTheme);
            }
        } else {
            Flash::error('无效的主题：' . $newTheme);
        }
        Response::redirect('/admin/themes');
    }
}