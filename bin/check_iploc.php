<?php
/**
 * IP 归属地自检：对照原项目 _check_iploc.py 的用例，验证 IpLocation::of 返回值。
 * 用法：D:\php83\php.exe bin\check_iploc.php
 */
declare(strict_types=1);

define('PHP_BLOG_SKIP_PLUGINS', 1);
require __DIR__ . '/../core/bootstrap.php';

use Blog\Service\IpLocation;

$cases = [
    '220.192.40.209' => '重庆',
    '27.10.42.104' => '重庆',
    '125.86.28.164' => '重庆',
    '27.10.69.166' => '重庆',
    '106.37.143.22' => '北京',
    '14.117.243.23' => '广东 江门',
    '8.8.8.8' => 'United States',
    '127.0.0.1' => '',
    '192.168.1.1' => '',
    'not-an-ip' => '',
    '::ffff:192.168.1.1' => '',
    '::ffff:1.2.3.4' => 'Australia',
    '2400:3200::1' => '浙江 杭州',
    '2606:4700:4700::1111' => 'United Kingdom',
];

$fail = 0;
foreach ($cases as $ip => $want) {
    $t0 = microtime(true);
    $got = IpLocation::of((string) $ip);
    $ms = (microtime(true) - $t0) * 1000;
    $ok = $want === '' ? $got === '' : str_starts_with($got, $want);
    if (!$ok) {
        $fail++;
    }
    printf(
        "%s %-24s -> %-22s 期望 %-16s (%.2fms)\n",
        $ok ? 'OK  ' : 'FAIL',
        $ip,
        "'" . $got . "'",
        "'" . $want . "'",
        $ms
    );
}
printf("\n失败 %d / %d\n", $fail, count($cases));
exit($fail ? 1 : 0);