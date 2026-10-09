<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;

/**
 * 头像服务（对照原项目 _avatar_urls / _avatar_file_exists）。
 * 统一返回站内代理地址 /avatar/<key>/<size>.png，由代理轮换外部图源并落盘缓存，
 * 浏览器不直连外部域名。无邮箱无 QQ 时返回空串，由调用方回退默认头像。
 */
class Avatar
{
    /** 站内默认头像（外部源全部失败时前端兜底） */
    public static function defaultUrl(): string
    {
        return '/static/images/default-avatar.svg';
    }

    /** 从 QQ 邮箱提取 QQ 号，非 QQ 邮箱返回空串 */
    public static function qqFromEmail(?string $email): string
    {
        $email = strtolower(trim((string) $email));
        if (preg_match('/^(\d{5,12})@qq\.com$/', $email, $m)) {
            return $m[1];
        }
        return '';
    }

    /** 计算联系邮箱的 md5（用于 Cravatar 兜底） */
    public static function emailHash(?string $email): string
    {
        $email = strtolower(trim((string) $email));
        return $email === '' ? '' : md5($email);
    }

    /** qlogo 仅支持 40/100/640 三档，就近向上取档 */
    public static function qqAvatarSize(int $size): int
    {
        if ($size <= 40) {
            return 40;
        }
        if ($size <= 100) {
            return 100;
        }
        return 640;
    }

    /**
     * 返回 [主头像URL, 备用URL]。备用源由代理内部兜底，故第二项恒为空串。
     */
    public static function urls(?string $emailHash, ?string $qq = '', int $size = 80): array
    {
        $emailHash = (string) $emailHash;
        $qq = (string) $qq;
        if ($emailHash === '' && $qq === '') {
            return ['', ''];
        }
        if ($qq !== '') {
            return ['/avatar/q' . $qq . '/' . self::qqAvatarSize($size) . '.png', ''];
        }
        return ['/avatar/e' . $emailHash . '/' . max(1, $size) . '.png', ''];
    }

    /** 后台配置的博主头像文件是否存在（存在则优先使用该头像） */
    public static function configuredAvatarExists(): bool
    {
        $cfg = trim((string) Settings::get('avatar', ''));
        if ($cfg === '') {
            return false;
        }
        $rel = ltrim($cfg, '/');
        $p = PHP_BLOG_ROOT . '/' . $rel;
        return is_file($p);
    }
}
