<?php
declare(strict_types=1);

namespace Blog\Template;

/**
 * Jinja2 兼容子集模板编译器：模板源码 → PHP 闭包缓存文件。
 *
 * 编译产物结构：['meta' => ['extends' => ?string, 'hasExtends' => bool],
 *               'fn' => function (array $__ctx, Runtime $__rt): string]
 *
 * 支持语法：
 *   输出 {{ expr }}、注释 {# #}、语句 {% ... %}、空白控制 {%- -%} / {{- -}}
 *   if/elif/else、for（含 if 过滤、else、loop 变量、双变量解包）、set / block-set、
 *   block/endblock、extends、macro/endmacro、from...import、include、with
 *   表达式：三元（a if c else b）、or/and/not、比较/ in / is 测试、~ 拼接、
 *           + - * / %、一元负号、.属性 .方法()、[] 下标、[:] 切片、| 过滤器、
 *           裸名调用（range/url_for/icon/宏/全局）、字面量（数/串/列表/字典/布尔/None）
 */
class Compiler
{
    private string $tpl = '';
    /** @var string[] 生成的 PHP 语句行 */
    private array $lines = [];
    /** @var array 语句栈 ['k' => if|for|block|macro|set|with, ...] */
    private array $stk = [];
    /** 表达式短路临时变量号 */
    private int $tn = 0;
    /** 语句序号（for/block/with 生成变量用） */
    private int $sn = 0;
    private ?string $extends = null;

    /** @var array 当前表达式 token 流 [['t'=>type,'v'=>value], ...] */
    private array $tk = [];
    private int $tp = 0;

    /** 模板名（错误定位） */
    private string $curNode = '';

    public static function compile(string $src, string $tpl): string
    {
        $c = new self();
        $c->tpl = $tpl;
        $c->generate($src);
        if ($c->stk !== []) {
            $k = $c->stk[count($c->stk) - 1]['k'];
            throw new \RuntimeException("模板 {$tpl}: 语句未闭合（缺 end{$k}）");
        }
        $meta = ['extends' => $c->extends, 'hasExtends' => $c->extends !== null];
        return '<?php return ['
            . "'meta' => " . var_export($meta, true) . ",\n"
            . "'fn' => function (array \$__ctx, \\Blog\\Template\\Runtime \$__rt): string {\n"
            . "ob_start();\n"
            . implode("\n", $c->lines) . "\n"
            . "return ob_get_clean();\n"
            . "}];\n";
    }

    // ==================== 顶层：扫描与分派 ====================

    private function generate(string $src): void
    {
        $nodes = $this->scan($src);
        $this->trimWhitespace($nodes);
        foreach ($nodes as $n) {
            $this->curNode = $n['t'];
            switch ($n['t']) {
                case 'text':
                    if ($n['v'] !== '') {
                        $this->out('echo ' . var_export($n['v'], true) . ';');
                    }
                    break;
                case 'expr':
                    $this->nodeExpr($n['v']);
                    break;
                case 'stmt':
                    $this->nodeStmt($n['v']);
                    break;
                case 'cmt':
                    break;
            }
        }
    }

    /**
     * 扫描 {{ }} / {% %} / {# #}，引号状态机防截断，记录 - 空白控制标志
     * @return array<int, array{t:string, v:string, l?:bool, r?:bool}>
     */
    private function scan(string $src): array
    {
        $nodes = [];
        $i = 0;
        $n = strlen($src);
        $textStart = 0;
        while ($i < $n) {
            if ($src[$i] === '{' && $i + 1 < $n && ($src[$i + 1] === '{' || $src[$i + 1] === '%' || $src[$i + 1] === '#')) {
                $open = substr($src, $i, 2);
                $close = $open === '{{' ? '}}' : ($open === '{%' ? '%}' : '#}');
                $type = $open === '{{' ? 'expr' : ($open === '{%' ? 'stmt' : 'cmt');
                if ($i > $textStart) {
                    $nodes[] = ['t' => 'text', 'v' => substr($src, $textStart, $i - $textStart)];
                }
                $j = $i + 2;
                $lead = false;
                if ($j < $n && $src[$j] === '-') {
                    $lead = true;
                    $j++;
                }
                $quote = '';
                $bodyStart = $j;
                $end = -1;
                while ($j < $n) {
                    $ch = $src[$j];
                    if ($quote !== '') {
                        if ($ch === '\\') {
                            $j += 2;
                            continue;
                        }
                        if ($ch === $quote) {
                            $quote = '';
                        }
                        $j++;
                        continue;
                    }
                    if ($ch === "'" || $ch === '"') {
                        $quote = $ch;
                        $j++;
                        continue;
                    }
                    if ($ch === $close[0] && $j + 1 < $n && $src[$j + 1] === $close[1]) {
                        $end = $j;
                        break;
                    }
                    $j++;
                }
                if ($end < 0) {
                    throw new \RuntimeException("模板 {$this->tpl}: 标签未闭合（" . substr($src, $i, 40) . '）');
                }
                $body = substr($src, $bodyStart, $end - $bodyStart);
                $trail = false;
                $bl = strlen($body);
                if ($bl > 0 && $body[$bl - 1] === '-') {
                    $trail = true;
                    $body = substr($body, 0, $bl - 1);
                }
                $nodes[] = $type === 'cmt'
                    ? ['t' => 'cmt', 'v' => $body, 'l' => $lead, 'r' => $trail]
                    : ['t' => $type, 'v' => $body, 'l' => $lead, 'r' => $trail];
                $i = $end + 2;
                $textStart = $i;
                continue;
            }
            $i++;
        }
        if ($textStart < $n) {
            $nodes[] = ['t' => 'text', 'v' => substr($src, $textStart)];
        }
        return $nodes;
    }

