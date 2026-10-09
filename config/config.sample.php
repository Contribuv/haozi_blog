<?php
/**
 * 配置示例文件（分发给新用户时使用的模板）。
 *
 * 安装向导会读取本文件作为默认值，安装完成后生成 config/config.php 写入真实凭据；
 * 也可手动复制本文件为 config/config.php 并填写数据库信息。
 *
 * 注意：config/config.php 不应提交到代码仓库（含真实密码）。
 */
return [
    'db' => [
        'host' => '',
        'port' => 3306,
        'dbname' => '',
        'user' => '',
        'pass' => '',
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],
    'app' => [
        'name' => 'Haozi Blog',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Shanghai',
        'base_path' => '',
    ],
    'session' => [
        'name' => 'PHPBLOG_SESSID',
        'lifetime' => 3600,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'upload' => [
        'max_size' => 20 * 1024 * 1024,
        'dir' => __DIR__ . '/../storage/uploads',
        'base_url' => '/uploads',
    ],
    'theme' => [
        'default' => 'tech',
    ],
];