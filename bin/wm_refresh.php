<?php
declare(strict_types=1);

/**
 * 历史图片水印批量重做（由运维 / cron 手动触发）。
 * 用法：php bin/wm_refresh.php [文本] [位置 br|bl|tr|tl|bc] [档位 s|m|l]
 * 不带参数时读取后台当前水印配置（对照原项目 watermark_backfill.py）。
 */

require __DIR__ . '/../core/bootstrap.php';

use Blog\Service\Watermark;

$text = $argv[1] ?? Watermark::text();
$pos = $argv[2] ?? Watermark::position();
$size = $argv[3] ?? Watermark::size();

if ($text === '') {
    fwrite(STDERR, "水印文本为空（后台亦未配置站点名），已中止\n");
    exit(1);
}

[$done, $errs] = Watermark::redoAll($text, $pos, $size);
echo "水印批量刷新完成：{$done} 张，失败 {$errs}\n";