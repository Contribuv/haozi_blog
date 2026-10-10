<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;

/**
 * 图片水印服务（对照原项目 _watermark_text/_position/_size/_apply_watermark/_wm_redo_all）。
 *
 * 展示层图片为「压缩 + 水印」版本，无痕原图备份在 uploads/.originals（公网不可达）。
 * PHP 无跨请求常驻线程，历史图刷新改为「自驱动队列」：start() 登记待处理文件入队，
 * 由后台设置页轮询 /admin/settings/wm-refresh 每次推进若干张，状态落盘跨进程可读。
 */
class Watermark
{
    /** 可加水印的位图扩展名（对照 WM_EXT） */
    public const EXT = ['.jpg', '.jpeg', '.png', '.webp', '.bmp'];

    /**
     * 水印字号档位：按图宽比例缩放，min/max 为像素上下限。
     * s 小 / m 标准（默认）/ l 大。
     */
    private const SIZE_LEVELS = [
        's' => ['ratio' => 0.02, 'min' => 14, 'max' => 28],
        'm' => ['ratio' => 0.03, 'min' => 18, 'max' => 52],
        'l' => ['ratio' => 0.04, 'min' => 24, 'max' => 76],
    ];

    /** 合法位置：右下 / 左下 / 右上 / 左上 / 底部居中 */
    private const POSITIONS = ['br', 'bl', 'tr', 'tl', 'bc'];

    /** 单次轮询处理的最大图片数（避免单请求过长） */
    private const BATCH = 3;

    /** 运行态陈旧阈值（秒）：超过视为中断，可重新触发 */
    private const STUCK_TTL = 300;

    /** 字体路径候选（仓库自带优先，跨平台保底） */
    private const FONT_CANDIDATES = [
        'C:/Windows/Fonts/msyh.ttc',                    // 微软雅黑
        'C:/Windows/Fonts/simhei.ttf',                  // 黑体
        'C:/Windows/Fonts/simsun.ttc',                  // 宋体
        '/System/Library/Fonts/PingFang.ttc',           // macOS 苹方
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
    ];

    /** 系统字体扫描目录 */
    private const FONT_SCAN_DIRS = [
        '/usr/share/fonts', '/usr/local/share/fonts',
        '/Library/Fonts', '/System/Library/Fonts',
        'C:/Windows/Fonts',
    ];

    /** CJK 字体文件名关键字（命中越靠前优先级越高） */
    private const CJK_HINTS = ['notosanscjk', 'notoserifcjk', 'sourcehan', 'wqy', 'microhei',
        'zenhei', 'msyh', 'simhei', 'simsun', 'pingfang', 'hiragino',
        'droidsansfallback', 'uming', 'ukai', 'cjk'];

    private static ?string $fontPath = null;
    private static bool $fontResolved = false;

    /** 水印总开关 */
    public static function enabled(): bool
    {
        return Settings::bool('watermark_enabled', true);
    }

    /** 水印文案：优先后台自定义文本，空则回退「站点名 · 域名」 */
    public static function text(): string
    {
        $custom = trim((string) Settings::get('watermark_text', ''));
        if ($custom !== '') {
            return $custom;
        }
        $name = trim((string) Settings::get('blog_name', ''));
        // CLI（backfill 等）无 HTTP_HOST 时不含域名，避免写入 127.0.0.1
        $host = isset($_SERVER['HTTP_HOST']) ? \Blog\Request::host() : '';
        $parts = [];
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($host !== '') {
            $parts[] = $host;
        }
        return implode(' · ', $parts);
    }

    /** 水印位置（默认右下 br） */
    public static function position(): string
    {
        $p = trim((string) Settings::get('watermark_position', ''));
        return in_array($p, self::POSITIONS, true) ? $p : 'br';
    }

    /** 水印字号档位（默认 m） */
    public static function size(): string
    {
        $s = strtolower(trim((string) Settings::get('watermark_size', '')));
        return in_array($s, ['s', 'm', 'l'], true) ? $s : 'm';
    }

