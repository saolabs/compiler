<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

use Saola\Compiler\CompileException;

/**
 * Tiện ích HTML dùng chung cho CẢ HAI emitter.
 *
 * Ghép thẻ nhiều dòng phải giống hệt nhau ở sao2blade và sao2js. Sửa một phía
 * thôi là id hydrate lệch nhau — đúng lớp lỗi mà cổng `marker-sync` sinh ra để
 * bắt. Để chung một chỗ thì không thể lệch.
 */
final class Html
{
    /**
     * Thẻ rỗng — không có thẻ đóng, không tăng độ sâu khi dò.
     *
     * Nguồn DUY NHẤT: `Ast\Parser` và `Hydration\BladeHydrateProcessor` đều
     * tham chiếu về đây. Hai bản sao lệch nhau là id hydrate lệch theo.
     *
     * @var array<string, true>
     */
    public const VOID_ELEMENTS = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true,
        'embed' => true, 'hr' => true, 'img' => true, 'input' => true,
        'link' => true, 'meta' => true, 'param' => true, 'source' => true,
        'track' => true, 'wbr' => true,
    ];

    /**
     * Ruột là văn bản nguyên vẹn — `<` bên trong KHÔNG mở thẻ.
     *
     * Giữ khớp với `Ast\Parser::RAW_CONTENT_ELEMENTS`.
     *
     * @var array<string, true>
     */
    private const RAW_CONTENT_ELEMENTS = [
        'textarea' => true, 'title' => true, 'script' => true, 'style' => true,
    ];

    /**
     * Directive viết trên thẻ → directive khối tương ứng.
     *
     * Tên `#` nào không nằm ở đây là LỖI, không được rơi lại thành thuộc tính
     * HTML thường: `parseElementAttributes` nhảy qua dấu `#` rồi khớp phần còn
     * lại như attr bình thường, nên `#fi="x"` sẽ lặng lẽ thành `fi="x"` trong
     * HTML. Xem docs/SAO_ELEMENT_DIRECTIVES_RFC.md §7 BB-2.
     *
     * @var array<string, bool> [tên directive => có nhận giá trị không]
     */
    private const TAG_DIRECTIVES = [
        'if' => true, 'elseif' => true, 'else' => false,
        'switch' => true, 'case' => true, 'default' => false,
        'foreach' => true, 'for' => true, 'while' => true,
        'key' => true,
    ];

    /**
     * Directive lặp — bọc thẻ giống `#if`, nhưng nhận kèm `#key`.
     *
     * @var array<string, true>
     */
    private const LOOP_DIRECTIVES = ['foreach' => true, 'for' => true, 'while' => true];

    /**
     * Loại (slot) của từng directive. Một thẻ mang tối đa một directive mỗi
     * loại; khác loại thì kết hợp được. Thứ tự bọc, ngoài → trong:
     *
     *   #case/#default > #elseif/#else > loop (+#key) > #if > #switch
     *
     * `#if` đứng cạnh loop thì nằm TRONG loop (lọc từng phần tử); không có
     * loop thì `#if` là đầu chuỗi nhánh như thường. `#elseif`/`#else` luôn bọc
     * loop để còn nối được với thẻ anh em. `#switch` bọc ruột nên nằm trong cùng.
     *
     * @var array<string, string>
     */
    private const TAG_DIRECTIVE_SLOTS = [
        'case' => 'case', 'default' => 'case',
        'if' => 'branch', 'elseif' => 'branch', 'else' => 'branch',
        'foreach' => 'loop', 'for' => 'loop', 'while' => 'loop',
        'switch' => 'switch',
        'key' => 'key',
    ];

    private function __construct()
    {
    }

    /** Fill an empty bound textarea for SSR, without inventing a two-way binding. */
    public static function normalizeTextareaBindings(string $template): string
    {
        $scan = Re::replaceCallback(
            '/\{\{--[\s\S]*?--\}\}|@verbatim\b[\s\S]*?@endverbatim\b|\{!![\s\S]*?!!\}|\{\{[\s\S]*?\}\}/i',
            static fn(array $m): string => preg_replace('/[^\n]/', ' ', $m[0]),
            $template,
        );
        $insertions = [];
        $pos = 0;
        while (preg_match('~<([a-zA-Z][\w-]*)\b~', $scan, $tag, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $tag[0][1];
            $name = strtolower($tag[1][0]);
            $openEnd = self::tagEnd($template, $start);
            if ($openEnd === null) break;
            $pos = $openEnd;
            if (!isset(self::RAW_CONTENT_ELEMENTS[$name])) continue;
            if (!preg_match('~</\s*' . $name . '\s*>~i', $scan, $close, PREG_OFFSET_CAPTURE, $openEnd)) break;
            $closeStart = $close[0][1];
            $pos = $closeStart + strlen($close[0][0]);
            if ($name !== 'textarea' || trim(substr($template, $openEnd, $closeStart - $openEnd)) !== '') continue;
            $open = substr($template, $start, $openEnd - $start);
            // Ignore attribute strings that merely mention @bind.
            if (!preg_match('~"(?:\\\\.|[^"\\\\])*"(*SKIP)(*F)|\'(?:\\\\.|[^\'\\\\])*\'(*SKIP)(*F)|@(?:bind|val)\s*\(~s', $open, $bind, PREG_OFFSET_CAPTURE)) continue;
            $paren = $bind[0][1] + strlen($bind[0][0]) - 1;
            [$expr] = Balanced::extractParensAt($open, $paren);
            if (trim($expr) !== '') $insertions[] = [$openEnd, $closeStart, '{{ ' . trim($expr) . ' }}'];
        }
        foreach (array_reverse($insertions) as [$start, $end, $text]) {
            $template = substr($template, 0, $start) . $text . substr($template, $end);
        }
        return $template;
    }

    /**
     * Ghép các dòng của một thẻ MỞ thành một dòng.
     *
     * Chỉ thay ký tự xuống dòng NẰM TRONG thẻ mở bằng khoảng trắng — nội dung
     * giữa các thẻ không bị đụng, nên HTML không đổi nghĩa (khoảng trắng giữa
     * các thuộc tính là vô nghĩa).
     *
     * Guard chống nhận nhầm: `{{ n<m }}` có `<m` trông như thẻ mở. Yêu cầu sau
     * tên thẻ phải là `>`, `/`, hoặc khoảng trắng RỒI tới ký tự mở đầu thuộc
     * tính hợp lệ. `<m }}` có `}` nên bị loại.
     */
    public static function joinMultilineOpenTags(string $template): string
    {
        $out = '';
        $i = 0;
        $length = strlen($template);

        while ($i < $length) {
            if ($template[$i] !== '<' || ! Re::match('/^<([a-zA-Z][\w-]*)/', substr($template, $i), $m)) {
                $out .= $template[$i];
                $i++;
                continue;
            }

            $afterName = $i + strlen($m[0]);
            $next = $template[$afterName] ?? '';

            if ($next !== '>' && $next !== '/' && ! ctype_space($next)) {
                $out .= $template[$i];
                $i++;
                continue;
            }

            if (ctype_space($next)) {
                $probe = $afterName;
                while ($probe < $length && ctype_space($template[$probe])) {
                    $probe++;
                }

                if (! Re::match('/^[A-Za-z_@:>\/]/', substr($template, $probe, 1))) {
                    $out .= $template[$i];
                    $i++;
                    continue;
                }
            }

            // Quét tới '>' đóng, gộp xuống dòng thành khoảng trắng
            $buffer = $m[0];
            $j = $afterName;
            $parenDepth = 0;
            $quote = '';
            $closed = false;

            while ($j < $length) {
                $ch = $template[$j];

                if ($quote !== '') {
                    $buffer .= $ch;
                    if ($ch === $quote) {
                        $quote = '';
                    }
                    $j++;
                    continue;
                }

                if ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                    $buffer .= $ch;
                    $j++;
                    continue;
                }

                if ($ch === '(') {
                    $parenDepth++;
                } elseif ($ch === ')' && $parenDepth > 0) {
                    $parenDepth--;
                }

                if ($ch === '>' && $parenDepth === 0) {
                    $buffer .= $ch;
                    $j++;
                    $closed = true;
                    break;
                }

                $buffer .= $ch === "\n" ? ' ' : $ch;
                $j++;
            }

            if (! $closed) {
                // Không tìm thấy '>' — nhiều khả năng không phải thẻ. Giữ nguyên.
                $out .= $template[$i];
                $i++;
                continue;
            }

            $out .= $buffer;
            $i = $j;
        }

        return $out;
    }

    /**
     * Directive điều khiển luồng: mỗi cái PHẢI đứng riêng một dòng.
     *
     * Xếp dài trước ngắn — `@endforeach` phải thử trước `@endfor`, `@elseif`
     * trước `@else`, nếu không tên ngắn nuốt mất tên dài.
     *
     * @var list<array{0: string, 1: bool}> [tên, có-ngoặc-không]
     */
    private const CONTROL_DIRECTIVES = [
        ['@endwrapper', false], ['@endwrap', false], ['@endsection', false], ['@endblock', false],
        ['@endforeach', false], ['@endforelse', false], ['@endswitch', false],
        ['@endunless', false], ['@endwhile', false], ['@endempty', false],
        ['@endisset', false], ['@endfor', false], ['@endif', false],
        ['@elseif', true], ['@else', false],
        ['@foreach', true], ['@forelse', true], ['@empty', true],
        ['@switch', true], ['@unless', true], ['@while', true],
        ['@isset', true], ['@case', true], ['@for', true], ['@if', true],
        ['@default', false], ['@break', false],
        // @key nuốt trọn dòng y như @if: `@key(i.id)<li>` làm mất <li> ở sao2js.
        ['@key', true],
        // @wrapper cũng vậy; đặt sau @while để không đụng tiền tố nào.
        ['@wrapper', false], ['@wrap', false],
        // @section inline cũng mất nội dung ở sao2js y như @if.
        ['@section', true], ['@block', true],
        // @include: Blade xử lý được inline, sao2js thì không — `tryDirective`
        // đòi directive đứng ĐẦU dòng, nên `<div>@include('x')</div>` ra chữ
        // "@include('x')" nguyên văn ở client trong khi server render đúng.
        // Đặt CUỐI: `@includeIf` không được khớp nhầm (chặn bởi kiểm alnum ở
        // matchControlDirective), còn `@importInclude` khác tiền tố nên vô can.
        ['@include', true],
    ];

    /**
     * Tách directive điều khiển ra dòng riêng.
     *
     *     @if(x)<span>a</span>@endif   →   @if(x)
     *                                      <span>a</span>
     *                                      @endif
     *
     * Cả hai emitter đều xử lý THEO DÒNG: handler nuốt trọn dòng chứa directive
     * và chỉ gom nội dung từ các dòng sau. Nội dung nằm cùng dòng vì thế bị bỏ
     * hẳn ở sao2js, còn sao2blade thì render nhưng KHÔNG cấp hydrate id —
     * SSR ≠ CSR, đúng bất biến I2 mà dự án chống (docs/05-roadmap.md §14).
     *
     * Chuẩn hoá ở một chỗ dùng chung thay vì dạy từng handler cách xử lý phần
     * đuôi: sửa một lần, cả @if/@foreach/@for/@while/@switch cùng đúng, và hai
     * emitter không thể lệch nhau.
     *
     * Với view viết bình thường (directive đã đứng riêng dòng) đây là no-op —
     * chỉ chèn xuống dòng khi thật sự có nội dung dính vào.
     */
    public static function splitInlineDirectives(string $template): string
    {
        // Comment và @verbatim là văn bản nguyên văn: trang docs in ví dụ
        // `@if(...)` sẽ bị tách dòng, làm hỏng đúng thứ nó đang mô tả.
        $regions = [];
        $masked = Re::replaceCallback(
            '/\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b/si',
            static function (array $m) use (&$regions): string {
                $regions[] = $m[0];

                return '__SAO_SPLIT_SKIP_' . (count($regions) - 1) . '__';
            },
            $template,
        );

        $out = '';
        $i = 0;
        $n = strlen($masked);
        $inTag = false;
        $quote = '';
        $parens = 0;

        while ($i < $n) {
            $ch = $masked[$i];

            // Trong thẻ HTML thì @class/@click/@if là directive THUỘC TÍNH —
            // tách ra dòng riêng sẽ xé nát thẻ.
            if ($inTag) {
                if ($quote !== '') {
                    if ($ch === $quote) {
                        $quote = '';
                    }
                } elseif ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                } elseif ($ch === '(') {
                    $parens++;
                } elseif ($ch === ')' && $parens > 0) {
                    $parens--;
                } elseif ($ch === '>' && $parens === 0) {
                    // '>' TRONG ngoặc là toán tử so sánh, không phải đóng thẻ:
                    // `<div @if(x>0) ...>` — đây đúng bug §8② lặp lại.
                    $inTag = false;
                }

                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === '<' && preg_match('/^<\/?[a-zA-Z]/', substr($masked, $i, 3)) === 1) {
                $inTag = true;
                $parens = 0;
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch !== '@') {
                $out .= $ch;
                $i++;
                continue;
            }

            [$end, $matched] = self::matchControlDirective($masked, $i);

            if (! $matched) {
                $out .= $ch;
                $i++;
                continue;
            }

            // Có nội dung TRƯỚC trên cùng dòng → xuống dòng trước directive
            if (self::hasContentBefore($out)) {
                $out .= "\n";
            }

            $out .= self::flattenDirectiveArgs(substr($masked, $i, $end - $i));
            $i = $end;

            // Có nội dung SAU trên cùng dòng → xuống dòng sau directive
            if (self::hasContentAfter($masked, $i)) {
                $out .= "\n";
            }
        }

        foreach ($regions as $index => $original) {
            $out = str_replace('__SAO_SPLIT_SKIP_' . $index . '__', $original, $out);
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: bool} [vị trí kết thúc, có khớp không]
     */
    /**
     * Gộp đối số trải nhiều dòng của một directive về MỘT dòng.
     *
     *     @include('a.b', {          →  @include('a.b', { x: n, on$edit: f })
     *         x: n,
     *         on$edit: f
     *     })
     *
     * Cả hai emitter đọc theo DÒNG, nên directive xuống dòng chỉ khớp được
     * mảnh đầu: sao2js bỏ qua cả khối, sao2blade để lại một `@include(` cụt.
     *
     * Chỉ đụng phần TRONG ngoặc — đó là biểu thức, không phải nội dung — nên
     * không sinh thêm hay mất đi text node nào. Xuống dòng cùng phần thụt lề
     * theo sau gộp thành ĐÚNG một khoảng trắng để hai đích ra chuỗi giống hệt.
     * Nội dung trong nháy giữ nguyên.
     */
    private static function flattenDirectiveArgs(string $directive): string
    {
        if (! str_contains($directive, "\n")) {
            return $directive;
        }

        $out = '';
        $depth = 0;
        $quote = '';
        $length = strlen($directive);

        for ($i = 0; $i < $length; $i++) {
            $ch = $directive[$i];

            if ($quote !== '') {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $length) {
                    $out .= $directive[++$i];
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($ch === '"' || $ch === "'" || $ch === '`') {
                $quote = $ch;
                $out .= $ch;
                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
            } elseif (($ch === "\n" || $ch === "\r") && $depth > 0) {
                while ($i + 1 < $length && ($directive[$i + 1] === ' ' || $directive[$i + 1] === "\t"
                    || $directive[$i + 1] === "\n" || $directive[$i + 1] === "\r")) {
                    $i++;
                }
                $out .= ' ';
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }

    private static function matchControlDirective(string $text, int $pos): array
    {
        foreach (self::CONTROL_DIRECTIVES as [$name, $takesParens]) {
            $len = strlen($name);

            if (strncasecmp(substr($text, $pos, $len), $name, $len) !== 0) {
                continue;
            }

            // `@iffy` không phải `@if`; `@forx` không phải `@for`.
            $after = $text[$pos + $len] ?? '';
            if ($after !== '' && (ctype_alnum($after) || $after === '_')) {
                continue;
            }

            if (! $takesParens) {
                return [$pos + $len, true];
            }

            $probe = $pos + $len;
            while ($probe < strlen($text) && ($text[$probe] === ' ' || $text[$probe] === "\t")) {
                $probe++;
            }

            if (($text[$probe] ?? '') !== '(') {
                // `@empty` / `@default` / `@break` dùng được cả kiểu không ngoặc
                return [$pos + $len, true];
            }

            [, $end] = Balanced::extractParensAt($text, $probe);

            return [$end, true];
        }

        return [$pos, false];
    }

    /** Trên dòng hiện tại của `$out` đã có ký tự khác khoảng trắng chưa? */
    private static function hasContentBefore(string $out): bool
    {
        $nl = strrpos($out, "\n");
        $line = $nl === false ? $out : substr($out, $nl + 1);

        return trim($line) !== '';
    }

    /** Sau vị trí `$pos`, phần còn lại của dòng có gì khác khoảng trắng không? */
    private static function hasContentAfter(string $text, int $pos): bool
    {
        $nl = strpos($text, "\n", $pos);
        $rest = $nl === false ? substr($text, $pos) : substr($text, $pos, $nl - $pos);

        return trim($rest) !== '';
    }

    /**
     * Nội dung bên trong thẻ `<$tag>` ĐẦU TIÊN, khớp thẻ đóng theo ĐỘ SÂU.
     *
     * Regex `<template>([\s\S]*?)<\/template>` non-greedy dừng ở `</template>`
     * ĐẦU TIÊN — với `<template>` lồng nhau thì đó là thẻ đóng của thẻ TRONG,
     * nên nội dung bị cắt cụt và thẻ đóng ngoài rơi mất. Blade sinh ra thiếu
     * `</template>`, còn id thì lệch hẳn so với sao2js.
     *
     * `<template>` lồng nhau là HTML hợp lệ (nó là element thật), chỉ thẻ
     * NGOÀI CÙNG mới là thẻ bọc của Saola.
     *
     * @return string|null null nếu không có thẻ mở, hoặc không tìm được thẻ đóng khớp
     */
    public static function innerOfFirstTag(string $source, string $tag): ?string
    {
        $open = '<' . $tag . '>';
        $close = '</' . $tag . '>';

        $start = strpos($source, $open);

        if ($start === false) {
            return null;
        }

        $innerStart = $start + strlen($open);
        $depth = 1;
        $cursor = $innerStart;

        while ($depth > 0) {
            $nextOpen = strpos($source, $open, $cursor);
            $nextClose = strpos($source, $close, $cursor);

            if ($nextClose === false) {
                return null;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $cursor = $nextOpen + strlen($open);
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return substr($source, $innerStart, $nextClose - $innerStart);
            }

            $cursor = $nextClose + strlen($close);
        }

        return null;
    }

    // ── Directive viết trên thẻ (`#if`, `#elseif`, `#else`) ──────────────

    /**
     * Hạ directive viết trên thẻ về directive khối tương ứng.
     *
     *     <a #if="cond">A</a>   →   @if(cond)<a>A</a>@endif
     *
     * PHẢI chạy giữa `joinMultilineOpenTags()` và `splitInlineDirectives()`:
     *
     * - SAU cái đầu vì thẻ mở trải nhiều dòng lúc ấy đã gom về một dòng, nên
     *   `#if` không bao giờ nằm cách tên thẻ bởi một xuống dòng.
     * - TRƯỚC cái sau vì `@if(...)` phát ra dính liền `<a`, và chính
     *   `splitInlineDirectives` đẩy nó xuống dòng riêng — cả hai emitter đọc
     *   theo DÒNG nên directive dính nội dung sẽ mất ở sao2js.
     *
     * Cùng một hàm chạy cho cả hai traversal nên id parity đúng theo cấu tạo.
     * Chi tiết: docs/SAO_ELEMENT_DIRECTIVES_RFC.md §4.
     */
    public static function expandTagDirectives(string $template): string
    {
        if (! str_contains($template, '#')) {
            return $template;
        }

        // Che vùng nguyên văn TRƯỚC khi dò. `splitInlineDirectives` che
        // `{{-- --}}` và `@verbatim` nhưng bước này chạy trước nó nên phải tự
        // làm — và phải che thêm `{{ }}`/`{!! !!}` mà nó không che, vì
        // `{{ '</div>' }}` là một thẻ đóng giả.
        $regions = [];
        $masked = Re::replaceCallback(
            '/\{\{--[\s\S]*?--\}\}|@verbatim\b[\s\S]*?@endverbatim\b|\{!![\s\S]*?!!\}|\{\{[\s\S]*?\}\}/i',
            static function (array $m) use (&$regions): string {
                $regions[] = $m[0];

                return '__SAO_EXPAND_' . (count($regions) - 1) . '__';
            },
            $template,
        );

        $out = self::expandRegion($masked);

        foreach ($regions as $index => $original) {
            $out = str_replace('__SAO_EXPAND_' . $index . '__', $original, $out);
        }

        return $out;
    }

    /**
     * Hạ `#name` do ứng dụng/thư viện đăng ký (Sao::tag / Sao::attr) về `.sao`.
     *
     * Chạy TRƯỚC preprocessor, trong DirectiveRegistry::transform: output là
     * `.sao` nên đi qua đúng mọi bước như code viết tay. `#` dựng sẵn (`#if`,
     * `#foreach`…) và tên lạ được để nguyên cho `expandTagDirectives` xử lý/báo lỗi.
     * Luật vị trí giống hệt `takeTagDirective`: chỉ ở vị trí tên thuộc tính.
     *
     * @param array<string, \Saola\Compiler\Directive\TagDirective> $defs
     */
    public static function expandUserTagDirectives(string $template, array $defs): string
    {
        if ($defs === [] || ! str_contains($template, '#')) {
            return $template;
        }

        $regions = [];
        $masked = Re::replaceCallback(
            '/\{\{--[\s\S]*?--\}\}|@verbatim\b[\s\S]*?@endverbatim\b|\{!![\s\S]*?!!\}|\{\{[\s\S]*?\}\}/i',
            static function (array $m) use (&$regions): string {
                $regions[] = $m[0];

                return '__SAO_UEXPAND_' . (count($regions) - 1) . '__';
            },
            $template,
        );

        $out = self::expandUserRegion($masked, $defs);

        foreach ($regions as $index => $original) {
            $out = str_replace('__SAO_UEXPAND_' . $index . '__', $original, $out);
        }

        return $out;
    }

    /** True nếu `#name` là directive dựng sẵn của compiler (tầng T0). */
    public static function isBuiltinTagDirective(string $name): bool
    {
        return array_key_exists(strtolower($name), self::TAG_DIRECTIVES);
    }

    /** @param array<string, \Saola\Compiler\Directive\TagDirective> $defs */
    private static function expandUserRegion(string $s, array $defs): string
    {
        $out = '';
        $i = 0;
        $length = strlen($s);

        while ($i < $length) {
            $lt = strpos($s, '<', $i);
            if ($lt === false) {
                return $out . substr($s, $i);
            }

            $out .= substr($s, $i, $lt - $i);

            if (! Re::match('~^<([a-zA-Z][\w-]*)~', substr($s, $lt, 64), $m)) {
                $out .= '<';
                $i = $lt + 1;
                continue;
            }

            $tag = strtolower($m[1]);
            $openEnd = self::tagEnd($s, $lt);
            if ($openEnd === null) {
                $out .= '<';
                $i = $lt + 1;
                continue;
            }

            if (isset(self::RAW_CONTENT_ELEMENTS[$tag])) {
                $i = self::skipRawContent($s, $openEnd, $tag);
                $out .= substr($s, $lt, $i - $lt);
                continue;
            }

            [$openTag, $wrap, $expr] = self::takeUserTagDirectives(substr($s, $lt, $openEnd - $lt), $tag, $defs, self::lineOf($s, $lt));

            if ($wrap === null) {
                $out .= $openTag;
                $i = $openEnd;
                continue;
            }

            if (isset(self::VOID_ELEMENTS[$tag]) || str_ends_with(rtrim(substr($openTag, 0, -1)), '/')) {
                $element = $openTag;
                $i = $openEnd;
            } else {
                $closeStart = self::findMatchingClose($s, $openEnd, $tag);
                if ($closeStart === null) {
                    throw new CompileException(
                        "`<{$tag}>` mang `#{$wrap->name}` nhưng thiếu `</{$tag}>`",
                        sourceLine: self::lineOf($s, $lt),
                    );
                }
                $i = strpos($s, '>', $closeStart) + 1;
                $element = $openTag
                    . self::expandUserRegion(substr($s, $openEnd, $closeStart - $openEnd), $defs)
                    . substr($s, $closeStart, $i - $closeStart);
            }

            [$before, $after] = $wrap->wrap($expr, $tag);
            $out .= $before . $element . $after;
        }

        return $out;
    }

    /**
     * Bóc `#name` người dùng khỏi thẻ mở: attr thay tại chỗ, wrap gỡ ra để bọc.
     *
     * @param array<string, \Saola\Compiler\Directive\TagDirective> $defs
     * @return array{0: string, 1: ?\Saola\Compiler\Directive\TagDirective, 2: string} [thẻ mở mới, directive bọc, biểu thức của nó]
     */
    private static function takeUserTagDirectives(string $openTag, string $tag, array $defs, int $line): array
    {
        if (! str_contains($openTag, '#')) {
            return [$openTag, null, ''];
        }

        $out = '';
        $wrap = null;
        $wrapExpr = '';
        $builtin = null;
        $i = 0;
        $length = strlen($openTag);
        $quote = '';
        $parens = 0;

        while ($i < $length) {
            $ch = $openTag[$i];

            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                }
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '(') {
                $parens++;
            } elseif ($ch === ')' && $parens > 0) {
                $parens--;
            }

            if ($ch !== '#' || $parens !== 0 || $i === 0 || ! ctype_space($openTag[$i - 1])
                || ! Re::match('/^#([a-zA-Z][\w-]*)/', substr($openTag, $i), $m)) {
                $out .= $ch;
                $i++;
                continue;
            }

            $name = strtolower($m[1]);
            $def = $defs[$name] ?? null;
            if ($def === null) {
                // Dựng sẵn hoặc tên lạ: để nguyên cho expandTagDirectives.
                if ($name !== 'key' && self::isBuiltinTagDirective($name)) {
                    $builtin = $name;
                }
                $out .= $m[0];
                $i += strlen($m[0]);
                continue;
            }

            $i += strlen($m[0]);
            $raw = null;
            if (Re::match('/^\s*=\s*("[^"]*"|\'[^\']*\')/', substr($openTag, $i), $v)) {
                $raw = substr($v[1], 1, -1);
                $i += strlen($v[0]);
            }
            if ($def->value && ($raw === null || trim($raw) === '')) {
                throw new CompileException("`#{$name}` trên `<{$tag}>` thiếu biểu thức", sourceLine: $line);
            }
            if (! $def->value && $raw !== null) {
                throw new CompileException("`#{$name}` trên `<{$tag}>` không nhận giá trị", sourceLine: $line);
            }
            $expr = trim($raw ?? '');

            if ($def->wraps) {
                if ($wrap !== null) {
                    throw new CompileException("`<{$tag}>` mang hai directive bọc: `#{$wrap->name}` và `#{$name}`", sourceLine: $line);
                }
                $wrap = $def;
                $wrapExpr = $expr;
                // Khoảng trắng đứng trước token đi cùng nó; phần sau giữ nguyên.
                $out = rtrim($out);
                continue;
            }

            $out .= $def->attributes($expr, $tag);
        }

        if ($wrap !== null && $builtin !== null) {
            throw new CompileException(
                "`<{$tag}>` mang cả `#{$wrap->name}` lẫn `#{$builtin}`; bọc thêm một thẻ ngoài để tách hai directive",
                sourceLine: $line,
            );
        }

        return [$out, $wrap, $wrapExpr];
    }

    /** Quét một vùng, hạ mọi directive `#` gặp được. Đệ quy vào ruột thẻ mang directive. */
    private static function expandRegion(string $s): string
    {
        $out = '';
        $i = 0;
        $length = strlen($s);

        while ($i < $length) {
            $lt = strpos($s, '<', $i);
            if ($lt === false) {
                return $out . substr($s, $i);
            }

            $out .= substr($s, $i, $lt - $i);

            if (! Re::match('~^<([a-zA-Z][\w-]*)~', substr($s, $lt, 64), $m)) {
                $out .= '<';
                $i = $lt + 1;
                continue;
            }

            $tag = strtolower($m[1]);
            $openEnd = self::tagEnd($s, $lt);
            if ($openEnd === null) {
                $out .= '<';
                $i = $lt + 1;
                continue;
            }

            // Ruột rawtext là văn bản: nhả nguyên CẢ THẺ LẪN RUỘT, không quét
            // `#` bên trong.
            if (isset(self::RAW_CONTENT_ELEMENTS[$tag])) {
                $i = self::skipRawContent($s, $openEnd, $tag);
                $out .= substr($s, $lt, $i - $lt);
                continue;
            }

            [$openTag, $dirs] = self::takeTagDirective(substr($s, $lt, $openEnd - $lt), $tag);

            if ($dirs === []) {
                $out .= $openTag;
                $i = $openEnd;
                continue;
            }

            if (isset($dirs['case'])) {
                throw new CompileException(
                    "`#{$dirs['case'][0]}` trên `<{$tag}>` không có `#switch` bao ngoài",
                    sourceLine: self::lineOf($s, $lt),
                );
            }

            $branch = $dirs['branch'][0] ?? null;
            if ($branch === 'elseif' || $branch === 'else') {
                throw new CompileException(
                    "`#{$branch}` trên `<{$tag}>` không có `#if` bao ngoài",
                    sourceLine: self::lineOf($s, $lt),
                );
            }

            // `#if` không đi cùng loop → đầu chuỗi nhánh. Có loop thì `#if` nằm
            // trong loop (lọc từng phần tử), chuỗi đóng ngay trong vòng lặp.
            if ($branch === 'if' && ! isset($dirs['loop'])) {
                [$chunk, $i] = self::expandChain($s, $lt, $tag, $openTag, $dirs, $openEnd);
            } else {
                [$chunk, $i] = self::renderTagged($s, $lt, $tag, $openTag, $dirs, $openEnd, true);
            }
            $out .= $chunk;
        }

        return $out;
    }

    /**
     * Hạ trọn một chuỗi `#if` → `#elseif`* → `#else`?.
     *
     * Các nhánh phải là element sibling liền kề, chỉ được cách nhau bởi
     * khoảng trắng. Khoảng trắng đó giữ nguyên — nó là text node thường.
     * Mỗi nhánh vẫn mang được loop/`#switch`; nhánh bọc chúng.
     *
     * @param array<string, array{0: string, 1: ?string}> $dirs
     * @return array{0: string, 1: int} [đoạn đã hạ, vị trí quét tiếp]
     */
    private static function expandChain(string $s, int $lt, string $tag, string $openTag, array $dirs, int $openEnd): array
    {
        $out = '@if(' . $dirs['branch'][1] . ')';

        while (true) {
            [$element, $after] = self::renderTagged($s, $lt, $tag, $openTag, $dirs, $openEnd, false);
            $out .= $element;

            $next = self::nextChainBranch($s, $after);
            if ($next === null) {
                return [$out . '@endif', $after];
            }

            [$lt, $tag, $openTag, $dirs, $openEnd, $gap] = $next;
            $out .= $gap . ($dirs['branch'][0] === 'else' ? '@else' : '@elseif(' . $dirs['branch'][1] . ')');
        }
    }

    /**
     * Phát một element theo các directive nó mang (trừ `#case`/`#default` và
     * nhánh chuỗi — chỗ gọi lo), thứ tự ngoài → trong: loop > `#if` > `#switch`.
     *
     * `#key` đi kèm được phát thành `@key(...)` ngay sau directive lặp; nó là
     * directive riêng đứng trong khối lặp (Ast\Parser::tryDirective), không phải
     * tham số của `@foreach`.
     *
     * @param array<string, array{0: string, 1: ?string}> $dirs
     * @param bool $withIf phát `#if` tại đây (false khi `#if` là đầu chuỗi nhánh)
     * @return array{0: string, 1: int} [đoạn đã hạ, vị trí quét tiếp]
     */
    private static function renderTagged(string $s, int $lt, string $tag, string $openTag, array $dirs, int $openEnd, bool $withIf): array
    {
        if (isset($dirs['switch'])) {
            [$out, $after] = self::expandSwitch($s, $lt, $tag, $openTag, $dirs['switch'][1], $openEnd);
        } else {
            [$out, $after] = self::takeElement($s, $lt, $tag, $openTag, $openEnd);
        }

        if ($withIf && ($dirs['branch'][0] ?? null) === 'if') {
            $out = '@if(' . $dirs['branch'][1] . ')' . $out . '@endif';
        }

        if (isset($dirs['loop'])) {
            [$loop, $expr] = $dirs['loop'];
            $head = '@' . $loop . '(' . $expr . ')';
            if (isset($dirs['key'])) {
                $head .= '@key(' . $dirs['key'][1] . ')';
            }
            $out = $head . $out . '@end' . $loop;
        }

        return [$out, $after];
    }

    /**
     * Lấy trọn một element (thẻ mở + ruột đã hạ đệ quy + thẻ đóng).
     *
     * @return array{0: string, 1: int} [nguyên văn element, vị trí ngay sau nó]
     */
    private static function takeElement(string $s, int $lt, string $tag, string $openTag, int $openEnd): array
    {
        if (isset(self::VOID_ELEMENTS[$tag]) || str_ends_with(rtrim(substr($openTag, 0, -1)), '/')) {
            return [$openTag, $openEnd];
        }

        $closeStart = self::findMatchingClose($s, $openEnd, $tag);
        if ($closeStart === null) {
            throw new CompileException(
                "`<{$tag}>` mang directive `#` nhưng thiếu `</{$tag}>`",
                sourceLine: self::lineOf($s, $lt),
            );
        }
        $closeEnd = strpos($s, '>', $closeStart) + 1;

        return [
            $openTag
                . self::expandRegion(substr($s, $openEnd, $closeStart - $openEnd))
                . substr($s, $closeStart, $closeEnd - $closeStart),
            $closeEnd,
        ];
    }

    /**
     * Hạ `#switch` — ca DUY NHẤT không bọc chính thẻ mang nó.
     *
     * Thẻ cha vẫn render; chỉ RUỘT được bọc `@switch(...)…@endswitch`. Con phải
     * toàn là `#case`/`#default`.
     *
     * Mọi thứ phát ra đều dính liền nhau, không chèn khoảng trắng nào giữa
     * `@switch` và `@case` đầu tiên: nội dung lạc ở đó bị Blade render còn JS bỏ
     * hẳn — SSR ≠ CSR, không một cảnh báo. Xem RFC §5.6.
     * `splitInlineDirectives` chạy sau sẽ tách từng directive ra dòng riêng.
     *
     * @return array{0: string, 1: int} [đoạn đã hạ, vị trí quét tiếp]
     */
    private static function expandSwitch(string $s, int $lt, string $tag, string $openTag, ?string $expr, int $openEnd): array
    {
        if (isset(self::VOID_ELEMENTS[$tag])) {
            throw new CompileException(
                "`#switch` không đặt được trên thẻ rỗng `<{$tag}>` — nó bọc RUỘT của thẻ",
                sourceLine: self::lineOf($s, $lt),
            );
        }

        $closeStart = self::findMatchingClose($s, $openEnd, $tag);
        if ($closeStart === null) {
            throw new CompileException(
                "`<{$tag}>` mang directive `#` nhưng thiếu `</{$tag}>`",
                sourceLine: self::lineOf($s, $lt),
            );
        }
        $closeEnd = strpos($s, '>', $closeStart) + 1;

        $branches = '';
        $seen = 0;
        $i = $openEnd;

        while ($i < $closeStart) {
            if (ctype_space($s[$i])) {
                $i++;
                continue;
            }

            if ($s[$i] !== '<' || ! Re::match('~^<([a-zA-Z][\w-]*)~', substr($s, $i, 64), $m)) {
                throw new CompileException(
                    "ruột của `#switch` chỉ được chứa `#case`/`#default`, gặp nội dung lạc",
                    sourceLine: self::lineOf($s, $i),
                );
            }

            $childTag = strtolower($m[1]);
            $childOpenEnd = self::tagEnd($s, $i);
            if ($childOpenEnd === null) {
                throw new CompileException("thẻ `<{$childTag}>` trong `#switch` không đóng", sourceLine: self::lineOf($s, $i));
            }

            [$childOpen, $childDirs] = self::takeTagDirective(substr($s, $i, $childOpenEnd - $i), $childTag);
            if (! isset($childDirs['case'])) {
                throw new CompileException(
                    "`<{$childTag}>` trong `#switch` phải mang `#case` hoặc `#default`",
                    sourceLine: self::lineOf($s, $i),
                );
            }

            if ($branches !== '') {
                $branches .= '@break';
            }
            [$childDirective, $childExpr] = $childDirs['case'];
            $branches .= $childDirective === 'default' ? '@default' : '@case(' . $childExpr . ')';

            // `#case`/`#default` bọc ngoài cùng; phần còn lại (loop, `#if`,
            // `#switch` lồng) phát như một element thường bên trong nhánh.
            unset($childDirs['case']);
            [$childChunk, $i] = self::renderTagged($s, $i, $childTag, $childOpen, $childDirs, $childOpenEnd, true);
            $branches .= $childChunk;

            $seen++;
        }

        if ($seen === 0) {
            throw new CompileException("`#switch` trên `<{$tag}>` không có nhánh `#case` nào", sourceLine: self::lineOf($s, $lt));
        }

        return [
            $openTag . '@switch(' . $expr . ')' . $branches . '@endswitch' . substr($s, $closeStart, $closeEnd - $closeStart),
            $closeEnd,
        ];
    }

    /**
     * Nhánh kế tiếp của chuỗi, nếu có.
     *
     * @return array{0: int, 1: string, 2: string, 3: array<string, array{0: string, 1: ?string}>, 4: int, 5: string}|null
     */
    private static function nextChainBranch(string $s, int $from): ?array
    {
        $lt = $from;
        while ($lt < strlen($s) && ctype_space($s[$lt])) {
            $lt++;
        }

        if ($lt >= strlen($s) || $s[$lt] !== '<') {
            return null;
        }

        if (! Re::match('~^<([a-zA-Z][\w-]*)~', substr($s, $lt, 64), $m)) {
            return null;
        }

        $tag = strtolower($m[1]);
        $openEnd = self::tagEnd($s, $lt);
        if ($openEnd === null) {
            return null;
        }

        [$openTag, $dirs] = self::takeTagDirective(substr($s, $lt, $openEnd - $lt), $tag);
        $branch = $dirs['branch'][0] ?? null;
        if ($branch !== 'elseif' && $branch !== 'else') {
            return null;
        }

        return [$lt, $tag, $openTag, $dirs, $openEnd, substr($s, $from, $lt - $from)];
    }

    /**
     * Bóc directive `#` khỏi thẻ mở.
     *
     * Một thẻ mang được nhiều directive, mỗi loại (slot) tối đa một cái:
     * `case` (#case/#default), `branch` (#if/#elseif/#else), `loop`
     * (#foreach/#for/#while), `switch`, `key`. Thứ tự bọc do `renderTagged`
     * và các chỗ gọi quyết định, không phụ thuộc thứ tự viết trên thẻ.
     *
     * @return array{0: string, 1: array<string, array{0: string, 1: ?string}>} [thẻ đã bỏ directive, slot => [tên, biểu thức]]
     */
    private static function takeTagDirective(string $openTag, string $tag): array
    {
        if (! str_contains($openTag, '#')) {
            return [$openTag, []];
        }

        $found = [];
        $keyExpr = null;
        $out = '';
        $i = 0;
        $length = strlen($openTag);
        $quote = '';
        $parens = 0;

        while ($i < $length) {
            $ch = $openTag[$i];

            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                }
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === '(') {
                $parens++;
            } elseif ($ch === ')' && $parens > 0) {
                $parens--;
            }

            // `#` chỉ là directive khi đứng ở VỊ TRÍ TÊN THUỘC TÍNH: ngoài nháy,
            // ngoài ngoặc của `@class(...)`, và ngay sau khoảng trắng. Nếu không
            // thì `style="color: #fff"` hay `title="xem #quan-trọng"` bị nhận nhầm.
            if ($ch !== '#' || $parens !== 0 || $i === 0 || ! ctype_space($openTag[$i - 1])) {
                $out .= $ch;
                $i++;
                continue;
            }

            if (! Re::match('/^#([a-zA-Z][\w-]*)/', substr($openTag, $i), $m)) {
                $out .= $ch;
                $i++;
                continue;
            }

            $name = strtolower($m[1]);
            if (! array_key_exists($name, self::TAG_DIRECTIVES)) {
                throw new CompileException(
                    "`#{$name}` trên `<{$tag}>` không phải directive hợp lệ"
                    . ' (hỗ trợ: ' . implode(', ', array_map(static fn (string $d): string => '#' . $d, array_keys(self::TAG_DIRECTIVES))) . ')',
                );
            }
            $slot = self::TAG_DIRECTIVE_SLOTS[$name];
            if ($name !== 'key' && isset($found[$slot])) {
                throw new CompileException("`<{$tag}>` mang nhiều directive cùng loại: `#{$found[$slot][0]}` và `#{$name}`");
            }
            if ($name === 'key' && $keyExpr !== null) {
                throw new CompileException("`<{$tag}>` mang hai `#key`");
            }

            $i += strlen($m[0]);
            $raw = null;
            if (Re::match('/^\s*=\s*("[^"]*"|\'[^\']*\')/', substr($openTag, $i), $v)) {
                $raw = substr($v[1], 1, -1);
                $i += strlen($v[0]);
            }

            $needsValue = self::TAG_DIRECTIVES[$name];
            if ($needsValue && ($raw === null || trim($raw) === '')) {
                throw new CompileException("`#{$name}` trên `<{$tag}>` thiếu biểu thức");
            }
            if (! $needsValue && $raw !== null) {
                throw new CompileException("`#{$name}` trên `<{$tag}>` không nhận giá trị");
            }

            if ($name === 'key') {
                $keyExpr = trim((string) $raw);
            } else {
                $found[$slot] = [$name, $raw === null ? null : trim($raw)];
            }
            $out = rtrim($out);
        }

        // `#key` là bạn đồng hành của directive lặp, không đứng một mình.
        if ($keyExpr !== null && ! isset($found['loop'])) {
            throw new CompileException(
                "`#key` trên `<{$tag}>` phải đi kèm `#foreach`/`#for`/`#while`",
            );
        }
        if ($keyExpr !== null) {
            $found['key'] = ['key', $keyExpr];
        }

        // `#case`/`#default` bọc ngoài cùng nên nhánh `#elseif`/`#else` bị nhốt
        // trong case — không còn nối được với `#if` của thẻ anh em.
        if (isset($found['case'], $found['branch']) && $found['branch'][0] !== 'if') {
            throw new CompileException(
                "`#{$found['case'][0]}` trên `<{$tag}>` không đi cùng `#{$found['branch'][0]}` được",
            );
        }

        return [$out, $found];
    }

    /**
     * Vị trí ngay sau `>` của thẻ mở bắt đầu tại `$start`, hoặc null.
     *
     * `>` trong nháy hoặc trong ngoặc là ký tự thường: `<div @if(x>0) …>` —
     * cùng bẫy mà `joinMultilineOpenTags` và `splitInlineDirectives` đã vấp.
     */
    private static function tagEnd(string $s, int $start): ?int
    {
        $length = strlen($s);
        $quote = '';
        $parens = 0;

        for ($i = $start + 1; $i < $length; $i++) {
            $ch = $s[$i];

            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '(') {
                $parens++;
            } elseif ($ch === ')' && $parens > 0) {
                $parens--;
            } elseif ($ch === '>' && $parens === 0) {
                return $i + 1;
            }
        }

        return null;
    }

    /** Vị trí `<` của thẻ đóng khớp với thẻ mở đã kết thúc tại `$from`, hoặc null. */
    private static function findMatchingClose(string $s, int $from, string $tag): ?int
    {
        $depth = 1;
        $i = $from;
        $length = strlen($s);

        while ($i < $length) {
            $lt = strpos($s, '<', $i);
            if ($lt === false) {
                return null;
            }

            $head = substr($s, $lt, 64);

            if (Re::match('~^</\s*([a-zA-Z][\w-]*)~', $head, $m)) {
                $end = strpos($s, '>', $lt);
                if ($end === false) {
                    return null;
                }
                if (strtolower($m[1]) === $tag && --$depth === 0) {
                    return $lt;
                }
                $i = $end + 1;
                continue;
            }

            if (! Re::match('~^<([a-zA-Z][\w-]*)~', $head, $m)) {
                $i = $lt + 1;
                continue;
            }

            $name = strtolower($m[1]);
            $end = self::tagEnd($s, $lt);
            if ($end === null) {
                return null;
            }

            if (isset(self::RAW_CONTENT_ELEMENTS[$name])) {
                $i = self::skipRawContent($s, $end, $name);
                continue;
            }

            $selfClosing = str_ends_with(rtrim(substr($s, $lt, $end - $lt - 1)), '/');
            if ($name === $tag && ! $selfClosing && ! isset(self::VOID_ELEMENTS[$name])) {
                $depth++;
            }

            $i = $end;
        }

        return null;
    }

    /** Vị trí ngay sau `</tag>` của một thẻ rawtext mở kết thúc tại `$from`. */
    private static function skipRawContent(string $s, int $from, string $tag): int
    {
        $close = stripos($s, '</' . $tag, $from);
        if ($close === false) {
            return strlen($s);
        }

        $end = strpos($s, '>', $close);

        return $end === false ? strlen($s) : $end + 1;
    }

    /** Số dòng (1-based) của offset — chỉ dùng cho thông điệp lỗi. */
    private static function lineOf(string $s, int $offset): int
    {
        return substr_count($s, "\n", 0, $offset) + 1;
    }
}
