<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Db;
use Blog\Model\Settings;

/**
 * 服务状态服务：云资源到期信息 + HTTP(S) 服务探测与可用率。
 * 对照原项目 get_expiry_info / probe_url / probe_all_services / get_services_status。
 */
class Status
{
    /** 探测间隔（秒） */
    public const MONITOR_INTERVAL = 300;

    /** 云资源到期信息（只读持久化的 API 结果，其次手动填写） */
    public static function getExpiryInfo(): array
    {
        $aliyunType = strtolower(trim((string) Settings::get('aliyun_server_type', 'swas')));
        $result = [];
        foreach ([
            ['aliyun', $aliyunType === 'ecs' ? '阿里云 ECS 服务器' : '阿里云轻量服务器'],
            ['tencent', '腾讯云域名'],
        ] as [$key, $label]) {
            $expiry = null;
            $source = 'manual';
            $note = '手动填写';
            $api = self::normalizeExpiry((string) Settings::get($key . '_expiry_api', ''));
            if ($api !== null) {
                $expiry = $api;
                $source = 'api';
                $tsVal = trim((string) Settings::get($key . '_expiry_api_ts', ''));
                $note = $tsVal !== '' ? 'API 同步（测试于 ' . self::fmtTs($tsVal) . '）' : 'API 自动同步';
            }
            if ($expiry === null) {
                $manual = self::normalizeExpiry((string) Settings::get('expiry_' . $key, ''));
                if ($manual !== null) {
                    $expiry = $manual;
                    $source = 'manual';
                    $note = '手动填写';
                } else {
                    $note = '未配置';
                }
            }
            $result[$key] = self::entry($label, $expiry, $source, $note,
                $key === 'aliyun' ? ($aliyunType === 'ecs' ? '云服务器 ECS' : '轻量应用服务器') : null);
        }
        return $result;
    }

    /** 组装单条到期信息（统一计算剩余天数与状态） */
    private static function entry(string $label, ?string $expiry, string $source, string $note, ?string $serverType): array
    {
        $days = null;
        $status = 'none';
        if ($expiry !== null) {
            $d = \DateTime::createFromFormat('Y-m-d', $expiry);
            if ($d !== false) {
                $days = (int) floor(($d->getTimestamp() - strtotime(date('Y-m-d'))) / 86400);
                $status = $days > 30 ? 'ok' : ($days > 0 ? 'warn' : 'expired');
            }
        }
        return compact('label', 'expiry', 'days', 'status', 'source', 'note') + ['server_type' => $serverType];
    }

    /**
     * 强制实时查询云 API（后台「同步」按钮用），结果仅返回预览、不落库。
     * 对照原项目 get_expiry_info(force=True)。
     */
    public static function getExpiryInfoForce(): array
    {
        $aliyunType = strtolower(trim((string) Settings::get('aliyun_server_type', 'swas')));
        $result = [];
        foreach ([['aliyun', $aliyunType === 'ecs' ? '阿里云 ECS 服务器' : '阿里云轻量服务器'], ['tencent', '腾讯云域名']] as [$key, $label]) {
            try {
                [$date, $err] = $key === 'aliyun' ? self::aliyunExpiry() : self::tencentExpiry();
            } catch (\Throwable $e) {
                $date = null;
                $err = 'API 查询异常：' . $e->getMessage();
            }
            if ($date !== null) {
                $result[$key] = self::entry($label, $date, 'api', 'API 自动同步',
                    $key === 'aliyun' ? ($aliyunType === 'ecs' ? '云服务器 ECS' : '轻量应用服务器') : null);
            } else {
                $result[$key] = self::entry($label, null, 'manual', $err ?: '未查询到到期时间',
                    $key === 'aliyun' ? ($aliyunType === 'ecs' ? '云服务器 ECS' : '轻量应用服务器') : null);
            }
        }
        return $result;
    }