    /** {%- -%} / {{- -}}：裁剪相邻文本节点 */
    private function trimWhitespace(array &$nodes): void
    {
        $cnt = count($nodes);
        for ($i = 0; $i < $cnt; $i++) {
            if (!empty($nodes[$i]['l']) && $i > 0 && $nodes[$i - 1]['t'] === 'text') {
                $nodes[$i - 1]['v'] = rtrim($nodes[$i - 1]['v']);
            }
            if (!empty($nodes[$i]['r']) && $i + 1 < $cnt && $nodes[$i + 1]['t'] === 'text') {
                $nodes[$i + 1]['v'] = ltrim($nodes[$i + 1]['v']);
            }
        }
    }

    private function out(string $code): void
    {
        $this->lines[] = $code;
    }

    private function err(string $msg): never
    {
        throw new \RuntimeException("模板 {$this->tpl}: {$msg}");
    }

    // ==================== 输出节点 ====================

    private function nodeExpr(string $body): void
    {
        if (trim($body) === '') {
            return;
        }
        $this->lex($body);
        // 深度 0 处的 |safe → 移除并转 raw 输出
        $raw = false;
        $depth = 0;
        for ($i = 0; $i < count($this->tk); $i++) {
            $t = $this->tk[$i];
            if ($t['t'] === 'op') {
                if (in_array($t['v'], ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($t['v'], [')', ']', '}'], true)) {
                    $depth--;
                } elseif ($depth === 0 && $t['v'] === '|') {
                    $nx = $this->tk[$i + 1] ?? null;
                    if ($nx && $nx['t'] === 'name' && $nx['v'] === 'safe') {
                        array_splice($this->tk, $i, 2);
                        $raw = true;
                        $i--;
                    }
                }
            }
        }
        $r = $this->compileTokens($this->tk);
        $this->out($raw
            ? 'echo \\Blog\\Template\\Runtime::str(' . $r['p'] . ');'
            : 'echo \\Blog\\View::e(' . $r['p'] . ');');
    }

    // ==================== 语句节点 ====================

    private function nodeStmt(string $body): void
    {
        $this->lex($body);
        if ($this->tk === []) {
            return;
        }
        $k = $this->tk[0];
        if ($k['t'] !== 'name') {
            $this->err('语句必须以关键字开头: ' . $body);
        }
        switch ($k['v']) {
            case 'if':
                $c = $this->exprFrom(1);
                $this->stk[] = ['k' => 'if'];
                $this->out('if (' . $c['p'] . ') {');
                break;

            case 'elif':
            case 'elseif':
                if (($this->top()['k'] ?? '') !== 'if') {
                    $this->err('elif 出现在 if 之外');
                }
                $c = $this->exprFrom(1);
                $this->out('} elseif (' . $c['p'] . ') {');
                break;

            case 'else':
                $t = $this->top();
                if (($t['k'] ?? '') === 'if') {
                    $this->out('} else {');
                } elseif (($t['k'] ?? '') === 'for') {
                    if ($t['e']) {
                        $this->err('for-else 重复');
                    }
                    $this->stk[count($this->stk) - 1]['e'] = true;
                    $this->forTail($t['n']);
                    $this->out('} if ($__n' . $t['n'] . ' === 0) {');
                } else {
                    $this->err('else 出现在 if/for 之外');
                }
                break;

            case 'endif':
                if (($this->top()['k'] ?? '') !== 'if') {
                    $this->err('endif 无匹配 if');
                }
                array_pop($this->stk);
                $this->out('}');
                break;

            case 'for':
                $this->stmtFor();
                break;

            case 'endfor':
                $t = $this->top();
                if (($t['k'] ?? '') !== 'for') {
                    $this->err('endfor 无匹配 for');
                }
                array_pop($this->stk);
                // 无 else：需补循环尾，再闭合 foreach 的 '{'
                // 有 else：for 尾已在 else 处输出，endfor 只闭合 else 的 'if ($__n === 0) {'
                if (!$t['e']) {
                    $this->forTail($t['n']);
                }
                $this->out('}');
                break;

            case 'set':
                $this->stmtSet();
                break;

            case 'endset':
                $t = $this->top();
                if (($t['k'] ?? '') !== 'set' || !$t['block']) {
                    $this->err('endset 无匹配 block-set');
                }
                array_pop($this->stk);
                // 块内 HTML 已由各 {{ }} 分别转义，整体再用即已安全，包成 Markup
                // 避免外层 {{ x }} 二次转义（对齐 Jinja2 的 block-set 语义）
                $this->out('$__ctx[' . var_export($t['name'], true) . '] = new \Blog\Template\Markup(ob_get_clean());');
                break;

            case 'extends':
                if (!isset($this->tk[1]) || $this->tk[1]['t'] !== 'str') {
                    $this->err('extends 仅支持字面量模板名');
                }
                $this->extends = $this->tk[1]['v'];
                break;

            case 'block':
                $this->stmtBlock();
                break;

            case 'endblock':
                $t = $this->top();
                if (($t['k'] ?? '') !== 'block') {
                    $this->err('endblock 无匹配 block');
                }
                array_pop($this->stk);
                $this->out('} $__rt->endBlock(' . var_export($t['name'], true) . ');');
                break;

            case 'macro':
                $this->stmtMacro();
                break;

            case 'endmacro':
                $t = $this->top();
                if (($t['k'] ?? '') !== 'macro') {
                    $this->err('endmacro 无匹配 macro');
                }
                array_pop($this->stk);
                $this->out('return new \Blog\Template\Markup(ob_get_clean()); };');
                break;

            case 'from':
                $this->stmtFrom();
                break;

            case 'include':
                $this->stmtInclude();
                break;

            case 'with':
                $this->stmtWith();
                break;

            case 'endwith':
                $t = $this->top();
                if (($t['k'] ?? '') !== 'with') {
                    $this->err('endwith 无匹配 with');
                }
                array_pop($this->stk);
                $this->out('$__ctx = $__ws' . $t['n'] . ';');
                $this->out('}');
                break;

            default:
                $this->err('不支持的语句关键字: ' . $k['v']);
        }
    }

    private function top(): array
    {
        return $this->stk === [] ? [] : $this->stk[count($this->stk) - 1];
    }

    /** 第 $from 个 token 起的完整表达式 */
    private function exprFrom(int $from): array
    {
        $toks = array_slice($this->tk, $from);
        if ($toks === []) {
            $this->err('缺少表达式');
        }
        return $this->compileTokens($toks);
    }

    /** 编译一段独立 token 流（保存/恢复解析状态，可嵌套） */
    private function compileTokens(array $toks): array
    {
        $saveTk = $this->tk;
        $saveTp = $this->tp;
        $this->tk = $toks;
        $this->tp = 0;
        try {
            $r = $this->pTernary();
            if ($this->tp < count($this->tk)) {
                $t = $this->tk[$this->tp];
                $this->err('表达式无法解析于 ' . $t['t'] . ' "' . $t['v'] . '"');
            }
        } finally {
            $this->tk = $saveTk;
            $this->tp = $saveTp;
        }
        return $r;
    }

    /** {% for targets in iter [if cond] %} */
    private function stmtFor(): void
    {
        // 目标变量表
        $targets = [];
        $i = 1;
        while (true) {
            if (!isset($this->tk[$i]) || $this->tk[$i]['t'] !== 'name') {
                $this->err('for 缺少循环变量');
            }
            $targets[] = $this->tk[$i]['v'];
            $i++;
            if (isset($this->tk[$i]) && $this->tk[$i]['t'] === 'op' && $this->tk[$i]['v'] === ',') {
                $i++;
                continue;
            }
            break;
        }
        if (!isset($this->tk[$i]) || $this->tk[$i]['t'] !== 'name' || $this->tk[$i]['v'] !== 'in') {
            $this->err('for 缺少 in');
        }
        $i++;
        // 深度 0 找 'if'（过滤子句）
        $depth = 0;
        $condAt = null;
        for ($j = $i; $j < count($this->tk); $j++) {
            $t = $this->tk[$j];
            if ($t['t'] === 'op') {
                if (in_array($t['v'], ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($t['v'], [')', ']', '}'], true)) {
                    $depth--;
                }
            } elseif ($depth === 0 && $t['t'] === 'name' && $t['v'] === 'if') {
                $condAt = $j;
                break;
            }
        }
        $iterToks = array_slice($this->tk, $i, ($condAt ?? count($this->tk)) - $i);
        $iter = $this->compileTokens($iterToks);
        $cond = $condAt !== null
            ? $this->compileTokens(array_slice($this->tk, $condAt + 1))
            : null;

        $N = $this->sn++;
        if ($cond !== null) {
            // 两遍物化：先按条件过滤
            $this->out('$__keep' . $N . ' = [];');
            $this->out('foreach ($__rt->iter(' . $iter['p'] . ') as $__v' . $N . ') {');
            $this->out($this->bindCode($N, $targets));
            $this->out('if (' . $cond['p'] . ' ) { $__keep' . $N . '[] = $__v' . $N . '; }');
            $this->out('}');
            $this->out('$__arr' . $N . ' = $__keep' . $N . ';');
        } else {
            $this->out('$__arr' . $N . ' = $__rt->iter(' . $iter['p'] . ');');
        }
        $this->out('$__n' . $N . ' = count($__arr' . $N . '); $__i' . $N . ' = 0;');
        $this->out('foreach ($__arr' . $N . ' as $__v' . $N . ') {');
        $this->out($this->bindCode($N, $targets));
        // loop 变量（保存/恢复外层 loop）
        $this->out('$__hl' . $N . ' = array_key_exists(\'loop\', $__ctx); $__ls' . $N . ' = $__hl' . $N . ' ? $__ctx[\'loop\'] : null;');
        $this->out('$__ctx[\'loop\'] = [\'index\' => $__i' . $N . ' + 1, \'index0\' => $__i' . $N
            . ', \'first\' => $__i' . $N . ' === 0, \'last\' => $__i' . $N . ' === $__n' . $N . ' - 1'
            . ', \'length\' => $__n' . $N . ', \'revindex\' => $__n' . $N . ' - $__i' . $N
            . ', \'revindex0\' => $__n' . $N . ' - 1 - $__i' . $N . '];');
        $this->stk[] = ['k' => 'for', 'n' => $N, 'e' => false];
    }

    /** 循环体尾：恢复外层 loop、递增序号（endfor / for-else 处生成） */
    private function forTail(int $N): void
    {
        $this->out('if ($__hl' . $N . ') { $__ctx[\'loop\'] = $__ls' . $N . '; } else { unset($__ctx[\'loop\']); }');
        $this->out('$__i' . $N . '++;');
    }

    private function bindCode(int $N, array $targets): string
    {
        if (count($targets) === 1) {
            return '$__ctx[' . var_export($targets[0], true) . '] = $__v' . $N . ';';
        }
        return '$__rt->unpack2($__v' . $N . ', $__ctx, ' . var_export($targets, true) . ');';
    }

    /** {% set x = expr %} 或 {% set x %}...{% endset %} */
    private function stmtSet(): void
    {
        // 找深度 0 的 '='
        $depth = 0;
        $eqAt = null;
        for ($j = 1; $j < count($this->tk); $j++) {
            $t = $this->tk[$j];
            if ($t['t'] === 'op') {
                if (in_array($t['v'], ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($t['v'], [')', ']', '}'], true)) {
                    $depth--;
                } elseif ($depth === 0 && $t['v'] === '=') {
                    $eqAt = $j;
                    break;
                }
            }
        }
        if ($eqAt === null) {
            // block-set
            if (!isset($this->tk[1]) || $this->tk[1]['t'] !== 'name' || count($this->tk) !== 2) {
                $this->err('block-set 仅支持单个变量名');
            }
            $this->stk[] = ['k' => 'set', 'block' => true, 'name' => $this->tk[1]['v']];
            $this->out('ob_start();');
            return;
        }
        // 目标名（单或逗号分隔）
        $targets = [];
        for ($j = 1; $j < $eqAt; $j++) {
            $t = $this->tk[$j];
            if ($t['t'] === 'name') {
                $targets[] = $t['v'];
            } elseif (!($t['t'] === 'op' && $t['v'] === ',')) {
                $this->err('set 左侧非法');
            }
        }
        if ($targets === []) {
            $this->err('set 缺少变量名');
        }
        $expr = $this->compileTokens(array_slice($this->tk, $eqAt + 1));
        if (count($targets) === 1) {
            $this->out('$__ctx[' . var_export($targets[0], true) . '] = ' . $expr['p'] . ';');
        } else {
            $this->out('$__rt->unpack2(' . $expr['p'] . ', $__ctx, ' . var_export($targets, true) . ');');
        }
    }

    /** {% block name %} ... {% endblock %} */
    private function stmtBlock(): void
    {
        if (!isset($this->tk[1]) || $this->tk[1]['t'] !== 'name') {
            $this->err('block 缺少名称');
        }
        $name = $this->tk[1]['v'];
        $N = $this->sn++;
        $this->out('$__bb' . $N . ' = $__rt->beginBlock(' . var_export($name, true) . '); if ($__bb' . $N . ') {');
        $this->stk[] = ['k' => 'block', 'name' => $name, 'n' => $N];
    }

    /** {% macro name(params) %} ... {% endmacro %} */
    private function stmtMacro(): void
    {
        if (!isset($this->tk[1]) || $this->tk[1]['t'] !== 'name') {
            $this->err('macro 缺少名称');
        }
        $name = $this->tk[1]['v'];
        if (!isset($this->tk[2]) || $this->tk[2]['t'] !== 'op' || $this->tk[2]['v'] !== '(') {
            $this->err('macro 缺少参数表');
        }
        // 解析参数：name [= expr] , ...
        $params = [];
        $i = 3;
        if (isset($this->tk[$i]) && $this->tk[$i]['t'] === 'op' && $this->tk[$i]['v'] === ')') {
            $i++;
        } else {
            while (true) {
                if (!isset($this->tk[$i]) || $this->tk[$i]['t'] !== 'name') {
                    $this->err('macro 参数非法');
                }
                $pname = $this->tk[$i]['v'];
                $i++;
                $def = null;
                if (isset($this->tk[$i]) && $this->tk[$i]['t'] === 'op' && $this->tk[$i]['v'] === '=') {
                    $i++;
                    // 默认表达式到深度 0 的 ',' 或 ')'
                    $depth = 0;
                    $start = $i;
                    while ($i < count($this->tk)) {
                        $t = $this->tk[$i];
                        if ($t['t'] === 'op') {
                            if (in_array($t['v'], ['(', '[', '{'], true)) {
                                $depth++;
                            } elseif (in_array($t['v'], [')', ']', '}'], true)) {
                                if ($depth === 0) {
                                    break;
                                }
                                $depth--;
                            } elseif ($depth === 0 && $t['v'] === ',') {
                                break;
                            }
                        }
                        $i++;
                    }
                    $def = array_slice($this->tk, $start, $i - $start);
                }
                $params[] = ['name' => $pname, 'def' => $def];
                if ($i < count($this->tk) && $this->tk[$i]['t'] === 'op' && $this->tk[$i]['v'] === ',') {
                    $i++;
                    continue;
                }
                if (isset($this->tk[$i]) && $this->tk[$i]['t'] === 'op' && $this->tk[$i]['v'] === ')') {
                    $i++;
                }
                break;
            }
        }
        $this->out('$__rt->macros[' . var_export($name, true) . '] = function (array $__pos, array $__kw) use ($__rt): \Blog\Template\Markup {');
        $this->out('$__ctx = $__rt->macroScope($__pos, $__kw);');
        foreach ($params as $idx => $p) {
            // 位置参数优先 > 关键字参数 > 默认值
            $line = '$__ctx[' . var_export($p['name'], true) . '] = $__pos[' . $idx . '] ?? $__kw['
                . var_export($p['name'], true) . '] ?? ' . ($p['def'] !== null ? '(' . $this->compileTokens($p['def'])['p'] . ')' : 'null') . ';';
            $this->out($line);
        }
        $this->out('ob_start();');
        $this->stk[] = ['k' => 'macro', 'name' => $name];
    }

    /** {% from "x.html" import a, b %} */
    private function stmtFrom(): void
    {
        if (!isset($this->tk[1]) || $this->tk[1]['t'] !== 'str') {
            $this->err('from 仅支持字面量模板名');
        }
        if (!isset($this->tk[2]) || $this->tk[2]['t'] !== 'name' || $this->tk[2]['v'] !== 'import') {
            $this->err('from 缺少 import');
        }
        $map = [];
        $i = 3;
        while ($i < count($this->tk)) {
            if ($this->tk[$i]['t'] !== 'name') {
                $this->err('import 列表非法');
            }
            $orig = $this->tk[$i]['v'];
            $alias = $orig;
            $i++;
            if (isset($this->tk[$i + 1]) && $this->tk[$i]['t'] === 'name' && $this->tk[$i]['v'] === 'as'
                && $this->tk[$i + 1]['t'] === 'name') {
                $alias = $this->tk[$i + 1]['v'];
                $i += 2;
            }
            $map[$alias] = $orig;
            if (isset($this->tk[$i]) && $this->tk[$i]['t'] === 'op' && $this->tk[$i]['v'] === ',') {
                $i++;
                continue;
            }
            break;
        }
        $this->out('$__rt->importMacros(' . var_export($this->tk[1]['v'], true) . ', ' . var_export($map, true) . ', $__ctx);');
    }

    /** {% include "x.html" [ignore missing] %} */
    private function stmtInclude(): void
    {
        if (!isset($this->tk[1])) {
            $this->err('include 缺少模板名');
        }
        $t1 = $this->tk[1];
        if ($t1['t'] === 'str' && count($this->tk) === 2) {
            $this->out('$__rt->includeTpl(' . var_export($t1['v'], true) . ', $__ctx);');
            return;
        }
        // 剥离 ignore missing / with|without context 后缀
        $toks = $this->tk;
        while (count($toks) > 1) {
            $last = $toks[count($toks) - 1];
            $prev = $toks[count($toks) - 2] ?? null;
            $ok = false;
            if ($last['t'] === 'name' && in_array($last['v'], ['missing', 'context'], true)) {
                $ok = true;
            } elseif ($prev && $prev['t'] === 'name' && in_array($prev['v'], ['ignore', 'with', 'without'], true)) {
                $ok = true;
            }
            if (!$ok) {
                break;
            }
            array_pop($toks);
        }
        if ($toks[1]['t'] === 'str' && count($toks) === 2) {
            $this->out('$__rt->includeTpl(' . var_export($toks[1]['v'], true) . ', $__ctx);');
            return;
        }
        $expr = $this->compileTokens(array_slice($toks, 1));
        $this->out('$__rt->includeTpl(' . $expr['p'] . ', $__ctx);');
    }

    /** {% with a = x, b = y %} ... {% endwith %}（整块 ctx 快照恢复） */
    private function stmtWith(): void
    {
        $N = $this->sn++;
        $this->out('$__ws' . $N . ' = $__ctx;');
        if (count($this->tk) > 1) {
            // 深度 0 逗号切分
            $depth = 0;
            $start = 1;
            $parts = [];
            for ($j = 1; $j <= count($this->tk); $j++) {
                $done = $j === count($this->tk);
                if (!$done) {
                    $t = $this->tk[$j];
                    if ($t['t'] === 'op') {
                        if (in_array($t['v'], ['(', '[', '{'], true)) {
                            $depth++;
                        } elseif (in_array($t['v'], [')', ']', '}'], true)) {
                            $depth--;
                        } elseif ($depth === 0 && $t['v'] === ',') {
                            $parts[] = array_slice($this->tk, $start, $j - $start);
                            $start = $j + 1;
                        }
                    }
                    continue;
                }
                if ($start < $j) {
                    $parts[] = array_slice($this->tk, $start, $j - $start);
                }
            }
            foreach ($parts as $p) {
                if (count($p) < 3 || $p[0]['t'] !== 'name' || $p[1]['t'] !== 'op' || $p[1]['v'] !== '=') {
                    $this->err('with 赋值非法');
                }
                $expr = $this->compileTokens(array_slice($p, 2));
                $this->out('$__ctx[' . var_export($p[0]['v'], true) . '] = ' . $expr['p'] . ';');
            }
        }
        // 进入 with 作用域，与 endwith 输出的 '}' 配对
        $this->out('{');
        $this->stk[] = ['k' => 'with', 'n' => $N];
    }

    // ==================== 词法 ====================

    /** 多字符运算符（优先匹配） */
    private const OPS2 = ['==', '!=', '<=', '>=', '**'];
    private const OPS1 = '~/|.,:()[]{}+-<>=*%';

    private function lex(string $s): void
    {
        $out = [];
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }
            if ($c === "'" || $c === '"') {
                $q = $c;
                $i++;
                $buf = '';
                while ($i < $n) {
                    $ch = $s[$i];
                    if ($ch === '\\' && $i + 1 < $n) {
                        $esc = $s[$i + 1];
                        $buf .= match ($esc) {
                            'n' => "\n",
                            't' => "\t",
                            'r' => "\r",
                            default => $esc,
                        };
                        $i += 2;
                        continue;
                    }
                    if ($ch === $q) {
                        $i++;
                        break;
                    }
                    $buf .= $ch;
                    $i++;
                }
                $out[] = ['t' => 'str', 'v' => $buf];
                continue;
            }
            if ($c >= '0' && $c <= '9') {
                $j = $i;
                while ($j < $n && (($s[$j] >= '0' && $s[$j] <= '9') || $s[$j] === '.')) {
                    $j++;
                }
                $out[] = ['t' => 'num', 'v' => substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_') {
                $j = $i;
                while ($j < $n && (($s[$j] >= 'a' && $s[$j] <= 'z') || ($s[$j] >= 'A' && $s[$j] <= 'Z')
                    || ($s[$j] >= '0' && $s[$j] <= '9') || $s[$j] === '_')) {
                    $j++;
                }
                $out[] = ['t' => 'name', 'v' => substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }
            $two = $i + 1 < $n ? substr($s, $i, 2) : '';
            if ($two !== '' && in_array($two, self::OPS2, true)) {
                $out[] = ['t' => 'op', 'v' => $two];
                $i += 2;
                continue;
            }
            if (str_contains(self::OPS1, $c)) {
                $out[] = ['t' => 'op', 'v' => $c];
                $i++;
                continue;
            }
            throw new \RuntimeException("模板 {$this->tpl}: 表达式非法字符 '{$c}' 于 " . substr($s, max(0, $i - 10), 30));
        }
        $this->tk = $out;
        $this->tp = 0;
    }

    // ==================== 表达式：递归下降 ====================

    private function peek(): ?array
    {
        return $this->tk[$this->tp] ?? null;
    }

    private function peekName(string $w): bool
    {
        $t = $this->tk[$this->tp] ?? null;
        return $t !== null && $t['t'] === 'name' && $t['v'] === $w;
    }

    private function peekOp(string $w): bool
    {
        $t = $this->tk[$this->tp] ?? null;
        return $t !== null && $t['t'] === 'op' && $t['v'] === $w;
    }

    private function expectOp(string $w): void
    {
        if (!$this->peekOp($w)) {
            $t = $this->peek();
            $this->err("期望 '{$w}'，得到 " . ($t ? $t['t'] . ' ' . $t['v'] : '结尾'));
        }
        $this->tp++;
    }

    /** 三元（Jinja 内联 if，最低优先级）：a if c else b */
    private function pTernary(): array
    {
        $a = $this->pOr();
        if ($this->peekName('if')) {
            $this->tp++;
            $c = $this->pOr();
            if ($this->peekName('else')) {
                $this->tp++;
                $b = $this->pTernary();
                $p = '((' . $c['p'] . ') ? (' . $a['p'] . ') : (' . $b['p'] . '))';
            } else {
                $p = '((' . $c['p'] . ') ? (' . $a['p'] . ') : null)';
            }
            return ['p' => $p, 'b' => null];
        }
        return $a;
    }

    /** or：值语义短路（记录首个真值） */
    private function pOr(): array
    {
        $l = $this->pAnd();
        while ($this->peekName('or')) {
            $this->tp++;
            $r = $this->pAnd();
            $t = '$__t' . $this->tn++;
            $l = ['p' => '((' . $t . ' = (' . $l['p'] . ')) ? ' . $t . ' : (' . $r['p'] . '))', 'b' => null];
        }
        return $l;
    }

    /** and：短路（首假值） */
    private function pAnd(): array
    {
        $l = $this->pNot();
        while ($this->peekName('and')) {
            $this->tp++;
            $r = $this->pNot();
            $t = '$__t' . $this->tn++;
            $l = ['p' => '((' . $t . ' = (' . $l['p'] . ')) ? (' . $r['p'] . ') : ' . $t . ')', 'b' => null];
        }
        return $l;
    }

    /** not */
    private function pNot(): array
    {
        if ($this->peekName('not')) {
            $this->tp++;
            $x = $this->pNot();
            return ['p' => '!(' . $x['p'] . ')', 'b' => null];
        }
        return $this->pCmp();
    }

    /** 比较 / in / not in / is [not] test */
    private function pCmp(): array
    {
        $l = $this->pConcat();
        while (true) {
            $t = $this->peek();
            if ($t === null) {
                break;
            }
            if ($t['t'] === 'op' && in_array($t['v'], ['==', '!=', '<', '>', '<=', '>='], true)) {
                $this->tp++;
                $r = $this->pConcat();
                $l = ['p' => '(' . $l['p'] . ' ' . $t['v'] . ' ' . $r['p'] . ')', 'b' => null];
                continue;
            }
            if ($t['t'] === 'name' && $t['v'] === 'in') {
                $this->tp++;
                $r = $this->pConcat();
                $l = ['p' => '$__rt->contains(' . $l['p'] . ', ' . $r['p'] . ')', 'b' => null];
                continue;
            }
            if ($t['t'] === 'name' && $t['v'] === 'not') {
                $nx = $this->tk[$this->tp + 1] ?? null;
                if ($nx !== null && $nx['t'] === 'name' && $nx['v'] === 'in') {
                    $this->tp += 2;
                    $r = $this->pConcat();
                    $l = ['p' => '!$__rt->contains(' . $l['p'] . ', ' . $r['p'] . ')', 'b' => null];
                    continue;
                }
                break;
            }
            if ($t['t'] === 'name' && $t['v'] === 'is') {
                $this->tp++;
                $neg = false;
                if ($this->peekName('not')) {
                    $this->tp++;
                    $neg = true;
                }
                $tn = $this->peek();
                if ($tn === null || $tn['t'] !== 'name') {
                    $this->err('is 缺少测试名');
                }
                $this->tp++;
                $lname = $l['b'];
                if ($tn['v'] === 'defined' || $tn['v'] === 'undefined') {
                    $want = $tn['v'] === 'defined' ? !$neg : $neg;
                    if ($lname !== null) {
                        $p = ($want ? '' : '!') . 'array_key_exists(' . var_export($lname, true) . ', $__ctx)';
                    } else {
                        $p = ($want ? '' : '!') . '(' . $l['p'] . ' !== null)';
                    }
                } else {
                    $p = ($neg ? '!' : '') . '$__rt->test(' . var_export($tn['v'], true) . ', ' . $l['p'] . ')';
                }
                $l = ['p' => $p, 'b' => null];
                continue;
            }
            break;
        }
        return $l;
    }

    /** ~ 拼接 */
    private function pConcat(): array
    {
        $l = $this->pAdd();
        while ($this->peekOp('~')) {
            $this->tp++;
            $r = $this->pAdd();
            $l = ['p' => '$__rt->concat(' . $l['p'] . ', ' . $r['p'] . ')', 'b' => null];
        }
        return $l;
    }

    /** + - */
    private function pAdd(): array
    {
        $l = $this->pMul();
        while (true) {
            if ($this->peekOp('+')) {
                $this->tp++;
                $r = $this->pMul();
                $l = ['p' => '$__rt->add(' . $l['p'] . ', ' . $r['p'] . ')', 'b' => null];
                continue;
            }
            if ($this->peekOp('-')) {
                $this->tp++;
                $r = $this->pMul();
                $l = ['p' => '((' . $l['p'] . ') - (' . $r['p'] . '))', 'b' => null];
                continue;
            }
            break;
        }
        return $l;
    }

    /** * / % ** */
    private function pMul(): array
    {
        $l = $this->pUnary();
        while (true) {
            if ($this->peekOp('*')) {
                $this->tp++;
                $r = $this->pUnary();
                $l = ['p' => '((' . $l['p'] . ') * (' . $r['p'] . '))', 'b' => null];
                continue;
            }
            if ($this->peekOp('%')) {
                $this->tp++;
                $r = $this->pUnary();
                $l = ['p' => '((' . $l['p'] . ') % (' . $r['p'] . '))', 'b' => null];
                continue;
            }
            if ($this->peekOp('/')) {
                $this->tp++;
                $r = $this->pUnary();
                // 除零保护：模板场景返回 0 更宽容
                $l = ['p' => '((' . $r['p'] . ' == 0) ? 0 : ((' . $l['p'] . ') / (' . $r['p'] . ')))', 'b' => null];
                continue;
            }
            if ($this->peekOp('**')) {
                $this->tp++;
                $r = $this->pUnary();
                $l = ['p' => 'pow((' . $l['p'] . '), (' . $r['p'] . '))', 'b' => null];
                continue;
            }
            break;
        }
        return $l;
    }

    /** 一元负号 */
    private function pUnary(): array
    {
        if ($this->peekOp('-')) {
            $this->tp++;
            $x = $this->pUnary();
            return ['p' => '(-(' . $x['p'] . '))', 'b' => null];
        }
        if ($this->peekOp('+')) {
            $this->tp++;
            return $this->pUnary();
        }
        return $this->pPostfix();
    }

    /** 后缀链：.attr / .call / [] / [:] / |filter / (call) */
    private function pPostfix(): array
    {
        $e = $this->pAtom();
        while (true) {
            $t = $this->peek();
            if ($t === null || $t['t'] !== 'op') {
                break;
            }
            if ($t['v'] === '.') {
                $this->tp++;
                $nm = $this->peek();
                if ($nm === null || $nm['t'] !== 'name') {
                    $this->err('. 后需要属性名');
                }
                $this->tp++;
                if ($this->peekOp('(')) {
                    $this->tp++;
                    [$pos, $kw] = $this->pArgs();
                    $e = ['p' => '$__rt->callMethod(' . $e['p'] . ', ' . var_export($nm['v'], true) . ', ' . $pos . ', ' . $kw . ')', 'b' => null];
                } else {
                    $e = ['p' => '$__rt->attr(' . $e['p'] . ', ' . var_export($nm['v'], true) . ')', 'b' => null];
                }
                continue;
            }
            if ($t['v'] === '[') {
                $this->tp++;
                $a = null;
                if (!$this->peekOp(':')) {
                    $a = $this->pTernary();
                }
                if ($this->peekOp(':')) {
                    $this->tp++;
                    $b = null;
                    if (!$this->peekOp(']')) {
                        $b = $this->pTernary();
                    }
                    $this->expectOp(']');
                    $e = ['p' => '$__rt->slice(' . $e['p'] . ', ' . ($a['p'] ?? 'null') . ', ' . ($b['p'] ?? 'null') . ')', 'b' => null];
                } else {
                    $this->expectOp(']');
                    $e = ['p' => '$__rt->item(' . $e['p'] . ', ' . $a['p'] . ')', 'b' => null];
                }
                continue;
            }
            if ($t['v'] === '|') {
                $this->tp++;
                $nm = $this->peek();
                if ($nm === null || $nm['t'] !== 'name') {
                    $this->err('| 后需要过滤器名');
                }
                $this->tp++;
                if ($this->peekOp('(')) {
                    $this->tp++;
                    [$pos, $kw] = $this->pArgs();
                } else {
                    $pos = '[]';
                    $kw = '[]';
                }
                $e = ['p' => '$__rt->filt(' . var_export($nm['v'], true) . ', ' . $e['p'] . ', ' . $pos . ', ' . $kw . ')', 'b' => null];
                continue;
            }
            if ($t['v'] === '(') {
                // 裸名直接调用：f(...)
                if ($e['b'] === null) {
                    $this->err('该表达式不可调用');
                }
                $this->tp++;
                [$pos, $kw] = $this->pArgs();
                $e = ['p' => '$__rt->call(' . var_export($e['b'], true) . ', ' . $pos . ', ' . $kw . ')', 'b' => null];
                continue;
            }
            break;
        }
        return $e;
    }

    /** 调用参数表（前置 ( 已消费）：返回 [posPhp, kwPhp] */
    private function pArgs(): array
    {
        $pos = [];
        $kw = [];
        $splat = [];
        if ($this->peekOp(')')) {
            $this->tp++;
            return ['[]', '[]'];
        }
        while (true) {
            if ($this->peekOp(')')) {
                break;
            }
            if ($this->peekOp('**')) {
                $this->tp++;
                $x = $this->pTernary();
                $splat[] = $x['p'];
            } else {
                $t = $this->peek();
                $nxt = $this->tk[$this->tp + 1] ?? null;
                if ($t !== null && $t['t'] === 'name' && $nxt !== null && $nxt['t'] === 'op' && $nxt['v'] === '=') {
                    $this->tp += 2;
                    $x = $this->pTernary();
                    $kw[] = var_export($t['v'], true) . ' => ' . $x['p'];
                } else {
                    $x = $this->pTernary();
                    $pos[] = $x['p'];
                }
            }
            if ($this->peekOp(',')) {
                $this->tp++;
                continue;
            }
            break;
        }
        if (!$this->peekOp(')')) {
            $this->err('参数表缺少 )');
        }
        $this->tp++;
        $posPhp = '[' . implode(', ', $pos) . ']';
        if ($kw === [] && $splat === []) {
            return [$posPhp, '[]'];
        }
        $kwPhp = '$__rt->mergeKw(' . implode(', ', array_merge($splat, ['[' . implode(', ', $kw) . ']'])) . ')';
        return [$posPhp, $kwPhp];
    }

    /** 原子：数字/字符串/名字/布尔/None/()/[]/{} */
    private function pAtom(): array
    {
        $t = $this->peek();
        if ($t === null) {
            $this->err('表达式意外结束');
        }
        switch ($t['t']) {
            case 'num':
                $this->tp++;
                return ['p' => $t['v'], 'b' => null];
            case 'str':
                $this->tp++;
                return ['p' => var_export($t['v'], true), 'b' => null];
            case 'name':
                $this->tp++;
                switch ($t['v']) {
                    case 'true':
                    case 'True':
                        return ['p' => 'true', 'b' => null];
                    case 'false':
                    case 'False':
                        return ['p' => 'false', 'b' => null];
                    case 'none':
                    case 'None':
                    case 'null':
                        return ['p' => 'null', 'b' => null];
                    default:
                        // 外层必须加括号：?? 优先级低于比较/算术运算符，
                        // 否则 "$__ctx['x'] ?? null == 'error'" 会被解析为 "$__ctx['x'] ?? (null == 'error')"
                        return ['p' => '($__ctx[' . var_export($t['v'], true) . '] ?? null)', 'b' => $t['v']];
                }
            case 'op':
                if ($t['v'] === '(') {
                    $this->tp++;
                    $items = [];
                    while (true) {
                        $items[] = $this->pTernary()['p'];
                        if ($this->peekOp(',')) {
                            $this->tp++;
                            if ($this->peekOp(')')) {
                                break;
                            }
                            continue;
                        }
                        break;
                    }
                    $this->expectOp(')');
                    // 单元素括号分组，多元素元组
                    return ['p' => count($items) === 1 ? '(' . $items[0] . ')' : '[' . implode(', ', $items) . ']', 'b' => null];
                }
                if ($t['v'] === '[') {
                    $this->tp++;
                    $items = [];
                    if ($this->peekOp(']')) {
                        $this->tp++;
                        return ['p' => '[]', 'b' => null];
                    }
                    while (true) {
                        $items[] = $this->pTernary()['p'];
                        if ($this->peekOp(',')) {
                            $this->tp++;
                            continue;
                        }
                        break;
                    }
                    $this->expectOp(']');
                    return ['p' => '[' . implode(', ', $items) . ']', 'b' => null];
                }
                if ($t['v'] === '{') {
                    $this->tp++;
                    $pairs = [];
                    if ($this->peekOp('}')) {
                        $this->tp++;
                        return ['p' => '[]', 'b' => null];
                    }
                    while (true) {
                        $k = $this->peek();
                        if ($k === null || ($k['t'] !== 'name' && $k['t'] !== 'str')) {
                            $this->err('字典键必须是名字或字符串');
                        }
                        $this->tp++;
                        $this->expectOp(':');
                        $v = $this->pTernary();
                        $pairs[] = var_export($k['v'], true) . ' => ' . $v['p'];
                        if ($this->peekOp(',')) {
                            $this->tp++;
                            continue;
                        }
                        break;
                    }
                    $this->expectOp('}');
                    return ['p' => '[' . implode(', ', $pairs) . ']', 'b' => null];
                }
                break;
        }
        $this->err('无法解析的表达式项: ' . $t['t'] . ' "' . $t['v'] . '"');
    }
}
