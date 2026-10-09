<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;

/**
 * OTP 双重验证（标准 TOTP，RFC 6238，HMAC-SHA1 + 6 位 + 30 秒步长）。
 * 与 Google Authenticator / 1Password / Authy 等通用验证器兼容。
 * 密钥以无填充 base32 存 settings 表（与 smtp_pass / github_token 同策略）；恢复码只存 sha256 哈希。
 * 对照原项目 app.py 中的 _totp_code / _totp_verify / _gen_recovery_codes 等。
 */
class Otp
{
    /** 30 秒一个时间窗口 */
    public const STEP = 30;
    /** 6 位验证码 */
    public const DIGITS = 6;
    /** 前后各容差 1 步，抗设备时钟漂移 */
    public const WINDOW = 1;
    /** 启用 OTP 时生成的恢复码数量 */
    public const RECOVERY_N = 10;
    /** 恢复码字符集：去掉易混淆的 0/O/1/I */
    public const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** 紧急禁用标记文件路径（部署者可 touch 该文件临时跳过 OTP 自救） */
    public static function emergencyFile(): string
    {
        return PHP_BLOG_ROOT . '/data/.otp_disable';
    }

    /** RFC 4648 base32 字符集（A-Z2-7） */
    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** base32 密钥存储时不带 '=' 填充，读取时按需补齐 */
    private static function b32pad(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        return $raw . str_repeat('=', (8 - strlen($raw) % 8) % 8);
    }

    /** base32 编码（PHP 无内置实现，等价 Python base64.b32encode） */
    public static function base32Encode(string $data): string
    {
        $bits = '';
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    /** base32 解码（忽略填充与余位），非法字符返回 false */
    public static function base32Decode(string $input): string|false
    {
        $input = strtoupper(rtrim(trim($input), '='));
        $bits = '';
        for ($i = 0, $n = strlen($input); $i < $n; $i++) {
            $pos = strpos(self::B32_ALPHABET, $input[$i]);
            if ($pos === false) {
                return false;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break; // 丢弃不足 8 位的填充余位
            }
            $out .= chr(bindec($chunk));
        }
        return $out;
    }

    /** 当前绑定的 base32 密钥（未绑定返回空串） */
    public static function secret(): string
    {
        return trim((string) Settings::get('otp_secret', ''));
    }

    /** 是否存在 OTP 紧急禁用标记文件 */
    public static function emergencyOff(): bool
    {
        return is_file(self::emergencyFile());
    }

    /** 用户是否在设置中打开了 OTP 开关 */
    public static function configured(): bool
    {
        $v = strtolower(trim((string) Settings::get('otp_enabled', '0')));
        return in_array($v, ['1', 'on', 'true', 'yes'], true);
    }

    /** OTP 是否实际生效：开关打开、已绑定密钥，且不存在紧急禁用标记 */
    public static function enabled(): bool
    {
        if (self::emergencyOff()) {
            return false;
        }
        return self::configured() && self::secret() !== '';
    }

    /** RFC 6238 TOTP 计算：HMAC-SHA1(counter) 动态截断出 6 位码 */
    public static function totpCode(string $secretB32, ?int $t = null): string
    {
        $key = self::base32Decode(self::b32pad($secretB32));
        if ($key === false || $key === '') {
            return '';
        }
        $now = $t ?? time();
        $counter = pack('J', intdiv($now, self::STEP)); // J = 64 位大端无符号，等价 Python struct '>Q'
        $mac = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($mac[strlen($mac) - 1]) & 0x0F;
        $part = substr($mac, $offset, 4);
        $num = unpack('N', $part)[1] & 0x7FFFFFFF; // N = 32 位大端无符号
        $code = $num % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** 校验 6 位验证码，允许前后各 WINDOW 步的时间窗口 */
    public static function verify(string $secretB32, string $code): bool
    {
        $code = str_replace(' ', '', trim($code));
        if (!ctype_digit($code) || strlen($code) !== self::DIGITS) {
            return false;
        }
        $now = time();
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $expect = self::totpCode($secretB32, $now + $i * self::STEP);
            if ($expect !== '' && hash_equals($expect, $code)) {
                return true;
            }
        }
        return false;
    }

    /** 生成 otpauth:// URI，供验证器扫码绑定 */
    public static function otpauthUri(string $secretB32, string $username): string
    {
        $issuer = trim((string) Settings::get('blog_name', 'infowe'));
        if ($issuer === '') {
            $issuer = 'infowe';
        }
        $acct = $username !== '' ? $username : 'admin';
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($acct)
            . '?secret=' . $secretB32
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::STEP;
    }

    /**
     * 生成 n 个 8 位恢复码，返回 [明文列表, sha256 哈希列表]。
     * 明文仅生成时展示这一次，数据库只存哈希，用于校验时一次性消费。
     */
    public static function genRecoveryCodes(int $n = self::RECOVERY_N): array
    {
        $seen = [];
        while (count($seen) < $n) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $seen[$code] = true;
        }
        $codes = array_keys($seen);
        $hashes = array_map(static fn (string $c): string => hash('sha256', $c), $codes);
        return [$codes, $hashes];
    }

    /** 恢复码归一化：去分隔符/空白并转大写（兼容 XXXX-XXXX / XXXXXXXX / 夹空格三种输入） */
    public static function normRecovery(string $code): string
    {
        return str_replace(['-', ' '], '', strtoupper(trim($code)));
    }

    /** 当前剩余未使用的恢复码数量 */
    public static function recoveryRemaining(): int
    {
        return count(self::recoveryHashes());
    }

    /** 读取恢复码哈希列表（json 存储于 settings） */
    private static function recoveryHashes(): array
    {
        $cur = (string) Settings::get('otp_recovery_codes', '');
        if ($cur === '') {
            return [];
        }
        $list = json_decode($cur, true);
        return is_array($list) ? array_values($list) : [];
    }

    /** 校验并一次性消费一个恢复码；成功返回 true */
    public static function consumeRecovery(string $code): bool
    {
        $norm = self::normRecovery($code);
        if (strlen($norm) !== 8) {
            return false;
        }
        $digest = hash('sha256', $norm);
        $hashes = self::recoveryHashes();
        if (!in_array($digest, $hashes, true)) {
            return false;
        }
        $hashes = array_values(array_filter($hashes, static fn ($h): bool => $h !== $digest));
        Settings::set('otp_recovery_codes', json_encode($hashes));
        return true;
    }

    /** 生成新的 base32 密钥（160bit，标准字符集 A-Z2-7，无填充） */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** 关闭 OTP：清空开关、密钥与恢复码 */
    public static function reset(): void
    {
        Settings::setMany([
            'otp_enabled' => '',
            'otp_secret' => '',
            'otp_recovery_codes' => '',
        ]);
    }
}