    /**
     * 给图片字节叠加角落水印，返回新字节；文字为空或绘制失败时原样返回。
     */
    public static function apply(string $data, string $ext, string $text, string $position = 'br', string $size = 'm'): string
    {
        if ($text === '' || $data === '' || !function_exists('imagettftext')) {
            return $data;
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return $data;
        }
        try {
            $w = imagesx($img);
            $h = imagesy($img);
            if ($w < 1 || $h < 1) {
                imagedestroy($img);
                return $data;
            }
            $lv = self::SIZE_LEVELS[$size] ?? self::SIZE_LEVELS['m'];
            $fs = max($lv['min'], min($lv['max'], (int) round($w * $lv['ratio'])));

            $font = self::fontPath();
            $bbox = $font !== null ? @imagettfbbox($fs, 0, $font, $text) : false;
            if (!is_array($bbox)) {
                imagedestroy($img);
                return $data;
            }
            // bbox: [llx,lly, lrx,lry, urx,ury, ulx,uly]，相对基线
            $textW = abs($bbox[2] - $bbox[0]);
            $topRel = $bbox[5];       // 上边相对基线（负值）
            $bottomRel = $bbox[1];    // 下边相对基线
            $textH = $bottomRel - $topRel;
            $pad = max(10, (int) round($fs * 0.45));

            switch ($position) {
                case 'bl':
                    $x = $pad;
                    $yTop = $h - $textH - $pad;
                    break;
                case 'tr':
                    $x = $w - $textW - $pad;
                    $yTop = $pad;
                    break;
                case 'tl':
                    $x = $pad;
                    $yTop = $pad;
                    break;
                case 'bc':
                    $x = (int) (($w - $textW) / 2);
                    $yTop = $h - $textH - $pad;
                    break;
                case 'br':
                default:
                    $x = $w - $textW - $pad;
                    $yTop = $h - $textH - $pad;
                    break;
            }
            $baseline = $yTop - $topRel;

            imagealphablending($img, true);
            imagesavealpha($img, true);
            // 深色阴影 + 半透明白字：深浅图片上都可读（GD alpha 0 不透明 / 127 全透明）
            $shadow = imagecolorallocatealpha($img, 0, 0, 0, self::gdAlpha(150));
            $white = imagecolorallocatealpha($img, 255, 255, 255, self::gdAlpha(180));
            if ($shadow !== false) {
                imagettftext($img, $fs, 0, $x + 1, $baseline + 1, $shadow, $font, $text);
            }
            if ($white !== false) {
                imagettftext($img, $fs, 0, $x, $baseline, $white, $font, $text);
            }

            ob_start();
            $extLower = strtolower($ext);
            if ($extLower === '.png') {
                imagesavealpha($img, true);
                imagepng($img, null, 6);
            } elseif ($extLower === '.webp') {
                // WebP 必须按原格式输出，否则文件内容(JPEG)与扩展名(.webp)不符
                imagesavealpha($img, true);
                imagewebp($img, null, 82);
            } else {
                imageinterlace($img, true); // 渐进式 JPEG，参照原项目 progressive=True
                imagejpeg($img, null, 82);
            }
            $out = (string) ob_get_clean();
            imagedestroy($img);
            return $out !== '' ? $out : $data;
        } catch (\Throwable $e) {
            @imagedestroy($img);
            return $data;
        }
    }

    /** 不透明度 0-255 → GD alpha 0-127 */
    private static function gdAlpha(int $opacity): int
    {
        return (int) round((255 - $opacity) / 255 * 127);
    }

