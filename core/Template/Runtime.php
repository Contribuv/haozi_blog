<?php
declare(strict_types=1);

namespace Blog\Template;

use Blog\View;

/**
 * 模板运行时：block 收集/渲染、宏作用域、include/import、过滤器与内建函数表。
 * 编译产物 fn(array $__ctx, Runtime $__rt): string 通过本类完成全部运行时语义。
 */
class Runtime
{
    /** 已收集的 block 内容（继承链子优先） */
    public array $blocks = [];
    /** 已注册的宏：name => closure(array $pos, array $kw): string */
    public array $macros = [];
    /** 收集模式：继承链自底向上执行，顶层输出丢弃、block 先到先得 */
    public bool $collecting = false;

    /** block 开启栈：['store'|'discard', name] */
    private array $stack = [];
    /** 闪存消息缓存：继承链的收集/渲染两阶段会重复执行父模板，需避免二次消费 */
    private ?array $flashedCache = null;
    /** 宏隔离作用域基底（模板级全局变量） */
    private array $base = [];
    /** 模板加载器 name => ['meta'=>..., 'fn'=>callable] */
    private $loader;
    /** 图标 SVG 懒加载缓存 name => raw */
    private static array $icons = [];

    public function __construct(array $base, callable $loader)
    {
        $this->base = $base;
        $this->loader = $loader;
    }

    // ==================== 渲染流程 ====================

    /**
     * 完整渲染：解析 extends 链 → 收集 block → 渲染根模板
     */
    public function renderTemplate(string $tpl, array $ctx): string
    {
        $chain = [];
        $cur = $tpl;
        for ($i = 0; $cur !== '' && $i < 20; $i++) {
            $m = ($this->loader)($cur);
            $chain[] = $m;
            $cur = $m['meta']['extends'] ?? null;
            if ($cur === null) {
                break;
            }
        }
        if (count($chain) === 1) {
            return ($chain[0]['fn'])($ctx, $this);
        }
        // 收集链：子 → 父，顶层输出丢弃，block 先到先得（子覆盖父）
        $this->collecting = true;
        foreach ($chain as $m) {
            ($m['fn'])($ctx, $this);
        }
        $this->collecting = false;
        // 渲染根模板：顶层文本输出，block 输出已收集内容
        $root = $chain[count($chain) - 1];
        return ($root['fn'])($ctx, $this);
    }

    // ==================== block 机制 ====================

    /** 开启 block：返回是否需要执行 body */
    public function beginBlock(string $name): bool
    {
        if (isset($this->blocks[$name])) {
            // 收集模式：子已定义，跳过；渲染模式：输出已收集内容
            if (!$this->collecting) {
                echo $this->blocks[$name];
            }
            $this->stack[] = ['discard', $name];
            return false;
        }
        ob_start();
        $this->stack[] = ['store', $name];
        return true;
    }

