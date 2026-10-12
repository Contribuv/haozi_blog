<?php
declare(strict_types=1);

namespace Blog\Controller;

use Blog\Response;
use Blog\Service\Context;
use Blog\Service\ErrorPage;
use Blog\Service\Upgrade;
use Blog\View;

/**
 * 控制器基类：统一渲染与错误页。
 */
abstract class BaseController
{
    /** 渲染模板并输出（自动加站点全局上下文；后台页自动注入更新提醒） */
    protected function render(string $tpl, array $data = [], int $code = 200): void
    {
        self::releaseSession();
        Response::html(View::render($tpl, $data + self::upgradeBanner($tpl)), $code);
    }

    /**
     * 后台页面注入更新提醒：只读版本缓存（零网络），绝不阻塞页面渲染。
     * 平台状态过期时挂「响应后异步探测」钩子，下次进后台即可见提醒。
     */
    private static function upgradeBanner(string $tpl): array
    {
        if (!str_starts_with(ltrim($tpl, '/'), 'admin/')) {
            return [];
        }
        $stale = Upgrade::sourcesStale();
        if ($stale) {
            Upgrade::scheduleProbe();
        }
        $info = Upgrade::cachedLatest();
        // upgrade_stale 供仪表盘决定是否在页面加载后主动补一次检测（缓存新鲜时无需重复请求）
        if ($info === null) {
            return ['upgrade_check' => null, 'upgrade_available' => false, 'upgrade_stale' => $stale];
        }
        $cur = Upgrade::parseVersion(Context::VERSION);
        // 缓存里的 version 已由 checkLatestVersion 解析为 [int,int,int]，直接用，勿再 (string) 转换
        $latest = is_array($info['version'] ?? null) ? $info['version'] : null;
        $available = $cur !== null && $latest !== null
            && version_compare(Upgrade::versionString($latest), Upgrade::versionString($cur), '>');
        return ['upgrade_check' => $info, 'upgrade_available' => $available, 'upgrade_stale' => $stale];
    }

    /**
     * 渲染前释放 session 文件锁（session_write_close）。
     * session 锁自 session_start 起持有到请求结束，渲染全程都在锁内；
     * 管理员多标签并发（同步轮询 + 后台页操作）会互相串行排队。
     * 渲染期唯一会写 session 的是 Runtime 消费 flash（unset 不落盘会导致
     * 消息永不消失），故先在此消费并缓存到 $GLOBALS，再安全关闭。
     */
    protected static function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $GLOBALS['_flashes_takeover'] = $_SESSION['_flashes'] ?? [];
            unset($_SESSION['_flashes']);
            session_write_close();
        }
    }

    /** 404 页面（站内风格，模板缺失时自动降级） */
    protected function abort404(): void
    {
        ErrorPage::render(404);
    }
}
