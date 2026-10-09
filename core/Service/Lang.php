<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * GitHub 语言调色板（对照原项目 LANGUAGE_COLORS / lang_color）。
 */
class Lang
{
    public const COLORS = [
        'JavaScript' => '#f1e05a', 'TypeScript' => '#3178c6', 'Python' => '#3572A5',
        'Go' => '#00ADD8', 'Rust' => '#dea584', 'Java' => '#b07219', 'C' => '#555555',
        'C++' => '#f34b7d', 'C#' => '#178600', 'HTML' => '#e34c26', 'CSS' => '#563d7c',
        'Shell' => '#89e051', 'PowerShell' => '#012A60', 'Vue' => '#41b883', 'Ruby' => '#701516',
        'PHP' => '#4F5D95', 'Swift' => '#F05138', 'Kotlin' => '#A97BFF', 'Dart' => '#00B4AB',
        'Lua' => '#000080', 'Dockerfile' => '#384d54', 'Makefile' => '#427819', 'R' => '#198CE7',
        'Objective-C' => '#438eff', 'Scala' => '#c22d40', 'Perl' => '#0298c3', 'Haskell' => '#5e5086',
        'Elixir' => '#6e4a7e', 'Clojure' => '#db5855', 'Racket' => '#3c5caa', 'Assembly' => '#6E4C13',
        'Zig' => '#ec915c', 'Nix' => '#7e7eff', 'YAML' => '#cb171e', 'JSON' => '#292929',
    ];

    public static function color(string $name): string
    {
        return self::COLORS[$name] ?? '#8b949e';
    }

    /**
     * 把项目 languages 字段（JSON）整理为 [[name, pct, color], ...] 并补 lang_names。
     * 兼容旧数据：languages 为空但 language 有值时按单语言 100%。
     */
    public static function decorate(array $project): array
    {
        $langs = [];
        $raw = (string) ($project['languages'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $langs = $decoded;
            }
        }
        if (!$langs && !empty($project['language'])) {
            $langs = [[$project['language'], 100]];
        }
        $list = [];
        foreach ($langs as $item) {
            if (is_array($item)) {
                $name = (string) ($item[0] ?? '');
                $pct = (float) ($item[1] ?? 0);
            } else {
                $name = (string) $item;
                $pct = 100.0;
            }
            if ($name === '') {
                continue;
            }
            $list[] = [$name, $pct, self::color($name)];
        }
        $project['lang_list'] = $list;
        $project['lang_names'] = implode(',', array_map(static fn (array $l): string => $l[0], $list));
        return $project;
    }

    /** 统计语言出现次数（供项目列表页筛选） */
    public static function tally(array $projects): array
    {
        $counts = [];
        foreach ($projects as $p) {
            foreach ($p['lang_list'] ?? [] as $l) {
                $counts[$l[0]] = ($counts[$l[0]] ?? 0) + 1;
            }
        }
        return $counts;
    }
}
