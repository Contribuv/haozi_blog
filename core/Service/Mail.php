<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Settings;

/**
 * SMTP 邮件发送（对照原项目 _smtp_send）。
 * PHP 无内置 SMTP 客户端，这里用 stream_socket_client 手写最小可用实现：
 * 465 端口走 SSL 直连，其余端口走 STARTTLS。配置缺漏或连接失败时抛异常，由调用方决定吞还是报。
 */
class Mail
{
    /** 通知邮件内嵌样式（与评论通知邮件共用，兼容主流邮箱客户端） */
    public const NOTIFY_CSS = 'body{margin:0;padding:0;background:#eef0f4;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif}'
        . '.wrap{max-width:580px;margin:0 auto;padding:28px 16px}.brand{font-size:12px;font-weight:600;color:#98a1b3;letter-spacing:1.5px;margin:0 6px 10px}'
        . '.card{background:#fff;border:1px solid #e4e7ee;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(31,45,80,.06)}'
        . '.head{background:linear-gradient(135deg,#5b6cf5,#7c5cf5);color:#fff;padding:18px 22px;font-size:17px;font-weight:700}.head-sub{display:block;font-size:12px;font-weight:400;opacity:.92;margin-top:4px}'
        . '.body{padding:20px 22px}.meta{font-size:13px;color:#57606a;margin:6px 0;line-height:1.6}.link a{color:#0969da;text-decoration:none}'
        . '.cta-wrap{margin-top:14px}'
        . '.cta{display:inline-block;background:#5b6cf5;color:#fff!important;text-decoration:none;font-size:13px;font-weight:600;padding:9px 18px;border-radius:8px}'
        . '.quote{margin-top:18px;background:#f6f8fa;border:1px solid #eef0f4;border-left:4px solid #5b6cf5;border-radius:8px;padding:12px 14px}'
        . '.q-author{font-size:13px;font-weight:700;color:#24292f;margin-bottom:6px}.q-avatar{display:inline-block;width:26px;height:26px;line-height:26px;text-align:center;border-radius:50%;background:#5b6cf5;color:#fff;font-size:13px;font-weight:700;margin-right:8px}'
        . '.q-content{font-size:14px;color:#3a4152;line-height:1.75;white-space:pre-wrap}'
        . '.foot{margin:16px 6px 0;font-size:12px;color:#98a1b3;line-height:1.6;text-align:center}';

    /** SMTP 是否已配置（存在 smtp_host） */
    public static function configured(): bool
    {
        return trim((string) Settings::get('smtp_host', '')) !== '';
    }

    /**
     * 发送 HTML 邮件。
     * $overrides 为测试邮件用：以临时配置试发，不落库不改全局配置。
     * 配置缺漏或连接失败时抛 \RuntimeException。
     */
    public static function send(string $toAddr, string $subject, string $html, ?array $overrides = null): void
    {
        $cfg = $overrides ?? null;
        $host = trim((string) ($cfg['smtp_host'] ?? Settings::get('smtp_host', '')));
        if ($host === '' || $toAddr === '') {
            throw new \RuntimeException('SMTP 未配置');
        }
        $port = (int) ($cfg['smtp_port'] ?? Settings::get('smtp_port', 465));
        if ($port <= 0) {
            $port = 465;
        }
        $user = trim((string) ($cfg['smtp_user'] ?? Settings::get('smtp_user', '')));
        $pwd = (string) ($cfg['smtp_pass'] ?? Settings::get('smtp_pass', ''));
        $sender = trim((string) ($cfg['smtp_sender_name'] ?? Settings::get('smtp_sender_name', '')));
        if ($sender === '') {
            $sender = trim((string) Settings::get('blog_name', 'Blog'));
        }
        if ($sender === '') {
            $sender = 'Blog';
        }

        $fromAddr = $user !== '' ? $user : $toAddr;
        $message = self::buildMessage($sender, $fromAddr, $toAddr, $subject, $html);

        $ssl = $port === 465;
        $socket = self::connect($host, $port, $ssl);
        try {
            self::expect($socket, 220);
            self::ehlo($socket);
            if (!$ssl) {
                self::cmd($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS 握手失败');
                }
                self::ehlo($socket);
            }
            if ($user !== '') {
                self::cmd($socket, 'AUTH LOGIN', 334);
                self::cmd($socket, base64_encode($user), 334);
                self::cmd($socket, base64_encode($pwd), 235);
            }
            self::cmd($socket, 'MAIL FROM:<' . $fromAddr . '>', 250);
            self::cmd($socket, 'RCPT TO:<' . $toAddr . '>', 250);
            self::cmd($socket, 'DATA', 354);
            // 点填充：行首单独的点需转义，避免被当作结束标记
            $data = preg_replace('/^\./m', '..', $message);
            fwrite($socket, $data . "\r\n.\r\n");
            self::expect($socket, 250);
            self::cmd($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    /** 组装 RFC 5322 邮件原文（含 MIME 头与 UTF-8 正文） */
    private static function buildMessage(string $senderName, string $fromAddr, string $toAddr, string $subject, string $html): string
    {
        $headers = [
            'Date: ' . date('D, d M Y H:i:s O'),
            'From: ' . self::encodeName($senderName) . ' <' . $fromAddr . '>',
            'To: <' . $toAddr . '>',
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = chunk_split(base64_encode($html), 76, "\r\n");
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /** 邮件显示名编码：含非 ASCII 时用 RFC 2047 编码字 */
    private static function encodeName(string $name): string
    {
        if (preg_match('/^[\x20-\x7E]+$/', $name)) {
            return preg_match('/[^\w \-.]/', $name) ? '"' . addcslashes($name, '"\\') . '"' : $name;
        }
        return self::encodeHeader($name);
    }

    /** 头字段 RFC 2047 编码（=?UTF-8?B?...?=），纯 ASCII 原样返回 */
    private static function encodeHeader(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /** 建立 TCP/SSL 连接（15 秒超时） */
    private static function connect(string $host, int $port, bool $ssl)
    {
        $remote = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => Http::sslContext()]);
        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if ($socket === false) {
            throw new \RuntimeException('SMTP 连接失败：' . ($errstr !== '' ? $errstr : ('错误码 ' . $errno)));
        }
        stream_set_timeout($socket, 15);
        return $socket;
    }

    /** 发送一行命令并校验响应码 */
    private static function cmd($socket, string $line, int $expect): void
    {
        fwrite($socket, $line . "\r\n");
        self::expect($socket, $expect);
    }

    /** 读取 SMTP 响应并校验首行响应码 */
    private static function expect($socket, int $code): void
    {
        $line = '';
        while (($buf = fgets($socket, 1024)) !== false) {
            $line = $buf;
            // 多行响应以 "250-" 续行，"250 " 结束
            if (strlen($buf) < 4 || $buf[3] !== '-') {
                break;
            }
        }
        if ($line === '' || (int) substr($line, 0, 3) !== $code) {
            throw new \RuntimeException('SMTP 响应异常：' . trim($line));
        }
    }

    /** 发送 EHLO（优先带域名的扩展命令），并吞掉多行扩展列表 */
    private static function ehlo($socket): void
    {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        fwrite($socket, 'EHLO ' . $host . "\r\n");
        self::expect($socket, 250);
    }
}
