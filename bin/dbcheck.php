<?php
// 数据库连通性与表结构检查
require __DIR__ . '/../core/bootstrap.php';

try {
    $pdo = \Blog\Db::pdo();
} catch (\Throwable $e) {
    echo 'DB 连接失败: ' . $e->getMessage() . "\n";
    exit(1);
}
$db = 'infowe';
echo "连接成功: {$db}\n";
foreach ($pdo->query('SHOW TABLES') as $r) {
    $t = $r['Tables_in_' . $db] ?? reset($r);
    $sql = 'SELECT COUNT(*) c FROM `' . $t . '`';
    $n = $pdo->query($sql)->fetch()['c'];
    echo '  ' . $t . ' (' . $n . ")\n";
}