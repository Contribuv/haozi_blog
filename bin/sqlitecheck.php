<?php
// 临时：对比原 SQLite 表结构与 MySQL
require __DIR__ . '/_guard.php';
$s = new PDO('sqlite:d:/blog/data/blog.db');
foreach (['projects', 'timeline', 'memories', 'settings', 'comments', 'service_checks', 'links', 'categories', 'posts', 'users'] as $t) {
    $cols = [];
    foreach ($s->query("PRAGMA table_info({$t})") as $c) {
        $cols[] = $c['name'];
    }
    echo $t . ': ' . implode(' ', $cols) . "\n";
}
