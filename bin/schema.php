<?php
// 临时：核对 projects 表字段差异（MySQL vs 原 SQLite）
require __DIR__ . '/../core/bootstrap.php';
$pdo = \Blog\Db::pdo();
echo "--- MySQL projects ---\n";
foreach ($pdo->query('SHOW COLUMNS FROM projects') as $c) {
    echo $c['Field'] . "\n";
}
$sqlite = null;
foreach (glob(PHP_BLOG_ROOT . '/*/*.db') as $f) { $sqlite = $f; }
foreach (glob(PHP_BLOG_ROOT . '/*.db') as $f) { $sqlite = $f; }
if ($sqlite) {
    echo "--- SQLite: {$sqlite} ---\n";
    $s = new PDO('sqlite:' . $sqlite);
    foreach ($s->query("PRAGMA table_info(projects)") as $c) {
        echo $c['name'] . "\n";
    }
} else {
    echo "未找到 SQLite 文件\n";
}
