<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Request;
use Blog\Response;
use Blog\Model\User;

/**
 * 后台登录态（对照原项目 session['admin_logged_in'] / admin_required）。
 */
class Auth
{
    public static function isAdmin(): bool
    {
        return !empty($_SESSION['admin_logged_in']);
    }

    public static function username(): string
    {
        return (string) ($_SESSION['admin_username'] ?? '');
    }

    /** 账号密码通过、等待 OTP 验证的用户名 */
    public static function pendingOtpUser(): string
    {
        return (string) ($_SESSION['_otp_user'] ?? '');
    }

    public static function setPendingOtpUser(string $username): void
    {
        $_SESSION['_otp_user'] = $username;
    }

    public static function clearPendingOtpUser(): void
    {
        unset($_SESSION['_otp_user']);
    }

    public static function login(string $username): void
    {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        unset($_SESSION['_otp_user']);
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_username'], $_SESSION['_otp_user']);
    }

    /** 强制要求登录，未登录跳转登录页（对照 admin_required 装饰器） */
    public static function requireAdmin(): void
    {
        if (self::isAdmin()) {
            return;
        }
        if (self::pendingOtpUser() !== '') {
            Response::redirect('/admin/otp');
        }
        Response::redirect('/admin/login');
    }

    /** 当前管理员账号（users 表唯一记录） */
    public static function currentUser(): ?array
    {
        return User::sole();
    }

    /**
     * 删除初始密码明文文件 data/.initial_admin_password。
     * 管理员真正改密（后台改密 / 忘记密码重置）后初始密码即失效，调用本函数避免明文残留磁盘。
     */
    public static function clearInitialPwdFile(): void
    {
        $file = PHP_BLOG_ROOT . '/data/.initial_admin_password';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
