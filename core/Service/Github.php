<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;

/**
 * GitHub 仓库数据服务：解析仓库地址、调用 GitHub API 拉取实时数据。
 * 对照原项目 parse_github_repo / fetch_github_repo。
 */
class Github
{
    /** 从 GitHub 地址解析 owner/repo，失败返回 null */
    public static function parseRepo(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        if (!preg_match('~github\.com[:/]([^/\s]+)/([^/\s#?]+)~', trim($url), $m)) {
            return null;
        }
        $repo = str_replace('.git', '', $m[2]);
        return $m[1] . '/' . $repo;
    }

    /**
     * 拉取仓库实时数据，失败返回 null（由调用方回退表单手填值）。
     * 若 settings 配置了 github_token，则带认证头，可访问私有仓库并提升限额。
     * 返回键：name/description/url/stars/language/languages/topics。
     */
    public static function fetchRepo(?string $url): ?array
    {
        $slug = self::parseRepo($url);
        if ($slug === null) {
            return null;
        }
        $repo = self::apiGet('https://api.github.com/repos/' . $slug);
        if ($repo === null) {
            return null;
        }
        $langs = self::normalizeLanguages(self::apiGet('https://api.github.com/repos/' . $slug . '/languages') ?? []);
        $language = (string) ($repo['language'] ?? '');
        if ($language === '' && $langs !== []) {
            $language = (string) $langs[0][0];
        }

        return [
            'name' => (string) ($repo['name'] ?? ''),
            'description' => (string) ($repo['description'] ?? ''),
            'url' => (string) ($repo['html_url'] ?? ('https://github.com/' . $slug)),
            'stars' => (int) ($repo['stargazers_count'] ?? 0),
            'language' => $language,
            'languages' => $langs,
            'topics' => is_array($repo['topics'] ?? null) ? $repo['topics'] : [],
        ];
    }

