<?php
namespace Blog;

use PDO;
use PDOException;
use Blog\Service\ErrorPage;

class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $cfg = require CONFIG_FILE;
        $db = $cfg['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) ($db['port'] ?? 3306),
            $db['dbname'],
            $db['charset'] ?? 'utf8mb4'
        );
        try {
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], $db['options']);
            return self::$pdo;
        } catch (PDOException $e) {
            error_log('[blog] DB error: ' . $e->getMessage());
            ErrorPage::render(500);
        }
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}