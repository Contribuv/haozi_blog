<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;

/**
 * 上传文件服务：保存/删除/尺寸读取/分享缩略图/孤儿清理。
 * 上传根目录为项目根 uploads/，按 年/月 分子目录；无痕原图存 uploads/.originals（公网不可达）。
 */
class Upload
{
    public const MAX_SIZE = 20971520; // 20MB

    /** 允许的图片扩展名（对照 ALLOWED_IMAGE_EXT） */
    public const IMAGE_EXT = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.bmp', '.heic', '.heif'];
    /** 允许的音视频扩展名（对照 ALLOWED_MEDIA_EXT） */
    public const MEDIA_EXT = ['.mp4', '.webm', '.ogg', '.mov', '.avi'];
    /** 允许的附件扩展名（对照 ALLOWED_FILE_EXT） */
    public const FILE_EXT = ['.zip', '.rar', '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.txt', '.md', '.py', '.js', '.json'];

    public static function root(): string
    {
        return PHP_BLOG_ROOT . '/uploads';
    }

    /** 从文本中提取全部 /uploads/ 相对 URL（URL 解码归一，去重） */
    public static function extractUrls(string ...$texts): array
    {
        $urls = [];
        foreach ($texts as $t) {
            if ($t === '' || $t === null) {
                continue;
            }
            if (preg_match_all('#/uploads/[/\w%.\-]+#u', (string) $t, $m)) {
                foreach ($m[0] as $u) {
                    $urls[] = rawurldecode($u);
                }
            }
        }
        return array_values(array_unique($urls));
    }

    /** 按正文顺序返回第一个 /uploads/ 相对 URL */
    public static function firstUrl(string ...$texts): string
    {
        foreach ($texts as $t) {
            if ($t === '' || $t === null) {
                continue;
            }
            if (preg_match('#/uploads/[/\w%.\-]+#u', (string) $t, $m)) {
                return rawurldecode($m[0]);
            }
        }
        return '';
    }

