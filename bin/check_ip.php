<?php
/**
 * 自检：Request::ip() 的反代头信任边界。
 * 仅当 REMOTE_ADDR 命中 config 的 trusted_proxies 时才采信 XFF / X-Real-IP；
 * 直连（包括回环，但未列入信任列表）一律以 REMOTE_ADDR 为准。
 * 用法：php bin/check_ip.php
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/../core/bootstrap.php';

use Blog\Request;

$cases = [
    // [trusted_proxies, REMOTE_ADDR, XFF, X-Real-IP, 期望]
    [[], '203.0.113.9', '1.2.3.4', '', '203.0.113.9'],                 // 未配置信任列表，伪造 XFF 无效
    [[], '127.0.0.1', '1.2.3.4', '', '127.0.0.1'],                     // 回环也不因私网身份被信任
    [['127.0.0.1'], '127.0.0.1', '1.2.3.4', '', '1.2.3.4'],            // 列入信任列表后才采信
    [['127.0.0.1', '192.168.1.10'], '192.168.1.10', '1.2.3.4, 10.0.0.1', '', '1.2.3.4'],
    [['::1'], '::1', '1.2.3.4', '', '1.2.3.4'],                        // IPv6 回环
    [['127.0.0.1'], '127.0.0.1', '', '5.6.7.8', '5.6.7.8'],            // XFF 缺失时用 X-Real-IP
    [['127.0.0.1'], '127.0.0.1', 'not-an-ip', '', '127.0.0.1'],        // 格式非法回退 REMOTE_ADDR
    [['127.0.0.1'], '127.0.0.1', '<script>alert(1)</script>', '', '127.0.0.1'],
    [[], '', '1.2.3.4', '', 'unknown'],                                 // 两者皆空
];

$fail = 0;
foreach ($cases as [$trusted, $remote, $xff, $real, $want]) {
    $config['trusted_proxies'] = $trusted;
    $_SERVER['REMOTE_ADDR'] = $remote;
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    $_SERVER['HTTP_X_REAL_IP'] = $real;
    $got = Request::ip();
    $ok = $got === $want;
    $fail += $ok ? 0 : 1;
    printf(
        "%s  trusted=%-22s REMOTE=%-14s XFF=%-26s REAL=%-10s => %-14s 期望 %s\n",
        $ok ? 'OK  ' : 'FAIL',
        '[' . implode(',', $trusted) . ']',
        $remote,
        $xff,
        $real,
        $got,
        $want
    );
}

echo $fail === 0 ? "\n全部通过\n" : "\n失败 $fail\n";
exit($fail === 0 ? 0 : 1);
