<?php
declare(strict_types=1);

/**
 * 项目 GitHub 后台同步工作进程（由 ProjectSync::start 派生）。
 * 用法：php bin/project_sync.php <all|id>
 */

require __DIR__ . '/../core/bootstrap.php';

use Blog\Service\ProjectSync;

$arg = $argv[1] ?? 'all';
$singleId = ($arg === 'all') ? null : (int) $arg;

ProjectSync::runWorker($singleId);
echo "sync done\n";