<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * Markdown 渲染服务（等价原项目 render_post_content / render_readme_html）。
 * 支持：fenced_code / tables / nl2br / sane_lists / toc / footnotes / attr_list /
 *        def_list / admonition / md_in_html 的常用子集。
 * 返回 [html, toc_html]，toc 无标题时为 ''。
 */
class Markdown
{
    /** 渲染文章正文：Markdown → HTML + 目录 */
    public static function renderPost(string $content): array
    {
        if (trim($content) === '') {
            return ['', ''];
        }
        // 去 YAML front matter
        $content = (string) preg_replace('/^---.*?---\s*/s', '', $content);
        // 自动链接 <URL> → [URL](URL)
        $content = (string) preg_replace('/<(https?:\/\/[^>\s]+)>/', '[$1]($1)', $content);
        // 任务列表
        $content = str_replace(
            ['[x]', '[ ]'],
            ['<i class="ck ck-done">☑</i> ', '<i class="ck ck-todo">☐</i> '],
            $content
        );

        $p = new self();
        $html = $p->convert($content);
        $toc = $p->tocHtml();

        $html = self::optimizeImages($html);
        $html = self::hardenLinks($html);
        // 钩子位 post.render：插件可后处理正文 HTML 与目录
        $payload = \Blog\Hook::emit('post.render', ['html' => $html, 'toc' => $toc]);
        if (is_array($payload) && isset($payload['html']) && is_string($payload['html'])) {
            $html = $payload['html'];
            $toc = (string) ($payload['toc'] ?? $toc);
        }
        return [$html, $toc];
    }

    /** 渲染 GitHub README：把相对链接补成绝对 URL 后转为 HTML */
    public static function renderReadme(string $md, string $slug, string $branch = 'main'): string
    {
        if (trim($md) === '') {
            return '';
        }
        $p = new self();
        $html = $p->convert($md);
        // README 来自 GitHub 仓库，同属外部内容，必须和正文一样走 hardenLinks，
        // 否则恶意仓库里的 [x](javascript:...) 会在本站项目页执行
        $html = self::hardenLinks($html);
        return self::absolutizeReadme($html, $slug, $branch);
    }

    // ==================== 图片 / 链接后处理 ====================