    /** 关闭 block：store 分支存入并回显到上层 */
    public function endBlock(string $name): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null || $frame[0] === 'discard') {
            return;
        }
        $content = ob_get_clean();
        $this->blocks[$frame[1]] = $content;
        echo $content;
    }

    /** {{ block('name') }} 取 block 内容 */
    public function blockContent(string $name): string
    {
        return $this->blocks[$name] ?? '';
    }

    // ==================== 宏 ====================

    /** 宏体作用域：模板级基底 + 按序绑定参数（位置 > 关键字 > 默认） */
    public function macroScope(array $pos, array $kw): array
    {
        return ['__pos' => $pos, '__kw' => $kw] + $this->base;
    }

    /** 宏参数绑定（默认表达式可引用前面参数，编译产物直接操作 $__ctx） */
    public function macroArg(array &$ctx, string $name, int $idx, mixed $defaultCode = null): void
    {
        // 编译期生成：$__ctx['x'] = $__pos[i] ?? ($__kw['x'] ?? <默认>); 此方法备用
        $ctx[$name] = $pos[$idx] ?? ($kw[$name] ?? null);
    }

    // ==================== include / import ====================

    /** {% include %}：独立运行时渲染，上下文继承，输出回显 */
    public function includeTpl(mixed $name, array $ctx): void
    {
        if (!is_string($name) || $name === '') {
            return; // 等价 ignore missing 的宽松处理
        }
        $rt = new self($this->base, $this->loader);
        echo $rt->renderTemplate($name, $ctx);
    }

    /** {% from x import a, b %}：临时运行时收集宏并合并进来 */
    public function importMacros(mixed $name, array $map, array $ctx): void
    {
        if (!is_string($name) || $name === '') {
            return;
        }
        $rt = new self($this->base, $this->loader);
        $rt->renderTemplate($name, $ctx); // 顶层输出丢弃
        foreach ($map as $alias => $orig) {
            if (isset($rt->macros[$orig])) {
                $this->macros[$alias] = $rt->macros[$orig];
            }
        }
    }

    // ==================== 调用分派 ====================

    /** 名称调用：宏 → 内建 → 全局可调用 */
    public function call(string $name, array $pos, array $kw): mixed
    {
        switch ($name) {
            case 'range':
                $start = 0;
                $step = 1;
                $stop = 0;
                if (count($pos) === 1) {
                    $stop = (int)$pos[0];
                } elseif (count($pos) >= 2) {
                    $start = (int)$pos[0];
                    $stop = (int)$pos[1];
                    $step = isset($pos[2]) ? (int)$pos[2] : 1;
                }
                if ($step === 0) {
                    return [];
                }
                return range($start, $stop - 1, $step);
            case 'block':
                return $this->blockContent((string)($pos[0] ?? ''));
            case 'url_for':
                $n = (string)($pos[0] ?? '');
                return View::url_for($n, $kw);
            case 'get_flashed_messages':
                return $this->getFlashed($kw);
            case 'icon':
                return View::icon((string)($pos[0] ?? ''), (int)($pos[1] ?? 20), (string)($pos[2] ?? ''));
            case 'config':
                return $this->base[$name] ?? null;
        }
        if (isset($this->macros[$name])) {
            return ($this->macros[$name])($pos, $kw);
        }
        $g = $this->base[$name] ?? null;
        if (is_callable($g)) {
            return $g(...array_values($pos));
        }
        if ($g !== null) {
            return $g;
        }
        return null;
    }

    /** 方法调用：数组 dict / 字符串方法 / 对象 callable */
    public function callMethod(mixed $base, string $name, array $pos, array $kw): mixed
    {
        if (is_array($base)) {
            switch ($name) {
                case 'update':
                    // 字典按键合并。$base 按值传入，改不了原变量，故返回合并后的新 dict，
                    // 模板需写成 `{% set args = args.update({...}) %}`（不要用 `set _ =`）
                    if (!empty($pos[0]) && is_array($pos[0])) {
                        return array_merge($base, $pos[0]);
                    }
                    return $base;
                case 'get':
                    return $pos[0] !== null && array_key_exists($pos[0], $base)
                        ? $base[$pos[0]]
                        : ($pos[1] ?? null);
                case 'items':
                    // 转成 [[键, 值], ...] 列表，供 `for k, v in d.items()` 解包。
                    // 不能直接返回原数组：iter() 遇到关联数组只取键，会导致解包值全为 null。
                    $pairs = [];
                    foreach ($base as $k => $v) {
                        $pairs[] = [$k, $v];
                    }
                    return $pairs;
                case 'keys':
                    return array_keys($base);
                case 'values':
                    return array_values($base);
                case 'pop':
                    $k = $pos[0] ?? null;
                    if ($k !== null && array_key_exists($k, $base)) {
                        $v = $base[$k];
                        unset($base[$k]);
                        return $v;
                    }
                    return $pos[1] ?? null;
            }
            return null;
        }
        if (is_string($base)) {
            switch ($name) {
                case 'startswith':
                    return str_starts_with($base, (string)($pos[0] ?? ''));
                case 'endswith':
                    return str_ends_with($base, (string)($pos[0] ?? ''));
                case 'strip':
                    $chars = $pos[0] ?? null;
                    return $chars === null
                        ? trim($base)
                        : trim($base, (string)$chars);
                case 'lstrip':
                    $chars = $pos[0] ?? null;
                    return $chars === null ? ltrim($base) : ltrim($base, (string)$chars);
                case 'rstrip':
                    $chars = $pos[0] ?? null;
                    return $chars === null ? rtrim($base) : rtrim($base, (string)$chars);
                case 'lower':
                    return mb_strtolower($base);
                case 'upper':
                    return mb_strtoupper($base);
                case 'split':
                    $sep = (string)($pos[0] ?? ' ');
                    if ($sep === '') {
                        return preg_split('/\s+/', trim($base)) ?: [];
                    }
                    return explode($sep, $base);
                case 'replace':
                    return str_replace((string)($pos[0] ?? ''), (string)($pos[1] ?? ''), $base);
                case 'format':
                    $i = 0;
                    return preg_replace_callback('/\{\}/', static function () use ($pos, &$i): string {
                        return (string)($pos[$i++] ?? '');
                    }, $base) ?? $base;
                case 'find':
                case 'index':
                    $p = mb_strpos($base, (string)($pos[0] ?? ''));
                    return $p === false ? -1 : $p;
                case 'count':
                    return substr_count($base, (string)($pos[0] ?? ''));
            }
            return null;
        }
        if (is_object($base)) {
            if (method_exists($base, $name)) {
                return $base->{$name}(...array_values($pos));
            }
            if ($base instanceof \ArrayAccess && $base->offsetExists($name)) {
                return $base[$name];
            }
            return null;
        }
        return null;
    }

    // ==================== 值工具 ====================

    /** Jinja `~` 拼接：null→''、bool→True/False */
    public function concat(mixed $a, mixed $b): string
    {
        return self::str($a) . self::str($b);
    }

    /** Python str() 近似（None→'' 变体，见计划文档） */
    public static function str(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v === true) {
            return 'True';
        }
        if ($v === false) {
            return 'False';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
        }
        if (is_object($v)) {
            return method_exists($v, '__toString') ? (string)$v : '';
        }
        return (string)$v;
    }

    /** `in` 测试 */
    public function contains(mixed $needle, mixed $hay): bool
    {
        if (is_string($hay)) {
            return str_contains($hay, (string)$needle);
        }
        if (is_array($hay)) {
            return array_is_list($hay)
                ? in_array($needle, $hay, true)
                : (is_scalar($needle) || $needle === null) && array_key_exists($needle, $hay);
        }
        return false;
    }

    /** `is` 测试 */
    public function test(string $name, mixed $v): bool
    {
        switch ($name) {
            case 'none':
                return $v === null;
            case 'string':
                return is_string($v);
            case 'number':
                return is_int($v) || is_float($v);
            case 'integer':
                return is_int($v);
            case 'float':
                return is_float($v);
            case 'boolean':
                return is_bool($v);
            case 'odd':
                return is_numeric($v) && ((int)$v) % 2 === 1;
            case 'even':
                return is_numeric($v) && ((int)$v) % 2 === 0;
            case 'iterable':
                return is_array($v) || $v instanceof \Traversable || is_string($v);
            case 'mapping':
                return is_array($v) && !array_is_list($v);
            case 'defined':
                return $v !== null;
            case 'undefined':
                return $v === null;
        }
        return false;
    }

    /** 属性访问：数组键 / 对象属性 */
    public function attr(mixed $v, string $name): mixed
    {
        if (is_array($v)) {
            return $v[$name] ?? null;
        }
        if ($v instanceof \ArrayAccess) {
            return $v->offsetExists($name) ? $v[$name] : null;
        }
        if (is_object($v)) {
            return $v->{$name} ?? null;
        }
        if ($v === null) {
            return null;
        }
        if (is_string($v) && $name === 'length') {
            return mb_strlen($v);
        }
        return null;
    }

    /** 下标访问（Python 负索引语义） */
    public function item(mixed $v, mixed $k): mixed
    {
        if (is_array($v)) {
            if (is_int($k) && $k < 0) {
                $k = count($v) + $k;
            }
            return $v[$k] ?? null;
        }
        if (is_string($v)) {
            // 同 slice：字符串下标按字符定位，避免 UTF-8 被字节下标截断
            if (is_int($k) && $k < 0) {
                $k = mb_strlen($v, 'UTF-8') + $k;
            }
            return is_int($k) ? mb_substr($v, $k, 1, 'UTF-8') : null;
        }
        if ($v instanceof \ArrayAccess && is_string($k)) {
            return $v->offsetExists($k) ? $v[$k] : null;
        }
        if (is_object($v) && is_string($k)) {
            return $v->{$k} ?? null;
        }
        return null;
    }

    /** 切片 [a:b]（Python 语义，负值归一） */
    public function slice(mixed $v, mixed $a, mixed $b): mixed
    {
        if (is_string($v) || is_array($v)) {
            // 字符串按字符（Python 语义）计数与切分，字节语义会截断 UTF-8 多字节序列产生乱码
            $len = is_string($v) ? mb_strlen($v, 'UTF-8') : count($v);
            $a = $a === null ? 0 : (int)$a;
            $b = $b === null ? $len : (int)$b;
            if ($a < 0) {
                $a = max($len + $a, 0);
            }
            if ($b < 0) {
                $b = $len + $b;
            }
            $b = min($b, $len);
            $n = max($b - $a, 0);
            if (is_string($v)) {
                return $n <= 0 ? '' : (string)mb_substr($v, $a, $n, 'UTF-8');
            }
            return $n <= 0 ? [] : array_slice(array_values($v), $a, $n);
        }
        return null;
    }

    /** for 迭代物化：list 取值、dict 取键、字符串逐字符 */
    public function iter(mixed $v): array
    {
        if ($v === null) {
            return [];
        }
        if (is_string($v)) {
            return preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if ($v instanceof \Traversable) {
            $v = iterator_to_array($v);
        }
        if (is_array($v)) {
            return array_is_list($v) ? $v : array_keys($v);
        }
        return [];
    }

    /** for 双变量解包（引用写回上下文） */
    public function unpack2(mixed $v, array &$ctx, array $names): void
    {
        $vals = is_array($v) ? array_values($v) : [];
        foreach ($names as $i => $n) {
            $ctx[$n] = $vals[$i] ?? null;
        }
    }

    /** `+`：数字相加，否则拼接（Python str+str 语义近似） */
    public function add(mixed $a, mixed $b): mixed
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a + $b;
        }
        return $this->concat($a, $b);
    }

    /** 调用参数合并（** 解包 + 显式关键字，后者覆盖前者） */
    public function mergeKw(...$arrays): array
    {
        $out = [];
        foreach ($arrays as $a) {
            if (is_array($a)) {
                $out = array_merge($out, $a);
            }
        }
        return $out;
    }

    // ==================== 过滤器 ====================

    /** 过滤器分派：name, 值, 位置参数, 关键字参数 */
    public function filt(string $name, mixed $v, array $pos = [], array $kw = []): mixed
    {
        switch ($name) {
            case 'safe':
                return $v;
            case 'length':
                if (is_string($v)) {
                    return mb_strlen($v);
                }
                return is_array($v) || $v instanceof \Countable ? count($v) : 0;
            case 'default':
                $useBool = (bool)($pos[1] ?? $kw['boolean'] ?? false);
                if ($v === null || ($useBool && !$v)) {
                    return $pos[0] ?? $kw['default'] ?? null;
                }
                return $v;
            case 'upper':
                return is_string($v) ? mb_strtoupper($v) : $v;
            case 'lower':
                return is_string($v) ? mb_strtolower($v) : $v;
            case 'string':
                return self::str($v);
            case 'int':
            case 'integer':
                return (int)$v;
            case 'float':
                return (float)$v;
            case 'replace':
                return str_replace((string)($pos[0] ?? ''), (string)($pos[1] ?? ''), self::str($v));
            case 'join':
                $sep = (string)($pos[0] ?? $kw['sep'] ?? '');
                if (!is_array($v)) {
                    return self::str($v);
                }
                return implode($sep, array_map([self::class, 'str'], array_values($v)));
            case 'tojson':
                $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    $json = 'null';
                }
                // HTML 安全转义（等价 Flask tojson：<> &' 转 \uXXXX）
                // 必须包 Markup：Jinja2 的 tojson 返回 safe 字符串，外层 {{ }} 不再转义。
                // 否则双引号会被 View::e 转成 &quot;，而 <script type="application/json"> 是
                // raw text 元素、不会还原实体，导致 JS 里 JSON.parse 抛 SyntaxError。
                return new Markup(strtr($json, [
                    '<' => '\u003c',
                    '>' => '\u003e',
                    '&' => '\u0026',
                    "'" => '\u0027',
                ]));
            case 'list':
                if (is_array($v)) {
                    return array_values($v);
                }
                if ($v instanceof \Traversable) {
                    return iterator_to_array($v);
                }
                return $this->iter($v);
            case 'selectattr':
                return $this->selectAttr($v, $pos, false);
            case 'rejectattr':
                return $this->selectAttr($v, $pos, true);
            case 'sort':
                if (!is_array($v)) {
                    return $v;
                }
                $attr = $pos[0] ?? $kw['attribute'] ?? null;
                $vals = array_values($v);
                if ($attr !== null) {
                    usort($vals, static function ($a, $b) use ($attr): int {
                        $x = is_array($a) ? ($a[$attr] ?? null) : (is_object($a) ? $a->{$attr} ?? null : null);
                        $y = is_array($b) ? ($b[$attr] ?? null) : (is_object($b) ? $b->{$attr} ?? null : null);
                        return $x <=> $y;
                    });
                } else {
                    sort($vals);
                }
                return $vals;
            case 'reverse':
                if (is_string($v)) {
                    return strrev($v);
                }
                return is_array($v) ? array_reverse(array_values($v)) : $v;
            case 'first':
                if (is_string($v)) {
                    return $v[0] ?? '';
                }
                if (is_array($v)) {
                    return array_values($v)[0] ?? null;
                }
                return null;
            case 'last':
                if (is_string($v)) {
                    return $v === '' ? '' : $v[strlen($v) - 1];
                }
                if (is_array($v)) {
                    $v = array_values($v);
                    return $v === [] ? null : $v[count($v) - 1];
                }
                return null;
            case 'trim':
                return is_string($v) ? trim($v) : $v;
            case 'striptags':
                return is_string($v) ? strip_tags($v) : $v;
            case 'urlencode':
                return rawurlencode(self::str($v));
            case 'abs':
                return is_numeric($v) ? abs($v + 0) : $v;
            case 'round':
                return is_numeric($v) ? round($v + 0, (int)($pos[0] ?? 0)) : $v;
        }
        // 未知过滤器：宽松返回原值
        return $v;
    }

    /** selectattr/rejectattr：按属性真值或 op 值过滤列表 */
    private function selectAttr(mixed $v, array $pos, bool $negate): array
    {
        if (!is_array($v)) {
            return [];
        }
        $attr = (string)($pos[0] ?? '');
        $hasOp = count($pos) >= 2;
        $op = $hasOp ? (string)$pos[1] : null;
        $cmp = $pos[2] ?? null;
        $out = [];
        foreach ($v as $item) {
            $val = $this->attr($item, $attr);
            if ($hasOp) {
                $ok = match ($op) {
                    'eq', '==', 'equalto', '===' => $val == $cmp,
                    'ne', '!=' => $val != $cmp,
                    'gt', '>' => $val > $cmp,
                    'ge', '>=' => $val >= $cmp,
                    'lt', '<' => $val < $cmp,
                    'le', '<=' => $val <= $cmp,
                    'in' => $this->contains($cmp, $val),
                    default => (bool)$val,
                };
            } else {
                $ok = (bool)$val;
            }
            if ($negate ? !$ok : $ok) {
                $out[] = $item;
            }
        }
        return $out;
    }

    // ==================== 内建数据 ====================

    /** Flask get_flashed_messages（读取并清空会话闪存） */
    public function getFlashed(array $kw = []): array
    {
        // 首次调用读取并清空会话；继承链的收集/渲染两阶段重复执行同一模板时复用缓存，
        // 否则收集阶段会把闪存提前消费掉，渲染阶段读到空导致消息不显示。
        if ($this->flashedCache === null) {
            $this->flashedCache = $_SESSION['_flashes'] ?? [];
            unset($_SESSION['_flashes']);
        }
        $flashes = $this->flashedCache;
        $withCat = (bool)($kw['with_categories'] ?? false);
        $filter = $kw['category_filter'] ?? null;
        $out = [];
        foreach ($flashes as $f) {
            $cat = $f[0] ?? 'message';
            $msg = $f[1] ?? '';
            if ($filter !== null && $filter !== [] && !in_array($cat, (array)$filter, true)) {
                continue;
            }
            $out[] = $withCat ? [$cat, $msg] : $msg;
        }
        return $out;
    }

    /** icon(name, size, class)：懒加载 SVG 并替换默认 24 尺寸 */
    public static function iconSvg(string $name, int $size = 20, string $class = ''): string
    {
        if ($name === '' || !preg_match('/^[a-z0-9\-_]+$/i', $name)) {
            return '';
        }
        if (!isset(self::$icons[$name])) {
            $file = PHP_BLOG_ROOT . '/static/icons/' . $name . '.svg';
            self::$icons[$name] = is_file($file) ? (string)file_get_contents($file) : '';
        }
        $raw = self::$icons[$name];
        if ($raw === '') {
            return '';
        }
        $raw = str_replace(['width="24"', 'height="24"'], ['width="' . $size . '"', 'height="' . $size . '"'], $raw);
        if ($class !== '') {
            $raw = preg_replace('/<svg/', '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '"', $raw, 1) ?? $raw;
        }
        return $raw;
    }
}
