<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;

/**
 * 评论图片上传（面向访客）。在 Upload::save 的通用管线之上再加一层访客专属防线：
 *
 * 1) 类型收窄：只允许 png/jpg/jpeg/gif/webp（不含 svg/bmp/heic —— svg 可执行脚本，
 *    heic/bmp 体积大且非浏览器通用格式，均不收）。扩展名不受后台「上传设置」影响，
 *    避免站长把白名单放宽后连带把访客入口也放宽。
 * 2) 尺寸更小：单图默认 5MB（远低于后台的通用上限）。
 * 3) 频率与配额：同 IP 每分钟 ≤3 张、每天 ≤30 张；全站每天总量上限，防止分布式刷盘。
 * 4) 文件内容仍由 Upload::save 用 GD 重新解码（挡改名伪装），并重编码去元数据。
 *
 * 限流状态落 storage/cache/comment_upload.json，读改写同一把锁内完成，避免并发绕限。
 * 图片随评论一起走人工审核；评论被删除后图片成为孤儿，可由后台「孤儿文件清理」回收。
 */
class CommentImage
{
    /** 访客可传的图片扩展名（固定白名单，不读后台配置） */
    public const EXT = ['.png', '.jpg', '.jpeg', '.gif', '.webp'];

    /** 单图大小上限（字节）：5MB */
    public const MAX_BYTES = 5242880;

    /** 同 IP 每分钟最多上传张数 */
    public const PER_MINUTE = 3;
    /** 同 IP 每天最多上传张数 */
    public const PER_DAY = 30;
    /** 全站每天上传总量上限 */
    public const GLOBAL_PER_DAY = 200;

    /** 上传开关（后台可一键关闭），默认开启 */
    public static function enabled(): bool
    {
        return Comments::open() && Settings::bool('comment_image_upload', true);
    }

    /** 单图大小上限（字节） */
    public static function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /** 单图大小上限（人类可读） */
    public static function maxSizeText(): string
    {
        return Upload::fmtSize(self::MAX_BYTES);
    }

    /** 评论图片的专属子目录（相对 uploads/）：按年月分，便于与正文图片/头像等分开维护 */
    public static function subDir(): string
    {
        return 'comment/' . date('Y/m');
    }

    /**
     * 保存访客上传的评论图片，返回 [/uploads/ 相对 URL, 错误信息]。
     * 先做廉价的类型/大小校验，通过后才占用限流配额，再进入通用上传管线。
     */
    public static function save(array $file, string $ip): array
    {
        if (!self::enabled()) {
            return ['', '评论图片上传当前不可用'];
        }
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['', '未选择图片或上传失败'];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['', '图片过大（上限 ' . self::maxSizeText() . '）'];
        }
        $dot = '.' . strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($dot, self::EXT, true)) {
            return ['', '不支持的图片格式（仅 png / jpg / jpeg / gif / webp）'];
        }
        $limited = self::consume($ip);
        if ($limited !== '') {
            return ['', $limited];
        }
        // 落 uploads/comment/年/月/，并用随机名替代访客原始文件名
        return Upload::save($file, self::EXT, self::MAX_BYTES, self::subDir(), true);
    }

    /**
     * 占用一次上传配额；超限返回拒绝原因，成功返回空串。
     * 读改写在同一 LOCK_EX 临界区内完成。
     */
    private static function consume(string $ip): string
    {
        $now = time();
        $f = self::file();
        $fh = @fopen($f, 'c+');
        if ($fh === false) {
            // 拿不到锁文件时宁可放行（上传本身仍受类型/大小/人工审核约束），避免正常用户被挡
            return '';
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return '';
            }
            $raw = (string) stream_get_contents($fh);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            $ips = is_array($data['ips'] ?? null) ? $data['ips'] : [];
            $today = date('Y-m-d', $now);

            // 清理过期的 IP 记录（>24h）
            foreach ($ips as $k => $list) {
                if (!is_array($list)) {
                    unset($ips[$k]);
                    continue;
                }
                $list = array_values(array_filter($list, static fn ($t): bool => ($now - (int) $t) < 86400));
                if ($list === []) {
                    unset($ips[$k]);
                } else {
                    $ips[$k] = $list;
                }
            }

            $mine = is_array($ips[$ip] ?? null) ? $ips[$ip] : [];
            $perMinute = 0;
            foreach ($mine as $t) {
                if (($now - (int) $t) < 60) {
                    $perMinute++;
                }
            }
            if ($perMinute >= self::PER_MINUTE) {
                $err = '上传太频繁，请稍后再试';
            } elseif (count($mine) >= self::PER_DAY) {
                $err = '今天上传的图片已达上限，请明天再试';
            } elseif ((int) ($data['day_n'] ?? 0) >= self::GLOBAL_PER_DAY && ($data['day'] ?? '') === $today) {
                $err = '今日全站上传量已达上限，请稍后再试';
            } else {
                $err = '';
            }

            if ($err === '') {
                $mine[] = $now;
                $ips[$ip] = $mine;
                if (($data['day'] ?? '') !== $today) {
                    $data['day'] = $today;
                    $data['day_n'] = 0;
                }
                $data['day_n'] = (int) ($data['day_n'] ?? 0) + 1;
            }
            $data['ips'] = $ips;

            $out = json_encode($data, JSON_UNESCAPED_UNICODE);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $out === false ? '{}' : $out);
            fflush($fh);
            return $err;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** 限流状态文件（storage/cache 下） */
    private static function file(): string
    {
        $dir = STORAGE_PATH . '/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/comment_upload.json';
    }
}
