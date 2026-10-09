<?php
declare(strict_types=1);

namespace Blog\Model;

/**
 * 管理员账号数据模型（users 表恒为单条记录，由数据库触发器硬保证唯一）。
 */
class User extends Model
{
    /** 取唯一管理员账号 */
    public static function sole(): ?array
    {
        $row = self::one('SELECT * FROM users ORDER BY id ASC LIMIT 1');
        return $row ?: null;
    }

    /** 按用户名查账号 */
    public static function findByUsername(string $username): ?array
    {
        $row = self::one('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
        return $row ?: null;
    }

    /** 校验密码，成功返回账号行，失败返回 null */
    public static function verify(string $username, string $password): ?array
    {
        $user = self::findByUsername($username);
        if ($user === null) {
            return null;
        }
        return self::verifyHash((string) $user['password_hash'], $password) ? $user : null;
    }

    /**
     * 校验密码哈希，兼容 werkzeug（原 Flask 项目）与 PHP 原生两种格式。
     * werkzeug 格式：pbkdf2:<digest>:<iterations>$<salt>$<hexhash>，PHP 的 password_verify 无法解析，
     * 这里按标准 PBKDF2-HMAC 重新推导后定长比较。
     */
    public static function verifyHash(string $hash, string $password): bool
    {
        if (str_starts_with($hash, 'pbkdf2:')) {
            $parts = explode('$', $hash);
            if (count($parts) !== 3) {
                return false;
            }
            $meta = explode(':', $parts[0]); // ['pbkdf2', digest, iterations]
            $digest = $meta[1] ?? 'sha256';
            $iterations = (int) ($meta[2] ?? 0);
            if ($iterations <= 0) {
                return false;
            }
            $calc = hash_pbkdf2($digest, $password, $parts[1], $iterations, 0);
            return hash_equals(strtolower($parts[2]), strtolower($calc));
        }
        return password_verify($password, $hash);
    }

    /** 该哈希是否为 PHP 原生格式（非原生则登录成功后懒迁移升级） */
    public static function isNativeHash(string $hash): bool
    {
        return str_starts_with($hash, '$');
    }

    /** 修改密码 */
    public static function updatePassword(int $id, string $plainPassword): void
    {
        self::exec(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($plainPassword, PASSWORD_DEFAULT), $id]
        );
    }
}
