<?php
declare(strict_types=1);

namespace Blog\Service;

/**
 * IP 归属地离线解析（ip2region xdb，Apache-2.0，数据见 core/lib/ip2region/）。
 * 对照原项目 app.py 的 _fmt_loc() / _ip_location()：纯本地查询（约 0.1ms/次），
 * 不依赖任何在线接口；回环 / 私网 / link-local / 畸形 IP 以及库文件缺失时返回 ''，
 * 前台不展示归属地。xdb 二进制格式规范参考 vendor 库的 util.py 与 searcher.py。
 */
class IpLocation
{
    /** xdb 头部信息长度（字节） */
    private const HEADER_INFO_LENGTH = 256;
    /** 向量索引：256 行 × 256 列，每项 8 字节（起始指针 + 结束指针，均小端 uint32） */
    private const VECTOR_INDEX_COLS = 256;
    private const VECTOR_INDEX_SIZE = 8;
    /** xdb 结构版本：3 起头部带 ipVersion 字段 */
    private const XDB_STRUCTURE_30 = 3;

    /** 句柄缓存：[4 => ['h'=>resource,'indexSize'=>14], 6 => ...]，false 表示不可用 */
    private static array $cache = [];

    /** 查询归属地，如「广东 江门」；查不到返回 ''（此时调用方不应展示） */
    public static function of(string $ip): string
    {
        $bytes = self::normalize($ip);
        if ($bytes === null) {
            return '';
        }
        return self::format(self::search(strlen($bytes) === 4 ? 4 : 6, $bytes));
    }

    /**
     * 校验并归一 IP 为打包字节（4 或 16 字节）。
     * ::ffff:a.b.c.d 归一为 IPv4；回环 / 私网 / 保留段返回 null（无归属地）。
     */
    private static function normalize(string $ip): ?string
    {
        $s = trim($ip);
        if ($s !== '') {
            $s = trim($s, '[]');
            $pct = strpos($s, '%');
            if ($pct !== false) {
                $s = substr($s, 0, $pct);
            }
        }
        $packed = @inet_pton($s);
        if ($packed === false || $packed === '') {
            return null;
        }
        // IPv4-mapped IPv6（::ffff:a.b.c.d）统一按 v4 库查询
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        $canonical = @inet_ntop($packed);
        if ($canonical === false) {
            return null;
        }
        // 回环 / 私网 / link-local / 保留段无归属地，直接跳过查询
        if (filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        return $packed;
    }

    /** 在 xdb 中二分查找原始区域串（格式：国家|省份|城市|ISP|国家码） */
    private static function search(int $ver, string $ipBytes): string
    {
        $db = self::db($ver);
        if ($db === null) {
            return '';
        }
        $byteNum = strlen($ipBytes);

        $idx = ord($ipBytes[0]) * self::VECTOR_INDEX_COLS * self::VECTOR_INDEX_SIZE
            + ord($ipBytes[1]) * self::VECTOR_INDEX_SIZE;
        $vec = self::readAt($db['h'], self::HEADER_INFO_LENGTH + $idx, self::VECTOR_INDEX_SIZE);
        if (strlen($vec) < self::VECTOR_INDEX_SIZE) {
            return '';
        }
        $sPtr = self::le32($vec, 0);
        $ePtr = self::le32($vec, 4);
        // 指针为 0 表示该段无数据
        if ($sPtr === 0 || $ePtr === 0) {
            return '';
        }

        $indexSize = $db['indexSize'];
        $dLen = 0;
        $dPtr = 0;
        $low = 0;
        $high = intdiv($ePtr - $sPtr, $indexSize);
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $buff = self::readAt($db['h'], $sPtr + $mid * $indexSize, $indexSize);
            if (strlen($buff) < $indexSize) {
                break;
            }
            if (self::subCompare($ipBytes, $buff, 0, $ver) < 0) {
                $high = $mid - 1;
            } elseif (self::subCompare($ipBytes, $buff, $byteNum, $ver) > 0) {
                $low = $mid + 1;
            } else {
                $dLen = self::le16($buff, $byteNum * 2);
                $dPtr = self::le32($buff, $byteNum * 2 + 2);
                break;
            }
        }
        if ($dLen === 0 || $dPtr === 0) {
            return '';
        }
        return self::readAt($db['h'], $dPtr, $dLen);
    }

