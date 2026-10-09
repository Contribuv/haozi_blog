<?php
/**
 * 自检：Request::ip() 的反代头信任边界。
 * 伪造 XFF 只应在「确实处于反代之后」（REMOTE_ADDR 为回环/私有段）时才被采信。
 * 用法：php bin/check_ip.php
 */
require __DIR__ . '/../core/bootstrap.php';

use Blog\Request;

$cases = [
    // [REMOTE_ADDR, XFF, 期望]
    ['203.0.113.9', '1.2.3.4', '203.0.113.9'],   // 公网直连，伪造 XFF 必须被忽略
    ['203.0.113.9', '', '203.0.113.9'],
    ['127.0.0.1', '1.2.3.4', '1.2.3.4'],         // 本机反代（php -S / nginx），采信 XFF
    ['192.168.1.10', '1.2.3.4, 10.0.0.1', '1.2.3.4'],
    ['127.0.0.1', 'not-an-ip', '127.0.0.1'],      // XFF 格式非法应回退 REMOTE_ADDR
    ['127.0.0.1', '<script>alert(1)</script>', '127.0.0.1'],
    ['', '1.2.3.4', 'unknown'],                    // 两者皆空
];

$fail = 0;
foreach ($cases as [$remote, $xff, $want]) {
    $_SERVER['REMOTE_ADDR'] = $remote;
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    $_SERVER['HTTP_X_REAL_IP'] = '';
    $got = Request::ip();
    $ok = $got === $want;
    $fail += $ok ? 0 : 1;
    printf("%s  REMOTE=%-14s XFF=%-26s => %-14s 期望 %s\n", $ok ? 'OK  ' : 'FAIL', $remote, $xff, $got, $want);
}

// IPv6 回环同样视为反代
$_SERVER['REMOTE_ADDR'] = '::1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
$_SERVER['HTTP_X_REAL_IP'] = '';
$got = Request::ip();
$ok = $got === '1.2.3.4';
$fail += $ok ? 0 : 1;
printf("%s  REMOTE=%-14s XFF=%-26s => %-14s 期望 %s\n", $ok ? 'OK  ' : 'FAIL', '::1', '1.2.3.4', $got, '1.2.3.4');

echo $fail === 0 ? "\n全部通过\n" : "\n失败 $fail\n";
exit($fail === 0 ? 0 : 1);