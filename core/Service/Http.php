<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * HTTP/SSL 公共辅助：定位 CA 根证书包并注入 curl / stream 上下文。
 *
 * Windows 版 PHP 的 curl（OpenSSL 后端）默认不带 CA 包，导致所有 HTTPS 请求报
 * “unable to get local issuer certificate”。这里优先使用 ini 配置，其次回退到
 * 项目内置证书 core/lib/cacert.pem，保证 GitHub / 邮件 / 头像代理等链路可用。
 */
class Http
{
    private static bool $resolved = false;
    private static ?string $cacert = null;

    /** CA 证书包路径；无可用证书返回 null */
    public static function cacert(): ?string
    {
        if (self::$resolved) {
            return self::$cacert;
        }
        self::$resolved = true;
        foreach ([
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
            CORE_PATH . '/lib/cacert.pem',
        ] as $p) {
            if ($p !== '' && is_file($p)) {
                self::$cacert = $p;
                break;
            }
        }
        return self::$cacert;
    }

    /** 为 curl 句柄注入基础 SSL 选项（在调用方 setopt_array 之前调用即可被覆盖） */
    public static function applyCurl(\CurlHandle $ch): void
    {
        $ca = self::cacert();
        if ($ca !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    /** 构造 SSL stream 上下文选项（含 cafile） */
    public static function sslContext(): array
    {
        $ctx = ['verify_peer' => true, 'verify_peer_name' => true];
        $ca = self::cacert();
        if ($ca !== null) {
            $ctx['cafile'] = $ca;
        }
        return $ctx;
    }

    /**
     * 发起 GET 请求并解析 JSON：网络层失败抛 RuntimeException，响应非 JSON 返回 null。
     * 供云 API / GitHub / Gitee 等接口调用复用。
     */
    public static function getJson(string $url, array $headers = [], int $timeout = 6): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        self::applyCurl($ch);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => array_merge(['User-Agent: infowe-blog'], $headers),
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException($err !== '' ? $err : '请求失败');
        }
        $d = json_decode((string) $body, true);
        return is_array($d) ? $d : null;
    }
}