    /** 读取上传图片真实尺寸（按路径缓存）；失败返回 null */
    public static function imageDims(string $relUrl): ?array
    {
        $rel = $relUrl;
        if (str_contains($rel, '/uploads/')) {
            $rel = substr($rel, (int) strpos($rel, '/uploads/') + 9);
        }
        $rel = ltrim(rawurldecode($rel), '/');
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '.')) {
            return null;
        }
        $src = self::root() . '/' . $rel;
        if (!is_file($src)) {
            return null;
        }
        $info = @getimagesize($src);
        if (!$info) {
            return null;
        }
        return [(int) $info[0], (int) $info[1]];
    }

    /** 把外部路径安全解析为 uploads 下的绝对路径；非法返回 null */
    public static function resolve(string $filename): ?string
    {
        $rel = ltrim(str_replace('\\', '/', rawurldecode($filename)), '/');
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '.')) {
            return null;
        }
        $segs = explode('/', $rel);
        if ($segs[0] === '' || str_starts_with($segs[0], '.') || $segs[0] === 'avatar' || $segs[0] === 'projects') {
            // projects 与 avatar 目录允许访问（README 图片 / 头像落盘），此处仅阻断隐藏目录
            if (str_starts_with($segs[0], '.')) {
                return null;
            }
        }
        $abs = self::root() . '/' . $rel;
        $real = realpath($abs);
        $baseReal = realpath(self::root());
        if ($baseReal === false || $real === false || !str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

    /**
     * 保存上传文件，返回 [/uploads/ 相对 URL, 错误信息]。
     *
     * 位图（png/jpg/bmp/webp/heic/heif）走「压缩 → 备份无痕原图 → 叠水印」管线：
     * - 无痕原图存 uploads/.originals（公网不可达），展示层一律用带水印版本
     * - gif 保留动画不压缩；HEIC/HEIF 因 GD 无解码能力而明确报错
     */
    public static function save(array $file, array $allowedExt): array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['', '未选择文件或上传失败'];
        }
        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            return ['', '文件过大（上限 20MB）'];
        }
        $ext = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
        $dot = '.' . $ext;
        if (!in_array($dot, $allowedExt, true)) {
            return ['', '不支持的文件类型: ' . $dot];
        }
        $sub = date('Y/m');
        $dir = self::root() . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return ['', '目录创建失败'];
        }
        $base = self::safeStem((string) $file['name']);
        $filename = $base . '.' . $ext;
        $i = 1;
        while (file_exists($dir . '/' . $filename)) {
            $filename = $base . '_' . $i . '.' . $ext;
            $i++;
        }
        $dest = $dir . '/' . $filename;

        if (in_array($dot, self::IMAGE_EXT, true)) {
            // GD 无 HEIC/HEIF 解码能力，明确报错而非落盘无法展示的文件
            if (in_array($dot, ['.heic', '.heif'], true)) {
                return ['', '服务器缺少 HEIC 解码支持（PHP GD 无法解码 HEIC/HEIF），请改用 JPG/PNG 上传'];
            }
            $data = @file_get_contents((string) $file['tmp_name']);
            $opt = $data !== false ? self::optimize($data, $ext) : null;
            if ($opt !== null) {
                [$outData, $newExt] = $opt; // $newExt 形如 '.jpg'
                if ($newExt !== $dot) {
                    $filename = $base . $newExt;
                    $j = 1;
                    while (file_exists($dir . '/' . $filename)) {
                        $filename = $base . '_' . $j . $newExt;
                        $j++;
                    }
                    $dest = $dir . '/' . $filename;
                }
                // 无痕原图（压缩后、加水印前）备份，展示层始终用带水印版本
                if (in_array($newExt, Watermark::EXT, true)) {
                    $origDir = self::root() . '/.originals/' . $sub;
                    if (!is_dir($origDir)) {
                        @mkdir($origDir, 0775, true);
                    }
                    @file_put_contents($origDir . '/' . $filename, $outData, LOCK_EX);
                    if (Watermark::enabled()) {
                        $text = Watermark::text();
                        if ($text !== '') {
                            $outData = Watermark::apply($outData, $newExt, $text, Watermark::position(), Watermark::size());
                        }
                    }
                }
                if (@file_put_contents($dest, $outData, LOCK_EX) === false) {
                    return ['', '文件保存失败'];
                }
            } elseif (!move_uploaded_file($file['tmp_name'], $dest)) {
                // 压缩失败（解码失败/缺 GD）回退保存原文件
                return ['', '文件保存失败'];
            }
        } elseif (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['', '文件保存失败'];
        }

        $url = '/uploads/' . $sub . '/' . rawurlencode($filename);
        // 钩子位 upload.after：插件可对刚落盘的文件做二次处理
        \Blog\Hook::emit('upload.after', [
            'url' => $url, 'path' => $dest, 'name' => $filename, 'ext' => ltrim(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), '.'),
        ]);
        return [$url, ''];
    }

    /** 保留可读中文文件名，过滤路径分隔符与危险字符 */
    public static function safeStem(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = (string) preg_replace('#[\\\\/:\*\?"<>\|]#u', '-', $name);
        $name = trim($name);
        return $name !== '' ? mb_substr($name, 0, 80) : 'file';
    }

    /**
     * 图片压缩：最长边 1920 时等比缩小并重新编码，返回 [data, 新扩展名]；失败返回 null。
     * 对照原项目 _optimize_image：png 保 PNG（保留透明通道），其余（jpg/bmp/webp）统一转 JPEG。
     */
    private static function optimize(string $data, string $ext): ?array
    {
        if ($data === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }
        try {
            $w = imagesx($img);
            $h = imagesy($img);
            $max = 1920;
            $scale = min(1.0, $max / max(1, max($w, $h)));
            if ($scale < 1.0) {
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $dst;
            }
            ob_start();
            if ($ext === 'png') {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                imagepng($img, null, 6);
                $newExt = '.png';
            } else {
                imageinterlace($img, true);
                imagejpeg($img, null, 82);
                $newExt = '.jpg';
            }
            $out = (string) ob_get_clean();
            imagedestroy($img);
            return $out !== '' ? [$out, $newExt] : null;
        } catch (\Throwable $e) {
            @imagedestroy($img);
            return null;
        }
    }

    /** 分享缩略图：4:3 居中裁切为 800×600 JPEG，落盘缓存 */
    public static function shareThumb(string $filename): ?string
    {
        $src = self::resolve($filename);
        if ($src === null || !is_file($src)) {
            return null;
        }
        $cacheDir = STORAGE_PATH . '/cache/share_thumbs';
        $key = sha1(rawurldecode($filename)) . '.jpg';
        $out = $cacheDir . '/' . $key;
        if (is_file($out) && filemtime($out) >= filemtime($src)) {
            return $out;
        }
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if ($img === false) {
            return $src; // 非位图（如 svg）回退原图
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $tw = 800;
        $th = 600;
        $targetRatio = $tw / $th;
        $srcRatio = $w / max(1, $h);
        if ($srcRatio > $targetRatio) {
            $sh = $h;
            $sw = (int) round($h * $targetRatio);
            $sx = (int) round(($w - $sw) / 2);
            $sy = 0;
        } else {
            $sw = $w;
            $sh = (int) round($w / $targetRatio);
            $sx = 0;
            $sy = (int) round(($h - $sh) / 2);
        }
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $img, 0, 0, $sx, $sy, $tw, $th, $sw, $sh);
        imagejpeg($dst, $out, 82);
        imagedestroy($img);
        imagedestroy($dst);
        return $out;
    }

    /**
     * 孤儿文件清理：返回未被任何文章（标题/正文/摘要/标签）引用的上传文件列表。
     * 排除 avatar / projects / 隐藏目录（含 .originals）与 uploads 根目录文件。
     */
    public static function orphans(): array
    {
        $referenced = [];
        $rows = \Blog\Db::query('SELECT title, content, excerpt, tags FROM posts')->fetchAll();
        foreach ($rows as $r) {
            foreach (self::extractUrls((string) $r['title'], (string) $r['content'], (string) $r['excerpt'], (string) $r['tags']) as $u) {
                $referenced[$u] = true;
            }
        }
        $out = [];
        $root = self::root();
        if (!is_dir($root)) {
            return $out;
        }
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
            if ($rel === '' || count($segs) < 2) {
                continue; // 仅清理子目录内文件，根目录文件不列出
            }
            $top = $segs[0];
            if (str_starts_with($top, '.') || $top === 'avatar' || $top === 'projects') {
                continue;
            }
            $url = '/uploads/' . $rel;
            if (isset($referenced[$url])) {
                continue;
            }
            $size = $f->getSize();
            $out[] = [
                'rel' => $rel,
                'url' => $url,
                'size' => self::fmtSize($size),
                'size_bytes' => $size,
                'ext' => strtolower((string) pathinfo($f->getFilename(), PATHINFO_EXTENSION)),
                'mtime' => date('Y-m-d H:i', $f->getMTime()),
                'has_origin' => is_file($root . '/.originals/' . $rel),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['mtime'], (string) $a['mtime']));
        return $out;
    }

    /**
     * 删除孤儿文件（按 uploads 下的相对路径），返回空串表示成功，否则为失败原因。
     * 同时删除 .originals 无痕原图并自底向上清理空目录；拒绝非法路径。
     */
    public static function deleteRel(string $rel): string
    {
        $rel = rawurldecode(str_replace('\\', '/', $rel));
        $segs = explode('/', $rel);
        if ($rel === '' || str_starts_with($rel, '.') || str_starts_with($rel, '/') || str_contains($rel, '..')
            || $segs[0] === '' || str_starts_with($segs[0], '.') || in_array($segs[0], ['avatar', 'projects'], true)) {
            return '非法路径';
        }
        $abs = self::root() . '/' . $rel;
        if (!is_file($abs)) {
            return '文件不存在';
        }
        if (!@unlink($abs)) {
            return '删除失败';
        }
        $orig = self::root() . '/.originals/' . $rel;
        if (is_file($orig)) {
            @unlink($orig);
        }
        self::pruneEmptyDirs(dirname($abs));
        return '';
    }

    /** 字节数转人类可读尺寸（对照 _fmt_size） */
    public static function fmtSize(int $bytes): string
    {
        $n = (float) $bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($n < 1024 || $unit === 'GB') {
                return $unit === 'B' ? ((int) $n) . ' B' : number_format($n, 1) . ' ' . $unit;
            }
            $n /= 1024.0;
        }
        return number_format($n, 1) . ' TB';
    }

    /** 自底向上删除空目录（不越过 uploads 根目录） */
    private static function pruneEmptyDirs(string $dir): void
    {
        $root = realpath(self::root());
        if ($root === false) {
            return;
        }
        $d = $dir;
        while ($d !== '' && $d !== $root) {
            $real = realpath($d);
            if ($real === false || $real === $root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                break;
            }
            if (!@rmdir($d)) {
                break;
            }
            $d = dirname($d);
        }
    }

    /** 按 URL 删除上传文件及其无痕原图（拒绝 avatar/projects/隐藏目录） */
    public static function deleteByUrl(string $url): bool
    {
        $rel = rawurldecode(substr($url, (int) strpos($url, '/uploads/') + 9));
        $segs = explode('/', $rel);
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '.')
            || $segs[0] === '' || str_starts_with($segs[0], '.') || in_array($segs[0], ['avatar', 'projects'], true)) {
            return false;
        }
        $abs = self::root() . '/' . $rel;
        if (!is_file($abs)) {
            return false;
        }
        @unlink($abs);
        $orig = self::root() . '/.originals/' . $rel;
        if (is_file($orig)) {
            @unlink($orig);
        }
        return true;
    }
}