    /** 图片懒加载 + alt 兜底 + 首图注入真实尺寸（分享爬虫） */
    private static function optimizeImages(string $html): string
    {
        $first = true;
        return (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $m) use (&$first): string {
            $tag = $m[0];
            if (stripos($tag, 'loading=') !== false) {
                return $tag;
            }
            $src = '';
            if (preg_match('/src="([^"]*)"/i', $tag, $sm)) {
                $src = $sm[1];
            }
            $alt = '';
            if (preg_match('/alt="([^"]*)"/i', $tag, $am) && $am[1] !== '') {
                $alt = $am[1];
            } else {
                $alt = $src !== '' ? basename(parse_url($src, PHP_URL_PATH) ?: '') : '';
            }
            $tag = (string) preg_replace('/\s+alt="[^"]*"/i', '', $tag);
            $isFirst = $first;
            $first = false;
            $extra = ' decoding="async"';
            if ($isFirst && str_starts_with($src, '/uploads/')) {
                $dims = Upload::imageDims(rawurldecode($src));
                if ($dims !== null) {
                    $extra .= ' width="' . $dims[0] . '" height="' . $dims[1] . '"';
                }
            } elseif (!$isFirst) {
                $extra = ' loading="lazy" decoding="async"';
            }
            return preg_replace('/<img/i', '<img' . $extra . ' alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"', $tag, 1);
        }, $html);
    }

    /** 外链新窗口打开，过滤 javascript: 伪协议，保留页内锚点 */
    private static function hardenLinks(string $html): string
    {
        return (string) preg_replace_callback('/<a\b[^>]*>/i', static function (array $m): string {
            $tag = $m[0];
            if (preg_match('/href="#/', $tag)) {
                return $tag;
            }
            if (preg_match('/href\s*=\s*["\']\s*javascript:/i', $tag)) {
                return (string) preg_replace('/href\s*=\s*["\'][^"\']*["\']/', 'href="#"', $tag, 1);
            }
            if (stripos($tag, 'target=') !== false) {
                return $tag;
            }
            return preg_replace('/<a/i', '<a target="_blank" rel="noopener noreferrer"', $tag, 1);
        }, $html);
    }

    /** README 中的相对图片/链接补成 GitHub 绝对地址 */
    private static function absolutizeReadme(string $html, string $slug, string $branch): string
    {
        if ($slug === '') {
            return $html;
        }
        $raw = 'https://raw.githubusercontent.com/' . $slug . '/' . $branch . '/';
        $blob = 'https://github.com/' . $slug . '/blob/' . $branch . '/';
        return (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $m) use ($raw): string {
            return (string) preg_replace_callback('/src="([^"]*)"/i', static function (array $s) use ($raw): string {
                $u = $s[1];
                if ($u === '' || preg_match('#^(https?:)?//#i', $u) || str_starts_with($u, 'data:') || str_starts_with($u, '/')) {
                    return $s[0];
                }
                return 'src="' . $raw . ltrim($u, './') . '"';
            }, $m[0]);
        }, (string) preg_replace_callback('/<a\b[^>]*>/i', static function (array $m) use ($blob): string {
            return (string) preg_replace_callback('/href="([^"]*)"/i', static function (array $s) use ($blob): string {
                $u = $s[1];
                if ($u === '' || preg_match('#^(https?:)?//#i', $u) || str_starts_with($u, '#') || str_starts_with($u, '/') || str_starts_with($u, 'mailto:')) {
                    return $s[0];
                }
                return 'href="' . $blob . ltrim($u, './') . '"';
            }, $m[0]);
        }, $html));
    }

    // ==================== 转换核心 ====================

    private array $toc = [];
    private array $footnotes = [];
    private array $slugCount = [];

    /** 块级解析 */
    public function convert(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $n = count($lines);
        $out = [];
        $i = 0;

        while ($i < $n) {
            $line = $lines[$i];

            // 空行
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // 围栏代码块
            if (preg_match('/^(\s*)(`{3,}|~{3,})\s*([^\s`]*)\s*$/', $line, $m)) {
                $fence = $m[2];
                $lang = $m[3];
                $i++;
                $buf = [];
                while ($i < $n && !preg_match('/^\s*' . preg_quote($fence[0], '/') . '{3,}\s*$/', $lines[$i])) {
                    $buf[] = $lines[$i];
                    $i++;
                }
                $i++; // 跳过闭合
                $code = htmlspecialchars(implode("\n", $buf), ENT_QUOTES);
                $cls = $lang !== '' ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES) . '"' : '';
                $out[] = "<pre><code{$cls}>" . $code . "</code></pre>";
                continue;
            }

            // 分隔线
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $out[] = '<hr>';
                $i++;
                continue;
            }

            // ATX 标题
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $out[] = $this->heading($level, $m[2]);
                $i++;
                continue;
            }

            // 表格：当前行含 |，下一行是分隔行
            if (str_contains($line, '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $lines[$i + 1])) {
                [$tbl, $i] = $this->table($lines, $i, $n);
                $out[] = $tbl;
                continue;
            }

            // admonition  !!! note "Title"
            if (preg_match('/^!!!\s+(\w+)(?:\s+"([^"]*)")?\s*$/', $line, $m)) {
                $buf = [];
                $i++;
                while ($i < $n && (trim($lines[$i]) === '' || preg_match('/^(\s{2,}|\t)/', $lines[$i]))) {
                    $buf[] = ltrim($lines[$i]);
                    $i++;
                }
                $title = $m[2] !== '' ? $m[2] : ucfirst($m[1]);
                $out[] = '<div class="admonition ' . htmlspecialchars($m[1], ENT_QUOTES) . '"><p class="admonition-title">'
                    . htmlspecialchars($title, ENT_QUOTES) . '</p>' . $this->convert(implode("\n", $buf)) . '</div>';
                continue;
            }

            // 引用块
            if (preg_match('/^>\s?/', $line)) {
                $buf = [];
                while ($i < $n && preg_match('/^>\s?/', $lines[$i])) {
                    $buf[] = preg_replace('/^>\s?/', '', $lines[$i]);
                    $i++;
                    if ($i < $n && trim($lines[$i]) === '' && $i + 1 < $n && preg_match('/^>/', $lines[$i + 1])) {
                        $buf[] = '';
                        $i++;
                    }
                }
                $out[] = '<blockquote>' . $this->convert(implode("\n", $buf)) . '</blockquote>';
                continue;
            }

            // 定义列表：下一行以 ": " 开头
            if ($i + 1 < $n && preg_match('/^:\s+/', $lines[$i + 1]) && !preg_match('/^[#>\-\*\d|]/', $line)) {
                [$dl, $i] = $this->defList($lines, $i, $n);
                $out[] = $dl;
                continue;
            }

            // 列表（有序 / 无序）
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line)) {
                [$list, $i] = $this->lists($lines, $i, $n);
                $out[] = $list;
                continue;
            }

            // 块级原始 HTML
            if (preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b/', $line)) {
                [$raw, $i] = $this->htmlBlock($lines, $i, $n);
                $out[] = $raw;
                continue;
            }

            // 段落（nl2br：段内换行转 <br>）
            $buf = [];
            while ($i < $n && trim($lines[$i]) !== '') {
                $l = $lines[$i];
                if (preg_match('/^(\s*)(`{3,}|~{3,})/', $l) || preg_match('/^(#{1,6})\s+/', $l)
                    || preg_match('/^>/', $l) || preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $l)
                    || preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b/', $l)
                    || preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $l)) {
                    break;
                }
                $buf[] = $l;
                $i++;
            }
            if ($buf) {
                $out[] = '<p>' . $this->inline(implode("\n", $buf)) . '</p>';
            }
        }

        return implode("\n", $out);
    }

    /** 标题 + 目录收集 */
    private function heading(int $level, string $text): string
    {
        $plain = trim(strip_tags($this->inline($text)));
        $slug = $this->slugify($plain);
        if ($level >= 2) {
            $this->toc[] = ['level' => $level, 'slug' => $slug, 'text' => $text];
        }
        return '<h' . $level . ' id="' . $slug . '">' . $this->inline($text) . '</h' . $level . '>';
    }

    /** 生成唯一标题锚点（保留中文） */
    private function slugify(string $value): string
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        $v = (string) preg_replace('/[^\p{L}\p{N}_]+/u', '-', $v);
        $v = (string) preg_replace('/-+/', '-', $v);
        $v = trim($v, '-');
        if ($v === '') {
            $v = 'section';
        }
        $base = $v;
        $k = 0;
        while (isset($this->slugCount[$v])) {
            $k++;
            $v = $base . '_' . $k;
        }
        $this->slugCount[$v] = true;
        return $v;
    }

    /** 目录 HTML：按级别嵌套 ul/li */
    private function tocHtml(): string
    {
        if (count($this->toc) === 0) {
            return '';
        }
        $html = '';
        $stack = [];
        $prev = 2;
        foreach ($this->toc as $idx => $item) {
            $lv = $item['level'];
            $li = '<li><a href="#' . $item['slug'] . '">' . $this->inline($item['text']) . '</a>';
            if ($idx === 0) {
                $html .= "<ul>\n" . $li;
                $stack = [$lv];
                $prev = $lv;
                continue;
            }
            if ($lv > $prev) {
                $html .= "\n<ul>\n" . $li;
                $stack[] = $lv;
            } elseif ($lv === $prev) {
                $html .= "</li>\n" . $li;
            } else {
                $html .= '</li>';
                while ($stack && end($stack) > $lv) {
                    array_pop($stack);
                    $html .= "\n</ul>\n</li>";
                }
                $html .= "\n" . $li;
            }
            $prev = $lv;
        }
        $html .= "</li>\n";
        while ($stack) {
            array_pop($stack);
            $html .= "</ul>\n";
        }
        return $html;
    }

    /** 表格 */
    private function table(array $lines, int $i, int $n): array
    {
        $header = $this->splitRow($lines[$i]);
        $align = [];
        foreach ($this->splitRow($lines[$i + 1]) as $cell) {
            $c = trim($cell);
            $left = str_starts_with($c, ':');
            $right = str_ends_with($c, ':');
            $align[] = ($left && $right) ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
        }
        $i += 2;
        $body = [];
        while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
            $body[] = $this->splitRow($lines[$i]);
            $i++;
        }
        $h = '<table><thead><tr>';
        foreach ($header as $k => $cell) {
            $a = $align[$k] ?? '';
            $h .= '<th' . ($a ? ' style="text-align:' . $a . '"' : '') . '>' . $this->inline(trim($cell)) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($body as $row) {
            $h .= '<tr>';
            for ($c = 0; $c < count($header); $c++) {
                $a = $align[$c] ?? '';
                $h .= '<td' . ($a ? ' style="text-align:' . $a . '"' : '') . '>' . $this->inline(trim($row[$c] ?? '')) . '</td>';
            }
            $h .= '</tr>';
        }
        return [$h . '</tbody></table>', $i];
    }

    private function splitRow(string $line): array
    {
        $line = trim($line);
        $line = (string) preg_replace('/^\||\|$/', '', $line);
        return explode('|', $line);
    }

    /** 定义列表 */
    private function defList(array $lines, int $i, int $n): array
    {
        $h = '<dl>';
        while ($i < $n) {
            if (trim($lines[$i]) === '') {
                $i++;
                break;
            }
            if (preg_match('/^:\s+(.*)$/', $lines[$i], $m)) {
                $h .= '<dd>' . $this->inline($m[1]) . '</dd>';
                $i++;
            } else {
                $h .= '<dt>' . $this->inline($lines[$i]) . '</dt>';
                $i++;
            }
            if ($i < $n && trim($lines[$i]) === '') {
                break;
            }
        }
        return [$h . '</dl>', $i];
    }

    /** 有序 / 无序列表（按缩进嵌套） */
    private function lists(array $lines, int $i, int $n): array
    {
        $ordered = (bool) preg_match('/^\s*\d+[.)]\s+/', $lines[$i]);
        $tag = $ordered ? 'ol' : 'ul';
        $html = '';
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                // 空行后若还是同类列表项则继续（sane_lists 宽松）
                if ($i + 1 < $n && preg_match('/^\s*([-*+]|\d+[.)])\s+/', $lines[$i + 1])) {
                    $i++;
                    continue;
                }
                break;
            }
            if (!preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $m)) {
                // 续行并入上一项
                if ($html !== '' && !preg_match('/^[#>]/', $line)) {
                    $html = (string) preg_replace('/<\/li>$/', ' ' . $this->inline(trim($line)) . '</li>', $html);
                    $i++;
                    continue;
                }
                break;
            }
            // 收集该项的全部行（含更深缩进的子列表）
            $itemLines = [$m[3]];
            $indent = strlen($m[1]);
            $i++;
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') {
                    break;
                }
                if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $l, $mm) && strlen($mm[1]) > $indent) {
                    break; // 子列表交给递归
                }
                if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $l)) {
                    break;
                }
                $itemLines[] = ltrim($l);
                $i++;
            }
            $content = $this->inline(implode("\n", $itemLines));
            // 紧随其后的更深缩进列表 → 子列表
            if ($i < $n && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i], $mm) && strlen($mm[1]) > $indent) {
                $sub = [];
                while ($i < $n && (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $lines[$i]) || trim($lines[$i]) === '')) {
                    if (trim($lines[$i]) === '' && !($i + 1 < $n && preg_match('/^\s*([-*+]|\d+[.)])\s+/', $lines[$i + 1]))) {
                        break;
                    }
                    $sub[] = (string) preg_replace('/^\s{2,}|\t/', '', $lines[$i], 1);
                    $i++;
                }
                $content .= $this->convert(implode("\n", $sub));
            }
            $html .= '<li>' . $content . '</li>';
        }
        return ['<' . $tag . '>' . $html . '</' . $tag . '>', $i];
    }

    /** 块级 HTML 原样透传 */
    private function htmlBlock(array $lines, int $i, int $n): array
    {
        $buf = [];
        while ($i < $n && trim($lines[$i]) !== '') {
            $buf[] = $lines[$i];
            $i++;
        }
        return [implode("\n", $buf), $i];
    }

    // ==================== 行内解析 ====================

    private array $codeSpans = [];

    public function inline(string $text): string
    {
        // 行内代码先占位，避免内部被其它规则处理
        $this->codeSpans = [];
        $text = (string) preg_replace_callback('/`([^`]+)`/', function (array $m): string {
            $key = "\x00C" . count($this->codeSpans) . "\x00";
            $this->codeSpans[$key] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES) . '</code>';
            return $key;
        }, $text);

        // 图片
        $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', static function (array $m): string {
            $title = isset($m[3]) && $m[3] !== '' ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES) . '"' : '';
            return '<img src="' . htmlspecialchars($m[2], ENT_QUOTES) . '" alt="' . htmlspecialchars($m[1], ENT_QUOTES) . '"' . $title . '>';
        }, $text);

        // 链接
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', static function (array $m): string {
            $title = isset($m[3]) && $m[3] !== '' ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES) . '"' : '';
            return '<a href="' . htmlspecialchars($m[2], ENT_QUOTES) . '"' . $title . '>' . $m[1] . '</a>';
        }, $text);

        // 脚注引用
        $text = (string) preg_replace('/\[\^([^\]]+)\]/', '<sup class="footnote-ref"><a href="#fn-$1">[$1]</a></sup>', $text);

        // 自动链接（裸 URL）
        $text = (string) preg_replace('#(?<!["\'=])(https?://[^\s<>"\']+)#', '<a href="$1">$1</a>', $text);

        // 加粗 / 斜体 / 删除线
        $text = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text);

        // nl2br
        $text = nl2br($text, false);

        // 还原行内代码
        if ($this->codeSpans) {
            $text = strtr($text, $this->codeSpans);
        }
        return $text;
    }
}
