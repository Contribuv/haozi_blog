<?php
declare(strict_types=1);

namespace Blog\Controller\front;

use Blog\Controller\BaseController;
use Blog\Model\Post;
use Blog\Model\Settings;

/**
 * RSS 订阅（对照原项目 rss_feed）。
 */
class FeedController extends BaseController
{
    public function rss(): void
    {
        [$posts] = Post::load('published', null, null, null, 1, 20);
        $root = $this->urlRoot();
        $blogName = (string) Settings::get('blog_name', 'infowe');

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<rss version="2.0">' . "\n  <channel>\n";
        $xml .= '    <title>' . self::esc($blogName) . "</title>\n";
        $xml .= '    <link>' . self::esc($root) . "</link>\n";
        $xml .= '    <description>' . self::esc((string) Settings::get('blog_subtitle', '')) . "</description>\n";
        $xml .= "    <language>zh-CN</language>\n";
        foreach ($posts as $p) {
            $url = $root . 'post/' . (int) $p['id'];
            $xml .= "    <item>\n";
            $xml .= '      <title>' . self::esc((string) $p['title']) . "</title>\n";
            $xml .= '      <link>' . self::esc($url) . "</link>\n";
            $xml .= '      <description>' . self::esc((string) ($p['excerpt'] ?? '')) . "</description>\n";
            $xml .= '      <pubDate>' . self::esc(self::pubDate((string) $p['created_at'])) . "</pubDate>\n";
            $xml .= '      <guid>' . self::esc($url) . "</guid>\n";
            $xml .= "    </item>\n";
        }
        $xml .= "  </channel>\n</rss>\n";

        header('Content-Type: application/rss+xml; charset=utf-8');
        echo $xml;
        exit;
    }

    /** 站点根地址（结尾带 /） */
    private function urlRoot(): string
    {
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        return $scheme . '://' . $host . '/';
    }

    /** 中国时区字符串 → RFC822 */
    public static function pubDate(string $createdAt): string
    {
        $ts = strtotime(substr($createdAt, 0, 19));
        if ($ts === false) {
            return $createdAt;
        }
        return date('D, d M Y H:i:s', $ts) . ' +0800';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
