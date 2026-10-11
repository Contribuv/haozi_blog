<?php
/**
 * 升级后台执行脚本（命令行入口）
 *
 * 由后台「一键升级」（UpgradeController::run）以独立进程拉起：升级脱离 HTTP 请求，
 * PHP-FPM worker 立即释放，其它访客不再排队等待。进度仍写 data/upgrade_progress.json，
 * 前端照常轮询 /admin/upgrade/progress 显示。
 *
 * 用法：
 *   php bin/upgrade_worker.php <tag>     如 php bin/upgrade_worker.php 1.2.3
 */

declare(strict_types=1);

// 只允许命令行调用：本脚本会无鉴权地执行升级，绝不能经 HTTP 直接访问
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/../core/bootstrap.php';

use Blog\Service\Upgrade;

$tag = $argv[1] ?? '';
if ($tag === '') {
    fwrite(STDERR, "缺少版本 tag\n");
    exit(1);
}

// 后台进程无人盯着，跑多久都得撑住
@set_time_limit(0);
ignore_user_abort(true);

// doUpgrade 内部自会写 running/终态进度并维护升级锁，此处只需等它跑完
$result = Upgrade::doUpgrade($tag);
if (($result[0] ?? false) !== true) {
    fwrite(STDERR, ($result[1] ?? '升级失败') . "\n");
    exit(1);
}
exit(0);