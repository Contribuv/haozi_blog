<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Link;
use Blog\Model\Post;
use Blog\Model\Settings;
use Blog\Request;

/**
 * 模板全局上下文（对照原项目 inject_globals / context_processor）。
 * 每次请求首次渲染时注入，供所有模板读取站点信息、导航开关、主题信息等。
 */
class Context
{
    /** 程序版本号（PHP 版独立版本线） */
    public const VERSION = '2.0.0';

    /** 可开关的导航项：(配置 key 后缀, 显示名, 链接)；首页与写作入口为固定项 */
    public const NAV_PAGES = [
        ['posts', '文章', '/posts'],
        ['tags', '标签', '/tags'],
        ['projects', '项目', '/projects'],
        ['links', '友链', '/links'],
        ['about', '关于', '/about'],
        ['status', '状态', '/status'],
    ];

    /** 导航项是否启用（默认启用） */
    public static function navEnabled(string $key): bool
    {
        $v = strtolower(trim((string) Settings::get('nav_' . $key, '1')));
        return in_array($v, ['1', 'on', 'true', 'yes'], true);
    }

    /** 组装模板全局变量 */
    public static function globals(): array
    {
        $navOn = [];
        $navPages = [];
        $navAll = [];
        foreach (self::NAV_PAGES as [$key, $label, $href]) {
            $on = self::navEnabled($key);
            $navOn[$key] = $on;
            $navAll[] = ['key' => $key, 'label' => $label, 'enabled' => $on];
            if ($on) {
                $navPages[] = ['key' => $key, 'label' => $label, 'href' => $href];
            }
        }

        return [
            'blog_name' => Settings::get('blog_name', 'infowe'),
            'blog_subtitle' => Settings::get('blog_subtitle', ''),
            'home_title' => Settings::get('home_title', ''),
            'home_posts_count' => Settings::get('home_posts_count', '6'),
            'posts_per_page' => Settings::get('posts_per_page', '20'),
            'comments_enabled' => Settings::get('comments_enabled', '1'),
            'icp_beian' => Settings::get('icp_beian', ''),
            'police_beian' => Settings::get('police_beian', ''),
            'stats_code' => self::sanitizeStatsCode((string) Settings::get('stats_code', '')),
            'author' => Settings::get('author', ''),
            'author_bio' => Settings::get('author_bio', ''),
            'about_intro' => Settings::get('about_intro', ''),
            'skills' => Settings::get('skills', ''),
            'social_github' => Settings::get('social_github', ''),
            'contact_email' => Settings::get('contact_email', ''),
            'avatar' => Settings::get('avatar', ''),
            'all_tags' => Post::allTags(),
            'links' => Link::load(),
            'nav_on' => $navOn,
            'nav_pages' => $navPages,
            'nav_all' => $navAll,
            'version' => self::VERSION,
            'now_year' => (int) date('Y'),
            // 全量设置键值（模板中 config.get('xxx', '') 读取，等价 Flask 的 app.config）
            'config' => Settings::all(),
            'active_theme' => self::activeTheme(),
            'themes' => self::listThemes(),
            'theme_has_css' => self::themeHasCss(),
            'theme_ver' => self::themeAssetVer(),
            'admin_ver' => self::adminAssetVer(),
            'upgrade_check' => null,
            'upgrade_available' => false,
            // 模板辅助函数：GitHub 语言调色板（对照原项目 lang_color）
            'lang_color' => static fn (string $name): string => Lang::color($name),
        ];
    }

    /** 当前启用主题 key */
    public static function activeTheme(): string
    {
        $t = trim((string) Settings::get('active_theme', ''));
        return $t !== '' ? $t : 'tech';
    }

    /** 扫描 themes/ 得到全部主题元信息 */
    public static function listThemes(): array
    {
        $base = PHP_BLOG_ROOT . '/themes';
        $themes = [];
        if (!is_dir($base)) {
            return $themes;
        }
        $names = [];
        foreach (scandir($base) ?: [] as $n) {
            if ($n === '.' || $n === '..' || str_starts_with($n, '.')) {
                continue;
            }
            if (is_dir($base . '/' . $n)) {
                $names[] = $n;
            }
        }
        sort($names);
        foreach ($names as $name) {
            $meta = [];
            $infoFile = $base . '/' . $name . '/info.json';
            if (is_file($infoFile)) {
                $decoded = json_decode((string) file_get_contents($infoFile), true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $themes[] = [
                'key' => $name,
                'name' => (string) ($meta['name'] ?? $name),
                'author' => (string) ($meta['author'] ?? ''),
                'description' => (string) ($meta['description'] ?? ''),
                'version' => (string) ($meta['version'] ?? self::VERSION),
                'has_preview' => is_file(PHP_BLOG_ROOT . '/themes/' . $name . '/preview.png'),
            ];
        }
        return $themes;
    }

    /** 主题是否提供 theme.css 覆盖 */
    public static function themeHasCss(?string $key = null): bool
    {
        $key = $key ?? self::activeTheme();
        return is_file(PHP_BLOG_ROOT . '/themes/' . $key . '/theme.css');
    }

    /** 主题静态资源缓存版本（theme.css / theme.js 最新 mtime） */
    public static function themeAssetVer(): int
    {
        $ver = 0;
        $dir = PHP_BLOG_ROOT . '/themes/' . self::activeTheme();
        foreach (['theme.css', 'theme.js'] as $f) {
            $p = $dir . '/' . $f;
            if (is_file($p)) {
                $ver = max($ver, (int) filemtime($p));
            }
        }
        return $ver;
    }

    /** 后台静态资源缓存版本（admin.css / admin.js 最新 mtime） */
    public static function adminAssetVer(): int
    {
        $ver = 0;
        foreach (['css/admin.css', 'js/admin.js'] as $f) {
            $p = PHP_BLOG_ROOT . '/static/' . $f;
            if (is_file($p)) {
                $ver = max($ver, (int) filemtime($p));
            }
        }
        return $ver;
    }

    /** 统计代码消毒：仅保留 <script>，剥离 on* 事件与 javascript: 协议 */
    public static function sanitizeStatsCode(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = (string) preg_replace('/\son\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string) preg_replace('/javascript\s*:/i', '', $html);
        $html = (string) preg_replace('/<(?!(script\b|\/script>)).*?>/i', '', $html);
        return trim((string) preg_replace('/\n\s*\n+/', "\n", $html));
    }

    /** 当前请求是否为后台页面 */
    public static function isAdminRequest(): bool
    {
        return str_starts_with(Request::uri(), '/admin');
    }
}
