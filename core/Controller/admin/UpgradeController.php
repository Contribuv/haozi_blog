<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Context;
use Blog\Service\Flash;
use Blog\Service\Upgrade;

/**
 * 后台「系统升级」：版本检测（异步接口）与一键升级。
 * 对照原项目 admin_upgrade / admin_upgrade_check。
 */
class UpgradeController extends BaseController
{
    /** GET /admin/upgrade：立即渲染，版本检测交给前端异步请求 */
    public function index(): void
    {
        Auth::requireAdmin();
        $this->render('admin/upgrade.html', ['current_version' => Context::VERSION]);
    }

    /** POST /admin/upgrade：检测并执行一键升级 */
    public function run(): void
    {
        Auth::requireAdmin();
        $curVer = Upgrade::parseVersion(Context::VERSION);
        $info = Upgrade::checkLatestVersion(true);
        $upgradable = $info !== null && $curVer !== null && self::cmp($info['version'], $curVer) > 0;

        if (!$upgradable) {
            Flash::error('当前已是最新版本，无需升级');
            Response::redirect('/admin/upgrade');
        }

        try {
            [$ok, $msg] = Upgrade::doUpgrade((string) $info['tag']);
        } catch (\Throwable $e) {
            $ok = false;
            $msg = '升级失败：' . $e->getMessage();
        }
        if ($ok) {
            Flash::success($msg);
        } else {
            Flash::error($msg);
        }
        Response::redirect('/admin/upgrade');
    }

    /** GET /admin/upgrade/check：异步版本检测接口（force=1 跳过缓存） */
    public function check(): void
    {
        Auth::requireAdmin();
        $curVer = Upgrade::parseVersion(Context::VERSION);
        $force = (string) ($_GET['force'] ?? '') === '1';
        $info = Upgrade::checkLatestVersion($force);
        $upgradable = $info !== null && $curVer !== null && self::cmp($info['version'], $curVer) > 0;
        $body = $info !== null ? (string) $info['body'] : '';

        Response::json([
            'ok' => $info !== null,
            'current_version' => Context::VERSION,
            'latest_version' => $info !== null ? (string) $info['tag'] : '',
            'tag' => $info !== null ? (string) $info['tag'] : '',
            'upgradable' => $upgradable,
            'published_at' => $info !== null ? (string) $info['published_at'] : '',
            'body' => $body,
            'body_html' => $body !== '' ? Upgrade::bodyHtml($body) : '',
            'release_url' => $info !== null ? (string) $info['html_url'] : Upgrade::RELEASE_URL,
            'source' => $info !== null ? (string) ($info['source'] ?? 'github') : '',
        ]);
    }

    /** 语义化版本比较：$a 大于 $b 返回正数 */
    private static function cmp(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            if (($a[$i] ?? 0) !== ($b[$i] ?? 0)) {
                return ($a[$i] ?? 0) <=> ($b[$i] ?? 0);
            }
        }
        return 0;
    }
}