    /**
     * 比较 IP 与索引块中偏移 $offset 处的地址。
     * IPv4 的 xdb 索引用小端存地址（兼容旧版实现），需反向取字节；IPv6 为大端直比。
     */
    private static function subCompare(string $ipBytes, string $buff, int $offset, int $ver): int
    {
        $len = strlen($ipBytes);
        if ($ver !== 4) {
            return strcmp($ipBytes, substr($buff, $offset, $len)) <=> 0;
        }
        $j = $offset + $len - 1;
        for ($i = 0; $i < $len; $i++) {
            $a = ord($ipBytes[$i]);
            $b = ord($buff[$j]);
            if ($a !== $b) {
                return $a < $b ? -1 : 1;
            }
            $j--;
        }
        return 0;
    }

    /**
     * 区域串转展示文案：国外只显示国家名，国内显示「省份」或「省份 城市」。
     * 缺省字段在 xdb 中为 '0'。
     */
    private static function format(string $region): string
    {
        if ($region === '') {
            return '';
        }
        $f = explode('|', $region);
        $country = $f[0] ?? '';
        if ($country !== '中国') {
            return $country;
        }
        $prov = self::trimLoc($f[1] ?? '');
        $city = (isset($f[2]) && $f[2] !== '0') ? self::trimLoc($f[2]) : '';
        if ($prov === '' || $prov === '0') {
            return '';
        }
        return ($prov === $city || $city === '') ? $prov : $prov . ' ' . $city;
    }

    /** 去掉行政区划后缀：重庆市→重庆、四川省→四川、广西壮族自治区→广西 */
    private static function trimLoc(string $loc): string
    {
        if ($loc === '') {
            return '';
        }
        foreach (['壮族自治区', '维吾尔自治区', '回族自治区', '自治区', '特别行政区', '省', '市'] as $suffix) {
            $loc = str_replace($suffix, '', $loc);
        }
        return trim($loc);
    }

    /** 惰性打开对应版本的 xdb；结构不符 / 文件缺失时缓存 false，不再重复尝试 */
    private static function db(int $ver): ?array
    {
        if (array_key_exists($ver, self::$cache)) {
            return self::$cache[$ver] ?: null;
        }
        $path = CORE_PATH . '/lib/ip2region/ip2region_v' . $ver . '.xdb';
        $h = is_file($path) ? @fopen($path, 'rb') : false;
        if ($h === false) {
            self::$cache[$ver] = false;
            return null;
        }
        $header = self::readAt($h, 0, self::HEADER_INFO_LENGTH);
        // 结构 2.0 只有 IPv4；3.0 起按头部 ipVersion 判定
        $fileVer = self::le16($header, 0) < self::XDB_STRUCTURE_30
            ? 4
            : self::le16($header, 16);
        if ($fileVer !== $ver) {
            fclose($h);
            self::$cache[$ver] = false;
            return null;
        }
        // IPv4 段索引 = 起始4 + 结束4 + 区域串长2 + 区域串偏移4；IPv6 同理按 16 字节地址
        self::$cache[$ver] = ['h' => $h, 'indexSize' => $ver === 4 ? 14 : 38];
        return self::$cache[$ver];
    }

    /** 从句柄指定偏移读取内容，失败返回空串 */
    private static function readAt($h, int $offset, int $length): string
    {
        if ($length <= 0 || fseek($h, $offset) !== 0) {
            return '';
        }
        $buf = fread($h, $length);
        return $buf === false ? '' : $buf;
    }

    /** 小端 uint16 */
    private static function le16(string $b, int $o): int
    {
        return ord($b[$o]) | (ord($b[$o + 1]) << 8);
    }

    /** 小端 uint32 */
    private static function le32(string $b, int $o): int
    {
        return ord($b[$o])
            | (ord($b[$o + 1]) << 8)
            | (ord($b[$o + 2]) << 16)
            | (ord($b[$o + 3]) << 24);
    }
}