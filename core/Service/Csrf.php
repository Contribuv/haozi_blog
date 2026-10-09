<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Request;

/**
 * 跨站请求伪造防护：同源校验。
 *
 * 不引入 CSRF token，改为校验浏览器无法伪造的 Origin 头（Fetch 规范把 Origin
 * 列为 forbidden header，页面 JS 改不了；跨站 POST 必然带本站以外的 Origin），
 * 缺失时退回 Referer。配合 session 的 SameSite=Lax 构成两层防护，且一处
 * choke point 即可覆盖全部 61 个 POST 路由与 12 处 fetch，无需逐个模板插 token。
 */
class Csrf
{
    /** POST 请求是否来自本站；非 HTTP 上下文（CLI 定时任务）直接放行 */
    public static function isSameOrigin(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }
        $host = strtolower(Request::host());
        $origin = strtolower(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));
        if ($origin !== '') {
            return self::hostOf($origin) === $host;
        }
        // 老浏览器不发 Origin，退回 Referer（同样由浏览器自动带，JS 改不了）
        $referer = strtolower(trim((string) ($_SERVER['HTTP_REFERER'] ?? '')));
        if ($referer !== '') {
            return self::hostOf($referer) === $host;
        }
        // 两者都无：非浏览器客户端（如 curl / 监控脚本），无从判断来源，放行
        return true;
    }

    /** 从 scheme://host[:port] 中取出 host 部分；格式非法返回空串 */
    private static function hostOf(string $url): string
    {
        $parts = parse_url($url);
        $h = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($h === '' || !isset($parts['port'])) {
            return $h;
        }
        return $h . ':' . (int) $parts['port'];
    }
}