    /**
     * 把 GitHub /languages 返回的 map（语言名 => 字节数）归一化为 [[语言名, 百分比], ...]。
     * 百分比按字节占比四舍五入到 1 位小数，并按字节数降序；非 map（空/列表）输入返回空数组。
     * 对照原项目 fetch_github_repo 的 languages 处理。
     */
    public static function normalizeLanguages(array $map): array
    {
        if ($map === [] || array_is_list($map)) {
            return [];
        }
        $total = 0;
        foreach ($map as $bytes) {
            $total += (int) $bytes;
        }
        if ($total <= 0) {
            return [];
        }
        $list = [];
        foreach ($map as $name => $bytes) {
            $list[] = [(string) $name, round((int) $bytes * 100 / $total, 1)];
        }
        usort($list, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        return $list;
    }

    /** 发起 GitHub API GET 请求，返回解析后的数组或 null */
    private static function apiGet(string $url): ?array
    {
        $ch = curl_init($url);
        if ($ch !== false) {
            Http::applyCurl($ch); // 注入 CA 证书，Windows 下 curl 默认不带 CA 包
        }
        if ($ch === false) {
            return null;
        }
        $headers = ['User-Agent: infowe-blog', 'Accept: application/vnd.github+json'];
        $token = trim((string) Settings::get('github_token', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 拉取项目完整快照：GitHub 元数据 + README（渲染为 HTML），
     * 返回 ['gh','readme','branch','ts','error']，供后台「同步」写入 ProjectCache。
     */
    public static function fetchProject(?string $url): array
    {
        $slug = self::parseRepo($url);
        if ($slug === null) {
            return ['gh' => null, 'readme' => null, 'branch' => '', 'ts' => 0, 'error' => '该项目未关联 GitHub 仓库'];
        }
        $repo = self::apiGet('https://api.github.com/repos/' . $slug);
        if ($repo === null) {
            return ['gh' => null, 'readme' => null, 'branch' => '', 'ts' => 0, 'error' => 'GitHub 接口请求失败（可能限流）'];
        }
        $langs = self::normalizeLanguages(self::apiGet('https://api.github.com/repos/' . $slug . '/languages') ?? []);
        $branch = (string) ($repo['default_branch'] ?? 'main');
        $language = (string) ($repo['language'] ?? '');
        if ($language === '' && $langs !== []) {
            $language = (string) $langs[0][0];
        }

        $gh = [
            'name' => (string) ($repo['name'] ?? ''),
            'description' => (string) ($repo['description'] ?? ''),
            'url' => (string) ($repo['html_url'] ?? ('https://github.com/' . $slug)),
            'stars' => (int) ($repo['stargazers_count'] ?? 0),
            'language' => $language,
            'languages' => $langs,
            'topics' => is_array($repo['topics'] ?? null) ? $repo['topics'] : [],
            'default_branch' => $branch,
            'pushed_at' => (string) ($repo['pushed_at'] ?? ''),
            'slug' => $slug,
        ];

        $md = self::fetchReadme($slug, $branch);
        $html = $md !== null ? Markdown::renderReadme($md, $slug, $branch) : null;
        // 图片本地化：同仓库 raw 图片下载到 uploads/projects/<slug>/readme/，前台零 GitHub 请求
        if ($html !== null && $html !== '') {
            $html = self::localizeReadmeImages($html, $slug);
        }

        return [
            'gh' => $gh,
            'readme' => $html,
            'branch' => $branch,
            'ts' => time(),
            'error' => $html === null ? 'README 拉取失败' : '',
        ];
    }

    /** README 单图上限：8MB，防超大图拖慢同步/占满磁盘 */
    private const README_IMG_MAX = 8388608;

    /** README 图片扩展名白名单 */
    private const README_IMG_EXT = ['.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.bmp', '.ico'];

    /**
     * 把 README 中指向同仓库 raw.githubusercontent.com 的图片下载到本地并替换 src。
     * 仅管理员同步时调用；下载失败保留原地址。返回替换后的 HTML。
     */
    public static function localizeReadmeImages(string $html, string $slug): string
    {
        if ($html === '' || $slug === '') {
            return $html;
        }
        $pattern = '#(<img\b[^>]*\bsrc=")(https://raw\.githubusercontent\.com/' . preg_quote($slug, '#') . '/[^"]*)(")#i';
        if (!preg_match_all($pattern, $html, $m, PREG_SET_ORDER)) {
            return $html;
        }
        $urls = [];
        foreach ($m as $row) {
            $urls[$row[2]] = true;
        }
        $filenameMap = [];
        foreach (array_keys($urls) as $u) {
            $fn = self::downloadReadmeImage((string) $u, $slug);
            if ($fn !== null) {
                $filenameMap[(string) $u] = $fn;
            }
        }
        $newHtml = (string) preg_replace_callback($pattern, static function (array $row) use ($filenameMap): string {
            $fn = $filenameMap[$row[2]] ?? null;
            if ($fn === null) {
                return $row[0];
            }
            return $row[1] . '/uploads/' . $fn . $row[3];
        }, $html);

        if ($newHtml !== $html) {
            self::pruneReadmeImages($slug, $newHtml);
        }
        return $newHtml;
    }

    /** 下载单个 README 图片，返回 'projects/<slug>/readme/<name>'；失败返回 null */
    private static function downloadReadmeImage(string $url, string $slug): ?string
    {
        $data = self::binGet($url, 15);
        if ($data === null) {
            // raw 域名不通时回退 GitHub API 下载（计入配额，Token 下配额充足）
            $alt = self::rawUrlToApi($url, $slug);
            if ($alt !== null) {
                $data = self::binGet($alt, 15, 'application/vnd.github.raw', true);
            }
        }
        if ($data === null || strlen($data) === 0 || strlen($data) > self::README_IMG_MAX) {
            return null;
        }
        $name = md5($url) . self::guessImageExt($data, $url);
        $dir = Upload::root() . '/projects/' . $slug . '/readme';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return null;
        }
        if (@file_put_contents($dir . '/' . $name, $data) === false) {
            return null;
        }
        return 'projects/' . $slug . '/readme/' . $name;
    }

    /** 清理 readme 图片目录中已不被新 HTML 引用的旧文件 */
    private static function pruneReadmeImages(string $slug, string $newHtml): void
    {
        $dir = Upload::root() . '/projects/' . $slug . '/readme';
        if (!is_dir($dir)) {
            return;
        }
        $kept = [];
        if (preg_match_all('#/uploads/projects/' . preg_quote($slug, '#') . '/readme/([A-Za-z0-9._-]+)#', $newHtml, $m)) {
            $kept = array_flip($m[1]);
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $p = $dir . '/' . $name;
            if (is_file($p) && !isset($kept[$name])) {
                @unlink($p);
            }
        }
    }

    /** raw.githubusercontent.com/{slug}/{branch}/{path} → api.github.com 下载地址 */
    private static function rawUrlToApi(string $url, string $slug): ?string
    {
        $re = '#^https://raw\.githubusercontent\.com/' . preg_quote($slug, '#') . '/([^/]+)/(.+)$#';
        if (!preg_match($re, $url, $m)) {
            return null;
        }
        $branch = $m[1];
        $path = $m[2];
        $pathQ = implode('/', array_map('rawurlencode', explode('/', $path)));
        return 'https://api.github.com/repos/' . $slug . '/contents/' . $pathQ . '?ref=' . $branch;
    }

    /** 扩展名：URL 后缀优先，其次按文件魔数识别，均无法识别则留空 */
    private static function guessImageExt(string $data, string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array('.' . $ext, self::README_IMG_EXT, true)) {
            return '.' . $ext;
        }
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return '.png';
        }
        if (in_array(substr($data, 0, 6), ["GIF87a", "GIF89a"], true)) {
            return '.gif';
        }
        if (str_starts_with($data, "\xff\xd8")) {
            return '.jpg';
        }
        if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return '.webp';
        }
        if (in_array(substr($data, 0, 5), ['<svg ', '<?xml'], true)) {
            return '.svg';
        }
        return '';
    }

