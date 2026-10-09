<?php
declare(strict_types=1);

/**
 * 重新生成静态错误页 static/{404,403,50x}.html。
 *
 * 换主题时 ThemeController 会自动调用同一份逻辑，正常情况下无需手动执行。
 * 手工改了 themes/<主题>/{404,403,502}.html 或 theme.css 后才需要跑这个脚本。
 * 用法：php bin/build_50x.php
 */
require_once __DIR__ . '/../core/bootstrap.php';

use Blog\Service\ErrorPages;

$errs = ErrorPages::rebuild();
if (isset($errs['error'])) {
    fwrite(STDERR, '失败：' . $errs['error'] . "\n");
    exit(1);
}
foreach ($errs as $path => $err) {
    echo "written: {$path} (" . filesize($path) . " bytes)\n";
}
