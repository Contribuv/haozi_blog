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
        // 版本检测结果里记录了这份信息来自哪个平台：升级时优先从该平台下载源码包，
        // 避免检测说 Gitee 可用、下载却先去盲试 GitHub 直连（超时/404 白耗数秒还误标熔断）
        $preferred = (string) ($info['source'] ?? '');
        // 先自己把进度置为 running 再拉起后台进程：前端轮询立刻有反馈，也顺带充当「正在升级」的互斥标记
        Upgrade::clearProgress();
        Upgrade::clearCancel();
        // 同时清掉上次残留的升级锁：上面已确认没有新鲜的进行中进程，而 writeProgress 会刷新锁 mtime，
        // 残留锁被刷新后就再也过期不了，新 worker 会因为建不了锁而直接失败
        Upgrade::clearLock();
        Upgrade::writeProgress(['state' => 'running', 'phase' => 'spawn', 'label' => '正在启动升级进程…', 'percent' => -1]);

        if (Upgrade::spawnWorker($tag, $preferred)) {
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
        [$ok, $msg] = Upgrade::doUpgrade($tag, $preferred);
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
            // 版本结果的缓存时间：页面用它说明「这份版本信息来自缓存」，避免与实时可用状态冲突
            'cached_at' => $info !== null ? Upgrade::cachedAt() : 0,
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

    /**
     * POST /admin/upgrade/cancel：请求取消升级。
     * 进程还在跑 → 写标记等它自行中止；进程心跳已过期 → 由 Upgrade::cancel() 就地收敛为终态，
     * 保证前端一定能拿到「已取消」，不会永远停在「正在取消…」。
     */
    public function cancel(): void
    {
        Auth::requireAdmin();
        [$ok, $msg] = Upgrade::cancel();
        // 升级请求已释放 session 锁，此处读完即放，免得与进度轮询互相排队
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        Response::json(['ok' => $ok, 'message' => $msg]);
    }

    /** POST /admin/upgrade/reset：清除升级残留状态（进度 / 取消标记 / 升级锁），供进程已中断时恢复 */
    public function reset(): void
    {
        Auth::requireAdmin();
        [$ok, $msg] = Upgrade::resetState();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        Response::json(['ok' => $ok, 'message' => $msg]);
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