<?php
declare(strict_types=1);

namespace Blog\Template;

/**
 * 标记为安全的 HTML 片段：View::e() 遇到它不再做 HTML 转义。
 * 对照 Jinja2 的 Markup —— 宏（macro）渲染出的内容本身就是已生成的 HTML，
 * 其中用户数据已由宏体内的 {{ }} 各自转义过，外层不应再次转义。
 */
final class Markup
{
    public function __construct(private readonly string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
