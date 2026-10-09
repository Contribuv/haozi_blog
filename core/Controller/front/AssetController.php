<?php
declare(strict_types=1);

namespace Blog\Controller\front;

use Blog\Controller\BaseController;
use Blog\Service\Avatar;
use Blog\Service\Upload;

/**
 * 静态资源与上传文件控制器（对照原项目 uploaded_file / theme_static /
 * /static & /share-thumb & avatar_proxy）。
 */
class AssetController extends BaseController
{
    /** /uploads/<path:filename> */
    public function upload(string $filename): void
    {
        $abs = Upload::resolve($filename);
        if ($abs === null || !is_file($abs)) {
            $this->abort404();
        }
        $this->serve($abs, 604800);
    }

    /** /static/<filename>（public/static 下资源） */
    public function static(string $filename): void
    {
        $abs = $this->safeJoin(PUBLIC_PATH . '/static', $filename);
        if ($abs === null || !is_file($abs)) {
            $this->abort404();
        }
        $this->serve($abs, 604800);
    }

    /** /themes/<path:filename>（主题静态资源，filename 含主题目录名） */
    public function themeStatic(string $filename): void
    {
        $abs = $this->safeJoin(PHP_BLOG_ROOT . '/themes', $filename);
        if ($abs === null || !is_file($abs)) {
            $this->abort404();
        }
        $this->serve($abs, 604800);
    }

    /** /share-thumb/<path:filename>：4:3 分享缩略图（懒生成 + 缓存） */
    public function shareThumb(string $filename): void
    {
        $out = Upload::shareThumb($filename);
        if ($out === null || !is_file($out)) {
            $this->abort404();
        }
        $this->serve($out, 86400);
    }

    /** /avatar/<key>/<int:size>.png：站内头像代理（轮换图源 + 磁盘缓存） */
    public function avatar(string $key, string $size): void
    {
        $size = (int) $size;
        if (str_starts_with($key, 'q') && ctype_digit(substr($key, 1))) {
            $qq = substr($key, 1);
            $s = Avatar::qqAvatarSize($size);
            $sources = [];
            foreach ([1, 2, 3, 4] as $i) {
                $sources[] = "https://q{$i}.qlogo.cn/g?b=qq&nk={$qq}&s={$s}";
            }
        } elseif (str_starts_with($key, 'e') && preg_match('/^[0-9a-f]{32}$/', substr($key, 1))) {
            $md5 = substr($key, 1);
            $s = max(1, min($size, 640));
            $sources = [
                "https://cravatar.cn/avatar/{$md5}?s={$s}&d=404",
                "https://weavatar.com/avatar/{$md5}?s={$s}&d=404",
            ];
        } else {
            $this->abort404();
            return;
        }

        $dir = STORAGE_PATH . '/cache/avatars';
        $maxAge = 86400 * 7;
        $file = $dir . '/' . $key . '_' . $s . '.png';
        if (is_file($file) && (time() - filemtime($file)) < $maxAge) {
            $this->serve($file, $maxAge, 'image/png');
            return;
        }
        foreach ($sources as $url) {
            [$data, $mime] = $this->fetchImage($url);
            if ($data !== null) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                @file_put_contents($file, $data, LOCK_EX);
                header('Content-Type: ' . $mime);
                header('Cache-Control: public, max-age=' . $maxAge);
                header('X-Avatar-Source: fetch');
                echo $data;
                exit;
            }
        }
        $this->abort404();
    }

    /** 输出文件（含条件请求与缓存头） */
    private function serve(string $abs, int $maxAge, ?string $mime = null): void
    {
        $mime = $mime ?? $this->mimeOf($abs);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: public, max-age=' . $maxAge);
        readfile($abs);
        exit;
    }

    private function mimeOf(string $abs): string
    {
        $ext = strtolower((string) pathinfo($abs, PATHINFO_EXTENSION));
        return match ($ext) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'txt', 'md' => 'text/plain; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    /** 拼接并校验路径落在 $base 内，防路径穿越 */
    private function safeJoin(string $base, string $rel): ?string
    {
        $rel = ltrim(str_replace('\\', '/', rawurldecode($rel)), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $abs = realpath($base . '/' . $rel);
        $baseReal = realpath($base);
        if ($abs === false || $baseReal === false || !str_starts_with($abs, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abs;
    }

    /** 抓取图片字节，返回 [data|null, mime] */
    private function fetchImage(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [null, ''];
        }
        \Blog\Service\Http::applyCurl($ch); // 注入 CA 证书（跨命名空间完全限定）
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'infowe-blog',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300 || $body === '') {
            return [null, ''];
        }
        return [(string) $body, $mime !== '' ? $mime : 'image/png'];
    }
}