    /** 阿里云 RPC 风格 HMAC-SHA1 签名查询实例到期时间，返回 [日期|null, 错误|null] */
    private static function aliyunExpiry(): array
    {
        $ak = trim((string) Settings::get('aliyun_access_key', ''));
        $sk = trim((string) Settings::get('aliyun_access_secret', ''));
        $region = trim((string) Settings::get('aliyun_region', 'cn-hangzhou')) ?: 'cn-hangzhou';
        if ($ak === '' || $sk === '') {
            return [null, '未配置阿里云 AccessKey'];
        }
        $isEcs = strtolower(trim((string) Settings::get('aliyun_server_type', 'swas'))) === 'ecs';
        $host = ($isEcs ? 'ecs.' : 'swas.') . $region . '.aliyuncs.com';
        $params = [
            'AccessKeyId' => $ak,
            'Format' => 'JSON',
            'RegionId' => $region,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => self::uuid(),
            'SignatureVersion' => '1.0',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'PageSize' => '100',
            'Action' => $isEcs ? 'DescribeInstances' : 'ListInstances',
            'Version' => $isEcs ? '2014-05-26' : '2020-06-01',
        ];
        $instanceId = trim((string) Settings::get('aliyun_instance_id', ''));
        if ($instanceId !== '') {
            $params['InstanceIds'] = json_encode([$instanceId]);
        }
        $do = static function (array $p) use ($sk, $host): ?array {
            ksort($p);
            $pairs = [];
            foreach ($p as $k => $v) {
                $pairs[] = $k . '=' . rawurlencode((string) $v);
            }
            $stringToSign = 'GET&%2F&' . rawurlencode(implode('&', $pairs));
            $p['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $sk . '&', true));
            return self::httpGetJson('https://' . $host . '/?' . http_build_query($p));
        };
        try {
            $data = $do($params);
        } catch (\Throwable $e) {
            return [null, '请求阿里云 API 失败（' . mb_substr($e->getMessage(), 0, 100) . '）'];
        }
        if ($data === null) {
            return [null, '请求阿里云 API 失败'];
        }
        if (isset($data['Code'])) {
            return [null, ($data['Code'] ?? '') . ': ' . ($data['Message'] ?? '')];
        }
        $instances = $isEcs ? ($data['Instances']['Instance'] ?? []) : ($data['Instances'] ?? []);
        // 按实例 ID 过滤未命中时，去掉过滤条件全量重查一次（可能是 ID 填写有误）
        if (!$instances && $instanceId !== '') {
            unset($params['InstanceIds']);
            $params['SignatureNonce'] = self::uuid();
            $params['Timestamp'] = gmdate('Y-m-d\TH:i:s\Z');
            try {
                $d2 = $do($params);
                if ($d2 !== null && !isset($d2['Code'])) {
                    $instances = $isEcs ? ($d2['Instances']['Instance'] ?? []) : ($d2['Instances'] ?? []);
                }
            } catch (\Throwable) {
                $instances = [];
            }
        }
        if (!$instances) {
            return [null, '当前地域 ' . $region . ' 未查询到' . ($isEcs ? 'ECS' : '轻量') . '实例，请检查地域（RegionId）或实例 ID 配置'];
        }
        $charge = 'PrePaid';
        foreach ($instances as $inst) {
            $charge = (string) ($inst['InstanceChargeType'] ?? $charge);
            $e = self::normalizeExpiry((string) ($inst['ExpiredTime'] ?? ''));
            if ($e !== null) {
                return [$e, null];
            }
        }
        if ($isEcs && $charge === 'PostPaid') {
            return [null, '查询到 ECS 实例，但为按量付费（PostPaid）实例，无到期时间；到期提醒仅适用于包年包月实例'];
        }
        return [null, '实例未返回到期时间（ExpiredTime 字段）'];
    }

    /** 腾讯云 TC3-HMAC-SHA256 签名查询域名到期时间，返回 [日期|null, 错误|null] */
    private static function tencentExpiry(): array
    {
        $sid = trim((string) Settings::get('tencent_secret_id', ''));
        $sk = trim((string) Settings::get('tencent_secret_key', ''));
        $domain = trim((string) Settings::get('tencent_domain', ''));
        if ($sid === '' || $sk === '') {
            return [null, '未配置腾讯云 SecretId/SecretKey'];
        }
        if ($domain === '') {
            return [null, '请在后台填写腾讯云域名（如 example.com）'];
        }
        $host = 'domain.tencentcloudapi.com';
        $service = 'domain';
        $ts = time();
        $date = gmdate('Y-m-d', $ts);
        $canonicalQuery = 'Domain=' . rawurlencode($domain);
        $hashedPayload = hash('sha256', '');
        $canonicalHeaders = "content-type:application/x-www-form-urlencoded\nhost:" . $host . "\n";
        $signedHeaders = 'content-type;host';
        $canonicalRequest = "GET\n/\n" . $canonicalQuery . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $hashedPayload;
        $stringToSign = "TC3-HMAC-SHA256\n" . $ts . "\n" . $date . '/' . $service . "/tc3_request\n" . hash('sha256', $canonicalRequest);
        // 第一层签名密钥必须加 'TC3' 前缀（腾讯云官方 SDK 同款算法）
        $secretDate = hash_hmac('sha256', $date, 'TC3' . $sk, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
        $auth = 'TC3-HMAC-SHA256 Credential=' . $sid . '/' . $date . '/' . $service . '/tc3_request, SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        try {
            $data = self::httpGetJson('https://' . $host . '/?' . $canonicalQuery, [
                'Authorization: ' . $auth,
                'Content-Type: application/x-www-form-urlencoded',
                'Host: ' . $host,
                'X-TC-Action: DescribeDomainBaseInfo',
                'X-TC-Timestamp: ' . $ts,
                'X-TC-Version: 2018-08-08',
            ]);
        } catch (\Throwable $e) {
            return [null, '请求腾讯云 API 失败（' . mb_substr($e->getMessage(), 0, 100) . '）'];
        }
        if ($data === null) {
            return [null, '请求腾讯云 API 失败'];
        }
        $err = $data['Response']['Error'] ?? null;
        if (is_array($err)) {
            $code = (string) ($err['Code'] ?? '');
            if ($code === 'AuthFailure.SignatureFailure') {
                return [null, '签名校验失败：请确认 SecretId 与 SecretKey 为同一对密钥'];
            }
            return [null, $code . ': ' . (string) ($err['Message'] ?? '')];
        }
        $info = $data['Response']['DomainInfo'] ?? [];
        $e = self::normalizeExpiry((string) ($info['ExpirationDate'] ?? ''));
        if ($e === null) {
            return [null, '未查询到域名到期时间（ExpirationDate 字段）'];
        }
        return [$e, null];
    }

    /** 发起 GET 请求并解析 JSON（复用 Http::getJson） */
    private static function httpGetJson(string $url, array $headers = []): ?array
    {
        return Http::getJson($url, $headers);
    }

    /** 生成 v4 UUID（SignatureNonce 用） */
    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /** 归一到期时间为 YYYY-MM-DD */
    public static function normalizeExpiry(string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        if (ctype_digit(ltrim($s, '-'))) {
            $ts = (int) $s;
            if ($ts > 1e12) {
                $ts = intdiv($ts, 1000);
            }
            return gmdate('Y-m-d', $ts);
        }
        $s2 = str_replace('Z', '+00:00', $s);
        try {
            $dt = new \DateTimeImmutable($s2);
            return $dt->format('Y-m-d');
        } catch (\Exception) {
            // 继续尝试常见格式
        }
        foreach (['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $s);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }

    private static function fmtTs(string $ts): string
    {
        if ($ts === '') {
            return '';
        }
        if (is_numeric($ts) && (int) $ts > 1e12) {
            $ts = (string) intdiv((int) $ts, 1000);
        }
        $v = is_numeric($ts) ? (int) $ts : (int) strtotime($ts);
        return $v > 0 ? date('Y-m-d H:i', $v) : '';
    }

    /** 解析 monitor_services 配置，返回 [{name,url}] */
    public static function monitorServices(): array
    {
        $raw = (string) Settings::get('monitor_services', '[]');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $s) {
            if (!is_array($s)) {
                continue;
            }
            $u = self::cleanMonitorUrl((string) ($s['url'] ?? ''));
            if ($u === '') {
                continue;
            }
            $name = trim((string) ($s['name'] ?? ''));
            $out[] = ['name' => $name !== '' ? $name : $u, 'url' => $u];
        }
        return $out;
    }

    /** 清洗监控 URL：去反引号并阻断内网/回环地址（防 SSRF） */
    public static function cleanMonitorUrl(string $url): string
    {
        $s = trim($url);
        if (strlen($s) >= 2 && str_starts_with($s, '`') && str_ends_with($s, '`')) {
            $s = trim(substr($s, 1, -1));
        }
        $host = parse_url($s, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return '';
                }
            } elseif (in_array(strtolower($host), ['localhost', '::1'], true) || str_ends_with(strtolower($host), '.local')) {
                return '';
            }
        }
        return $s;
    }

    /** 探测单个 URL，返回 [ok, latency_ms, cert_days, detail] */
    public static function probeUrl(string $url, int $timeout = 5): array
    {
        // 不用 CURLOPT_FOLLOWLOCATION：跟随后 curl 不会再回头校验，首跳 302 就能把
        // 请求引到内网（cleanMonitorUrl 只查了初始 URL）。故手动跟随，每跳重新校验。
        $t0 = microtime(true);
        $latency = 0;
        $detail = '';
        $okStatus = false;
        $target = $url;
        for ($hop = 0; $hop <= 3; $hop++) {
            $safe = self::cleanMonitorUrl($target);
            if ($safe === '') {
                return [false, 0, -1, '重定向目标被内网策略阻断'];
            }
            $ch = curl_init($safe);
            if ($ch === false) {
                return [false, 0, -1, '初始化失败'];
            }
            Http::applyCurl($ch);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'infowe-monitor',
            ]);
            $t = microtime(true);
            $ok = curl_exec($ch);
            $elapsed = (int) round((microtime(true) - $t) * 1000);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            $latency += $elapsed;

            if (in_array($code, [301, 302, 303, 307, 308], true) && $loc !== '') {
                $target = $loc;
                continue;
            }
            $okStatus = $ok !== false && $code > 0 && $code < 500;
            $detail = $okStatus ? ('HTTP ' . $code) : ($err !== '' ? $err : ('HTTP ' . $code));
            break;
        }
        $latency = $latency ?: (int) round((microtime(true) - $t0) * 1000);
        return [$okStatus, $latency, self::sslCertDays($url), $detail];
    }

    /** 读取 HTTPS 证书剩余天数；非 HTTPS 或失败返回 -1 */
    public static function sslCertDays(string $url): int
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return -1;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        if ($host === '') {
            return -1;
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
        $client = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        if ($client === false) {
            return -1;
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return -1;
        }
        $info = openssl_x509_parse($cert);
        if (!is_array($info) || empty($info['validTo_time_t'])) {
            return -1;
        }
        return (int) floor(($info['validTo_time_t'] - time()) / 86400);
    }

    /** 轮询全部服务并写入 service_checks（按间隔去重） */
    public static function probeAll(bool $force = false): array
    {
        $services = self::monitorServices();
        $results = [];
        $now = time();
        foreach ($services as $svc) {
            $name = $svc['name'];
            $url = $svc['url'];
            if (!$force) {
                $row = Db::query(
                    'SELECT checked_at FROM service_checks WHERE service_id = ? ORDER BY checked_at DESC LIMIT 1',
                    [$name]
                )->fetch();
                if ($row && ($now - (float) $row['checked_at']) < self::MONITOR_INTERVAL) {
                    continue;
                }
            }
            [$ok, $latency, $certDays, $detail] = self::probeUrl($url);
            Db::query(
                'INSERT INTO service_checks (service_id, checked_at, ok, latency_ms, cert_days, detail) VALUES (?,?,?,?,?,?)',
                [$name, $now, $ok ? 1 : 0, $latency, $certDays, $detail]
            );
            $results[$name] = compact('name', 'url', 'ok', 'latency', 'certDays', 'detail');
            // 每个服务保留最近 2000 条
            Db::query(
                'DELETE FROM service_checks WHERE service_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM service_checks WHERE service_id = ? ORDER BY id DESC LIMIT 2000) t)',
                [$name, $name]
            );
        }
        return $results;
    }

    /** 各服务最新探测结果 + 近 24h 可用率 */
    public static function getServicesStatus(): array
    {
        $services = self::monitorServices();
        if (!$services) {
            return [];
        }
        $items = [];
        $dayAgo = time() - 86400;
        foreach ($services as $svc) {
            $name = $svc['name'];
            $url = $svc['url'];
            $row = Db::query(
                'SELECT ok, latency_ms, cert_days, detail, checked_at FROM service_checks WHERE service_id = ? ORDER BY checked_at DESC LIMIT 1',
                [$name]
            )->fetch();
            $stat = Db::query(
                'SELECT COUNT(*) AS total, SUM(ok) AS ok_count FROM service_checks WHERE service_id = ? AND checked_at >= ?',
                [$name, $dayAgo]
            )->fetch();
            $total = (int) ($stat['total'] ?? 0);
            $okCount = (int) ($stat['ok_count'] ?? 0);
            $uptime = $total > 0 ? round($okCount * 100.0 / $total, 1) : null;
            if ($row) {
                $items[] = [
                    'name' => $name, 'url' => $url,
                    'ok' => (bool) $row['ok'],
                    'latency_ms' => (int) $row['latency_ms'],
                    'cert_days' => (int) $row['cert_days'],
                    'detail' => (string) $row['detail'],
                    'checked_at' => (float) $row['checked_at'],
                    'uptime' => $uptime,
                ];
            } else {
                $items[] = [
                    'name' => $name, 'url' => $url, 'ok' => null,
                    'latency_ms' => null, 'cert_days' => null,
                    'detail' => '尚未探测', 'checked_at' => null, 'uptime' => $uptime,
                ];
            }
        }
        return $items;
    }
}