    /** 发现可用 CJK 字体（仓库自带 → 已知候选 → 扫描系统目录），全部落空返回 null */
    public static function fontPath(): ?string
    {
        if (self::$fontResolved) {
            return self::$fontPath;
        }
        self::$fontResolved = true;
        if (!function_exists('imagettfbbox')) {
            return self::$fontPath = null;
        }
        $candidates = array_merge([PHP_BLOG_ROOT . '/fonts/wqy-microhei.ttc'], self::FONT_CANDIDATES);
        foreach ($candidates as $p) {
            if (is_file($p) && self::fontUsable($p)) {
                return self::$fontPath = $p;
            }
        }
        $found = [];
        foreach (self::FONT_SCAN_DIRS as $d) {
            if (!is_dir($d)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile()) {
                    continue;
                }
                if (preg_match('/\.(ttf|ttc|otf)$/i', $f->getFilename())) {
                    $found[] = $f->getPathname();
                }
            }
        }
        // 扫描仅接受 CJK 命名命中者：纯西文字体只会把中文水印画成方块
        usort($found, static fn (string $a, string $b): int => self::fontRank($a) <=> self::fontRank($b));
        foreach ($found as $p) {
            if (self::fontRank($p) < count(self::CJK_HINTS) && self::fontUsable($p)) {
                return self::$fontPath = $p;
            }
        }
        return self::$fontPath = null;
    }

    private static function fontUsable(string $path): bool
    {
        return is_array(@imagettfbbox(16, 0, $path, 'A'));
    }

    private static function fontRank(string $path): int
    {
        $n = strtolower(basename($path));
        foreach (self::CJK_HINTS as $i => $hint) {
            if (str_contains($n, $hint)) {
                return $i;
            }
        }
        return count(self::CJK_HINTS);
    }

    // ─────────────── 历史图批量刷新（自驱动队列） ───────────────

    private static function stateFile(): string
    {
        $dir = STORAGE_PATH . '/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/wm_refresh.json';
    }

    private static function defaults(): array
    {
        return [
            'running' => false, 'finished' => false, 'remaining' => [],
            'total' => 0, 'done' => 0, 'errs' => 0,
            'started_at' => 0, 'time' => '', 'seconds' => 0,
            'text' => '', 'position' => 'br', 'size' => 'm', 'saved_at' => 0,
        ];
    }

    private static function read(): array
    {
        $f = self::stateFile();
        if (!is_file($f)) {
            return self::defaults();
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? array_merge(self::defaults(), $d) : self::defaults();
    }

    private static function write(array $st): void
    {
        $f = self::stateFile();
        $tmp = $f . '.tmp';
        @file_put_contents($tmp, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($tmp, $f);
    }

    /** 对外状态（供设置页展示）：{state, time, done, errs, seconds} */
    public static function state(): array
    {
        $st = self::read();
        if ($st['running']) {
            if ((time() - (int) $st['saved_at']) > self::STUCK_TTL) {
                return ['state' => 'idle', 'time' => '', 'done' => 0, 'errs' => 0, 'seconds' => 0];
            }
            return ['state' => 'running', 'time' => (string) $st['time'], 'done' => (int) $st['done'],
                'errs' => (int) $st['errs'], 'seconds' => 0];
        }
        if ($st['finished']) {
            return ['state' => 'done', 'time' => (string) $st['time'], 'done' => (int) $st['done'],
                'errs' => (int) $st['errs'], 'seconds' => (int) $st['seconds']];
        }
        return ['state' => 'idle', 'time' => '', 'done' => 0, 'errs' => 0, 'seconds' => 0];
    }

    /**
     * 登记历史图刷新任务。已有刷新在跑（且未陈旧）则返回 false。
     */
    public static function start(string $text, string $position = 'br', string $size = 'm'): bool
    {
        $cur = self::read();
        if ($cur['running'] && (time() - (int) $cur['saved_at']) <= self::STUCK_TTL) {
            return false;
        }
        $files = self::listFiles();
        $st = self::defaults();
        $st['running'] = true;
        $st['remaining'] = $files;
        $st['total'] = count($files);
        $st['started_at'] = time();
        $st['saved_at'] = time();
        $st['text'] = $text;
        $st['position'] = $position;
        $st['size'] = $size;
        if (!$files) {
            // 无历史图：直接标记完成，避免状态页永远 running
            $st['running'] = false;
            $st['finished'] = true;
            $st['time'] = date('H:i:s');
        }
        self::write($st);
        return true;
    }

    /**
     * 推进队列：处理至多 $limit 张。返回推进后的对外状态。
     */
    public static function advance(int $limit = self::BATCH): array
    {
        $st = self::read();
        if (!$st['running']) {
            return self::state();
        }
        for ($i = 0; $i < $limit && $st['remaining']; $i++) {
            $rel = (string) array_shift($st['remaining']);
            if (self::redoOne($rel, (string) $st['text'], (string) $st['position'], (string) $st['size'])) {
                $st['done']++;
            } else {
                $st['errs']++;
            }
            $st['saved_at'] = time();
        }
        if (!$st['remaining']) {
            $st['running'] = false;
            $st['finished'] = true;
            $st['time'] = date('H:i:s');
            $st['seconds'] = max(1, time() - (int) $st['started_at']);
        }
        self::write($st);
        return self::state();
    }

    /** CLI：一次性跑完全部历史图（对照 watermark_backfill） */
    public static function redoAll(string $text, string $position = 'br', string $size = 'm'): array
    {
        $files = self::listFiles();
        $done = $errs = 0;
        foreach ($files as $rel) {
            if (self::redoOne($rel, $text, $position, $size)) {
                $done++;
            } else {
                $errs++;
            }
        }
        return [$done, $errs];
    }

    /**
     * 重做单张：有 .originals 从原图重画；无则先把当前文件备份为无痕原图再叠水印。
     * 返回是否成功。
     */
    private static function redoOne(string $rel, string $text, string $position, string $size): bool
    {
        $root = Upload::root();
        $src = $root . '/' . $rel;
        if (!is_file($src)) {
            return false;
        }
        $ext = strtolower((string) pathinfo($src, PATHINFO_EXTENSION));
        if (!in_array('.' . $ext, self::EXT, true)) {
            return false;
        }
        $orig = $root . '/.originals/' . $rel;
        $data = false;
        if (is_file($orig)) {
            $data = @file_get_contents($orig);
        } else {
            $data = @file_get_contents($src);
            if ($data !== false) {
                $od = dirname($orig);
                if (!is_dir($od)) {
                    @mkdir($od, 0775, true);
                }
                @file_put_contents($orig, $data, LOCK_EX);
            }
        }
        if ($data === false || $data === '') {
            return false;
        }
        $out = self::apply($data, '.' . $ext, $text, $position, $size);
        return @file_put_contents($src, $out, LOCK_EX) !== false;
    }

    /** 列出 uploads 下全部可处理位图的相对路径（跳过 avatar/projects/隐藏目录） */
    private static function listFiles(): array
    {
        $root = Upload::root();
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            $segs = explode('/', $rel);
            $top = $segs[0];
            if (str_starts_with($top, '.') || $top === 'avatar' || $top === 'projects') {
                continue;
            }
            $ext = strtolower((string) pathinfo($f->getFilename(), PATHINFO_EXTENSION));
            if (in_array('.' . $ext, self::EXT, true)) {
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }
}