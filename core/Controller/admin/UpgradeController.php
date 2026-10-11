<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Context;
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
        // 平台可用性状态过期时，挂一个「响应发出后再探测」的钩子，用户不必等探测
        if (Upgrade::sourcesStale()) {
            Upgrade::scheduleProbe();
        }
        $this->render('admin/upgrade.html', ['current_version' => Context::VERSION]);
    }

    /** POST /admin/upgrade：检测并以独立后台进程执行一键升级，HTTP 立即返回，不占用 worker */
    public function run(): void
    {
        Auth::requireAdmin();
        $curVer = Upgrade::parseVersion(Context::VERSION);
        $info = Upgrade::checkLatestVersion(true);
        $upgradable = $info !== null && $curVer !== null && self::cmp($info['version'], $curVer) > 0;

        if (!$upgradable) {
            Response::json(['ok' => false, 'message' => '当前已是最新版本，无需升级']);
        }
        if (Upgrade::isRunning()) {
            Response::json(['ok' => false, 'message' => '升级任务已在进行中，请稍候']);
        }

        $tag = (string) $info['tag'];
        // 先自己把进度置为 running 再拉起后台进程：前端轮询立刻有反馈，也顺带充当「正在升级」的互斥标记
        Upgrade::clearProgress();
        Upgrade::clearCancel();
        Upgrade::writeProgress(['state' => 'running', 'phase' => 'spawn', 'label' => '正在启动升级进程…', 'percent' => -1]);

        if (Upgrade::spawnWorker($tag)) {
            // 释放 session 排他锁后即返回；真正的升级与进度写入都在后台进程里
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            Response::json(['ok' => true]);
        }

        // 环境不允许起后台进程（如 exec 被禁）：回退为同步执行，功能仍可用，只是仍会占用本 worker
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        [$ok, $msg] = Upgrade::doUpgrade($tag);
        Response::json(['ok' => $ok, 'message' => $msg]);
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
        $state = Upgrade::sourceState();

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
            // 双平台可用性（含上次探测时间），供页面展示与判断会走哪个源
            'sources' => [
                ['key' => 'github', 'label' => 'GitHub', 'ok' => (bool) $state['github']['ok'], 'checked' => (int) $state['github']['checked']],
                ['key' => 'gitee', 'label' => 'Gitee', 'ok' => (bool) $state['gitee']['ok'], 'checked' => (int) $state['gitee']['checked']],
            ],
        ]);
    }

    /** GET /admin/upgrade/progress：前端轮询升级进度。升级请求已释放 session 锁，此处能立即读 */
    public function progress(): void
    {
        Auth::requireAdmin();
        $p = Upgrade::progress();
        // 只读短请求，读完随手释放锁，免得与后续轮询互相排队
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        Response::json($p);
    }

    /** POST /admin/upgrade/cancel：写入取消标记，升级流程在下一个检查点中止 */
    public function cancel(): void
    {
        Auth::requireAdmin();
        Upgrade::requestCancel();
        // 升级请求已释放 session 锁，此处读完即放，免得与进度轮询互相排队
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        Response::json(['ok' => true]);
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