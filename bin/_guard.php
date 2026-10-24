<?php
/**
 * bin/ 目录的 CLI 守卫。
 *
 * 站点运行目录就是项目根，bin/ 天然落在 Web 可见范围内。一旦服务器漏配
 * 「封锁敏感目录」的规则，公网就能直接请求这里的脚本：dbcheck / schema
 * 会吐出库名与表结构，wm_refresh / project_sync / migrate / upgrade_worker
 * 更是会真实写数据、改代码。
 *
 * 典型漏配：nginx 的 `location ~ \.php$` 排在伪静态的目录规则之前，正则按
 * 出现顺序匹配，.php 请求先被 PHP 处理器接走，目录封锁形同虚设。服务器配置
 * 已改为 `location ^~ /bin/` 前缀匹配（见 README），这里再在 PHP 层兜一层：
 * 非命令行请求一律 404，与 Web 服务器的规则顺序无关。
 *
 * 允许 cli-server 是因为 `php -S ... bin/router.php` 本地开发要用。
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
