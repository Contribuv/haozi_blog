<?php
declare(strict_types=1);

/**
 * 对比 config/config.sample.php 与 config/config.php，列出新增/缺失的配置项。
 *
 * 背景：config.php 含数据库凭据、且允许大量自定义，升级永远不会覆盖它。
 * bootstrap.php 会把 config.sample.php 作为默认值做深合并，所以「缺项」不影响运行；
 * 这个脚本只是让人看清差异，必要时把新键补进 config.php（保留已有自定义值）。
 *
 * 用法：
 *   php bin/config_sync.php                 只读检查
 *   php bin/config_sync.php --write --yes   备份后合并缺失项（已有值一律优先，不覆盖）
 */

require __DIR__ . '/_guard.php';
require __DIR__ . '/../core/bootstrap.php';

$write = in_array('--write', $argv, true);
$yes = in_array('--yes', $argv, true);

$sampleFile = PHP_BLOG_ROOT . '/config/config.sample.php';
$configFile = PHP_BLOG_ROOT . '/config/config.php';

if (!is_file($configFile)) {
    fwrite(STDERR, "未找到 config/config.php，请先完成安装。\n");
    exit(1);
}
$sample = require $sampleFile;
$config = require $configFile;
if (!is_array($sample) || !is_array($config)) {
    fwrite(STDERR, "配置文件格式异常：应返回数组。\n");
    exit(1);
}

$missing = [];
$conflicts = [];
compare($sample, $config, '', $missing, $conflicts);

echo "对比 config.sample.php → config.php\n";
if (!$missing && !$conflicts) {
    echo "已对齐，无需处理。\n";
    exit(0);
}
if ($missing) {
    echo "\n缺少 " . count($missing) . " 项（不补也能运行，bootstrap 会用 sample 默认值深合并）：\n";
    foreach ($missing as $path) {
        echo '  - ' . $path . "\n";
    }
}
if ($conflicts) {
    echo "\n类型不一致 " . count($conflicts) . " 项（不会自动改，请人工确认）：\n";
    foreach ($conflicts as $path => $desc) {
        echo '  - ' . $path . '：' . $desc . "\n";
    }
}

if (!$write) {
    echo "\n只读检查完成。需要把缺失项补进 config.php（会先备份）：\n";
    echo "  php bin/config_sync.php --write --yes\n";
    exit(0);
}
if (!$yes) {
    echo "\n--write 会备份并重写 config/config.php，请加 --yes 确认。\n";
    exit(1);
}

$oldOwner = @fileowner($configFile);
$oldGroup = @filegroup($configFile);

$merged = merge($sample, $config);
$backup = $configFile . '.bak.' . date('YmdHis');
if (!@copy($configFile, $backup)) {
    fwrite(STDERR, "备份失败：{$backup}\n");
    exit(1);
}
@chmod($backup, 0600); // 备份含密码，只给当前用户读

$code = "<?php\n"
    . "/**\n"
    . " * 由 bin/config_sync.php 于 " . date('Y-m-d H:i:s') . " 合并生成。\n"
    . " * 原文件已备份为 " . basename($backup) . "。\n"
    . " * 本文件包含数据库凭据，请勿提交到代码仓库。\n"
    . " */\n"
    . 'return ' . var_export($merged, true) . ";\n";

$tmp = $configFile . '.tmp.' . getmypid();
if (@file_put_contents($tmp, $code, LOCK_EX) === false || !@rename($tmp, $configFile)) {
    @unlink($tmp);
    fwrite(STDERR, "写入失败，原 config.php 未改动。\n");
    exit(1);
}

// config/ 已被 nginx 封锁，0644 不会公网下载；但必须保证 PHP-FPM 用户可读。
// 若以 root 运行，新文件属主会变成 root，因此尽量恢复原属主/属组，权限固定 0644。
@chmod($configFile, 0644);
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    if ($oldOwner !== false) {
        @chown($configFile, $oldOwner);
    }
    if ($oldGroup !== false) {
        @chgrp($configFile, $oldGroup);
    }
    echo "提示：当前以 root 运行；已尝试恢复原属主/属组并设为 0644。若首页 500，执行：\n";
    echo "  chown www:www config/config.php && chmod 644 config/config.php\n";
}
echo "\n已合并写入 config.php（权限 0644，PHP-FPM 可读）；原文件备份：{$backup}\n";
exit(0);

/**
 * 递归比较：只记录缺失键与类型冲突，不输出任何配置值（避免密码进日志）。
 *
 * @param array<string,mixed> $sample
 * @param array<string,mixed> $config
 * @param array<int,string> $missing
 * @param array<string,string> $conflicts
 */
function compare(array $sample, array $config, string $prefix, array &$missing, array &$conflicts): void
{
    foreach ($sample as $key => $sampleValue) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (!array_key_exists($key, $config)) {
            $missing[] = $path;
            continue;
        }
        $configValue = $config[$key];
        if (is_array($sampleValue) !== is_array($configValue)) {
            $conflicts[$path] = 'sample=' . gettype($sampleValue) . ' / config=' . gettype($configValue);
            continue;
        }
        if (is_array($sampleValue)) {
            compare($sampleValue, $configValue, $path, $missing, $conflicts);
        }
    }
}

/**
 * 只补缺失键：config 中已有的值（包括自定义值和数据库凭据）一律优先。
 *
 * @param array<string,mixed> $sample
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function merge(array $sample, array $config): array
{
    foreach ($sample as $key => $sampleValue) {
        if (!array_key_exists($key, $config)) {
            $config[$key] = $sampleValue;
            continue;
        }
        if (is_array($sampleValue) && is_array($config[$key])) {
            $config[$key] = merge($sampleValue, $config[$key]);
        }
    }
    return $config;
}
