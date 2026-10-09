<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * 登录防爆破（基于 IP 的失败计数）。
 * 原项目用进程内字典，PHP 多进程模型下改为文件持久化（storage/cache/login_attempts.json），
 * 语义与原实现保持一致：连续失败达阈值触发验证码，再达上限锁定 15 分钟，每次失败递增延迟。
 */
class LoginGuard
{
    /** 连续失败上限 */
    public const MAX_FAILS = 5;
    /** 锁定时长（15 分钟） */
    public const LOCK_SECONDS = 15 * 60;
    /** 基础失败延迟（秒） */
    public const BASE_DELAY = 0.5;
    /** 连续失败达到该次数后要求验证码 */
    public const CAPTCHA_FAILS = 3;

    /** 记录文件路径 */
    private static function file(): string
    {
        return STORAGE_PATH . '/cache/login_attempts.json';
    }

    /** @return array<string,array{fails:int,lock_until:int}> */
    private static function load(): array
    {
        $f = self::file();
        if (!is_file($f)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($f), true);
        return is_array($data) ? $data : [];
    }

    private static function save(array $data): void
    {
        $dir = dirname(self::file());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(self::file(), json_encode($data), LOCK_EX);
    }

    /** 清理过期记录，防止文件被爆破日志撑爆（按最后活动时间判定，锁定中的保留） */
    private static function prune(array $data): array
    {
        $now = time();
        foreach ($data as $ip => $rec) {
            $ts = (int) ($rec['ts'] ?? 0);
            $lock = (int) ($rec['lock_until'] ?? 0);
            if ($ts < $now - 86400 && $lock < $now) {
                unset($data[$ip]);
            }
        }
        if (count($data) > 2000) {
            // 只丢最旧的，不能整份清空 —— 否则伪造 IP 灌满 2000 就能把锁定状态一起抹掉
            $data = array_slice($data, -2000, null, true);
        }
        return $data;
    }

    /** 该 IP 当前是否处于锁定状态 */
    public static function blocked(string $ip): bool
    {
        $rec = self::load()[$ip] ?? null;
        return $rec !== null && (int) ($rec['lock_until'] ?? 0) > time();
    }

    /** 该 IP 是否已需要输入验证码 */
    public static function captchaRequired(string $ip): bool
    {
        $rec = self::load()[$ip] ?? null;
        return $rec !== null && (int) ($rec['fails'] ?? 0) >= self::CAPTCHA_FAILS;
    }

    /** 该 IP 当前连续失败次数 */
    public static function fails(string $ip): int
    {
        return (int) (self::load()[$ip]['fails'] ?? 0);
    }

    /** 剩余可尝试次数 */
    public static function remaining(string $ip): int
    {
        return max(self::MAX_FAILS - self::fails($ip), 0);
    }

    /** 登记一次失败：累加计数、递增延迟、达上限则锁定 */
    public static function registerFail(string $ip): void
    {
        $data = self::prune(self::load());
        $rec = $data[$ip] ?? ['fails' => 0, 'lock_until' => 0];
        $rec['fails'] = (int) $rec['fails'] + 1;
        $rec['ts'] = time(); // 最后活动时间，供 prune 判定过期
        if ($rec['fails'] >= self::MAX_FAILS) {
            $rec['lock_until'] = time() + self::LOCK_SECONDS;
        }
        $data[$ip] = $rec;
        self::save($data);
        // 递增失败延迟：0.5s, 1s, 2s, 4s ... 拖慢爆破
        $delay = self::BASE_DELAY * (2 ** min($rec['fails'] - 1, 5));
        usleep((int) (min($delay, 16) * 1_000_000));
    }

    /** 登录成功：清空该 IP 的失败记录 */
    public static function registerSuccess(string $ip): void
    {
        $data = self::load();
        unset($data[$ip]);
        self::save($data);
    }

    /** 生成算术验证码题面，答案存 session */
    public static function genCaptcha(): string
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $_SESSION['captcha_answer'] = $a + $b;
        $_SESSION['_captcha_q'] = $a . ' + ' . $b;
        return $_SESSION['_captcha_q'];
    }
}