    /** 下载二进制内容（带 UA / 可选 Accept / Bearer）；失败或超限返回 null */
    private static function binGet(string $url, int $timeout, ?string $accept = null, bool $auth = false): ?string
    {
        $ch = curl_init($url);
        if ($ch !== false) {
            Http::applyCurl($ch); // 注入 CA 证书，Windows 下 curl 默认不带 CA 包
        }
        if ($ch === false) {
            return null;
        }
        $headers = ['User-Agent: infowe-Blog'];
        if ($accept !== null) {
            $headers[] = 'Accept: ' . $accept;
        }
        if ($auth) {
            $token = trim((string) Settings::get('github_token', ''));
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXFILESIZE => self::README_IMG_MAX,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        return (string) $body;
    }

    /** 拉取 README 原文（Markdown）；失败返回 null */
    public static function fetchReadme(string $slug, string $branch): ?string
    {
        $url = 'https://raw.githubusercontent.com/' . $slug . '/' . $branch . '/README.md';
        $body = self::textGet($url);
        if ($body !== null) {
            return $body;
        }
        foreach (['README_EN.md', 'readme.md', 'Readme.md'] as $alt) {
            $body = self::textGet('https://raw.githubusercontent.com/' . $slug . '/' . $branch . '/' . $alt);
            if ($body !== null) {
                return $body;
            }
        }
        // 回退 GitHub API readme 接口
        $json = self::apiGet('https://api.github.com/repos/' . $slug . '/readme');
        if ($json !== null && !empty($json['content'])) {
            $decoded = base64_decode((string) $json['content'], true);
            return $decoded === false ? null : $decoded;
        }
        return null;
    }

    /** 简单文本 GET */
    private static function textGet(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch !== false) {
            Http::applyCurl($ch); // 注入 CA 证书，Windows 下 curl 默认不带 CA 包
        }
        if ($ch === false) {
            return null;
        }
        $headers = ['User-Agent: infowe-blog'];
        $token = trim((string) Settings::get('github_token', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $text = (string) $body;
        return trim($text) === '' ? null : $text;
    }
}
