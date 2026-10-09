<?php
/**
 * SQLite → MySQL 数据迁移脚本（命令行入口）
 *
 * 实际逻辑在 core/Service/SqliteImport.php，与后台「数据管理 → SQLite 导入」共用同一份实现。
 *
 * 用法：
 *   php bin\migrate.php                          从 data/blog.db 全量迁移
 *   php bin\migrate.php --source=<路径>          指定源库（如后台备份的 blog_backup_*.db）
 *   php bin\migrate.php --dry-run                只体检、不写库
 *   php bin\migrate.php --keep-users             不导入 users，保留当前账号密码
 *
 * 幂等策略：先 TRUNCATE 目标表再全量重插，重复运行数据不翻倍。
 *
 * 注意：默认会把 users 一并搬过来，登录密码随之变为源库（原 Python 版）的密码。
 *       PHP 端支持校验 werkzeug 的 pbkdf2 哈希，登录成功后会懒迁移为 bcrypt，
 *       所以原密码可直接登录。若当前 MySQL 已改过密码且不想被覆盖，加 --keep-users。
 */

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

use Blog\Service\SqliteImport;

$opts = getopt('', ['source::', 'dry-run', 'keep-users']);
$cfg = require __DIR__ . '/../config/config.php';
$sourceOpt = $opts['source'] ?? null;
$sqlitePath = is_string($sourceOpt) && $sourceOpt !== ''
    ? $sourceOpt
    : __DIR__ . '/../../data/blog.db'; // 源 SQLite 库
$dryRun = array_key_exists('dry-run', $opts);
$keepUsers = array_key_exists('keep-users', $opts);

echo "源库：{$sqlitePath}\n";
echo '目标库：' . $cfg['db']['host'] . '/' . $cfg['db']['dbname']
    . ($dryRun ? "（dry-run，不写入）\n" : "\n");

// ── 预检：文件必须是可用的 SQLite 库且表齐全，不合法则完全不碰目标库 ──
$info = SqliteImport::inspect($sqlitePath);
if (!$info['ok']) {
    fwrite(STDERR, '源库校验失败：' . $info['error'] . "\n");
    exit(1);
}

if ($dryRun) {
    echo "== 预检通过，将导入 ==\n";
    foreach ($info['tables'] as $table => $n) {
        $note = ($keepUsers && $table === 'users') ? '（--keep-users 将跳过）' : '';
        echo "  {$table}: {$n} 行{$note}\n";
    }
    echo "dry-run 结束：未写入任何数据\n";
    exit(0);
}

// ── 执行导入 ──
echo "== 开始导入 ==\n";
try {
    $r = SqliteImport::run($sqlitePath, $keepUsers);
} catch (\Throwable $e) {
    fwrite(STDERR, '导入失败：' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($r['tables'] as $table => $n) {
    echo "  {$table}: {$n} 行\n";
}
if ($keepUsers) {
    echo "  users: 跳过（--keep-users，保留当前账号密码）\n";
}
echo "  补入缺失默认键 {$r['added']} 个\n";

// ── 行数对比 ──
echo "== 行数对比 ==\n";
foreach ($r['verify'] as $table => [$s, $d]) {
    printf("  %-15s SQLite=%-6d MySQL=%-6d %s\n", $table, $s, $d, $s === $d ? 'OK' : '不一致!');
}
if ($keepUsers) {
    echo "  users: 跳过对比（--keep-users）\n";
}

if ($r['failed']) {
    fwrite(STDERR, "迁移失败：存在行数差异\n");
    exit(1);
}
echo "迁移成功\n";
exit(0);
