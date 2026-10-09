<?php
declare(strict_types=1);

namespace Blog;

/**
 * 插件加载器：扫描 plugins/<name>/plugin.json，require 入口 Plugin.php。
 *
 * 入口文件顶层代码自行调用 Hook::on() 注册钩子即可；
 * 也可让文件 return 一个 callable，由本加载器执行（便于闭包式插件）。
 * plugin.json 字段：name（唯一标识）、title、version、author、enabled（false 则跳过）。
 */
class Plugin
{
    /** @var list<string> 已加载插件名 */
    private static array $loaded = [];

    public static function dir(): string
    {
        return PHP_BLOG_ROOT . '/plugins';
    }

    /** 加载全部启用插件（bootstrap 阶段调用一次） */
    public static function loadAll(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*/plugin.json') ?: [];
        sort($files);
        foreach ($files as $jsonFile) {
            $meta = json_decode((string) @file_get_contents($jsonFile), true);
            if (!is_array($meta) || empty($meta['name'])) {
                continue;
            }
            if (array_key_exists('enabled', $meta) && !$meta['enabled']) {
                continue;
            }
            $entry = dirname($jsonFile) . '/Plugin.php';
            if (!is_file($entry)) {
                continue;
            }
            $ret = require_once $entry;
            if (is_callable($ret)) {
                $ret();
            }
            self::$loaded[] = (string) $meta['name'];
        }
    }

    /** 已加载插件名列表 */
    public static function loaded(): array
    {
        return self::$loaded;
    }
}