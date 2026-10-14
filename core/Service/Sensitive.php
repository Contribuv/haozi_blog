<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;

/**
 * 评论敏感词过滤（前后端共用同一份词库）。
 *
 * 词库存在 settings.sensitive_words，一行一个词，`#` 开头为注释；开关为 settings.sensitive_filter_enabled。
 * 命中策略：把命中词就地替换为「等长星号」，长度不变 —— 既不影响前后端字数统计，也不会泄露词库长度。
 *
 * 前端 comments.js 拿到同一份词库做实时替换（输入即变星号）；本类在提交时再兜一层，
 * 保证禁用 JS、直连 POST 的提交同样会被过滤（前端拦截天然可绕过，绝不能只靠前端）。
 */
class Sensitive
{
    /** 词库配置键 */
    public const KEY_WORDS = 'sensitive_words';
    /** 开关配置键 */
    public const KEY_ENABLED = 'sensitive_filter_enabled';

    /** @var array<int,string>|null 词库缓存（每请求一次解析） */
    private static ?array $words = null;

    /** @var string|null 编译后的正则；空串表示「无词库/不可用」 */
    private static ?string $regex = null;

    /** 过滤是否生效（开关打开且词库非空） */
    public static function enabled(): bool
    {
        return Settings::bool(self::KEY_ENABLED, true) && self::words() !== [];
    }

    /**
     * 解析词库：按行拆分，去空行、去注释、去重。
     * @return array<int,string>
     */
    public static function words(): array
    {
        if (self::$words !== null) {
            return self::$words;
        }
        $raw = (string) Settings::get(self::KEY_WORDS, '');
        $seen = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            $w = trim($line);
            if ($w === '' || $w[0] === '#') {
                continue;
            }
            $seen[$w] = true;
        }
        return self::$words = array_keys($seen);
    }

    /**
     * 把正文里的命中词替换为等长星号。
     * 词库为空、未启用或正则编译失败时原样返回（绝不因过滤失败让整站报错）。
     */
    public static function filter(string $text): string
    {
        if ($text === '' || !self::enabled()) {
            return $text;
        }
        $re = self::regex();
        if ($re === null) {
            return $text;
        }
        $out = preg_replace_callback($re, static function (array $m): string {
            $len = mb_strlen($m[0]);
            return $len > 0 ? str_repeat('*', $len) : '***';
        }, $text);
        return $out ?? $text;
    }

    /** 是否包含命中词（供审核/提示等场景判断） */
    public static function has(string $text): bool
    {
        if ($text === '' || !self::enabled()) {
            return false;
        }
        $re = self::regex();
        return $re !== null && preg_match($re, $text) === 1;
    }

    /**
     * 注入前端的词库 JSON（未启用或为空时返回空串，前端据此跳过实时过滤）。
     * 注意：实时「输入即变星号」必须把词库下发到浏览器，词库内容对访客可见，这是该体验的固有代价。
     */
    public static function json(): string
    {
        if (!self::enabled()) {
            return '';
        }
        $json = json_encode(array_values(self::words()), JSON_UNESCAPED_UNICODE);
        return $json === false ? '' : $json;
    }

    /** 编译成单条正则：长词优先，保证最长匹配；编译失败则降级为不过滤 */
    private static function regex(): ?string
    {
        if (self::$regex !== null) {
            return self::$regex === '' ? null : self::$regex;
        }
        $words = self::words();
        if ($words === []) {
            self::$regex = '';
            return null;
        }
        // 长词排在前面：PCRE 交替是从左到右第一个匹配，长词优先可避免「短词吃掉长词前缀」
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $alts = array_map(static fn (string $w): string => preg_quote($w, '/'), $words);
        $re = '/(' . implode('|', $alts) . ')/iu';
        // 词库过大导致正则编译失败时降级为不过滤，避免整站 500
        if (@preg_match($re, '') === false) {
            self::$regex = '';
            return null;
        }
        return self::$regex = $re;
    }
}
