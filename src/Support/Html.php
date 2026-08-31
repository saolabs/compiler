<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

/**
 * Tiện ích HTML dùng chung cho CẢ HAI emitter.
 *
 * Ghép thẻ nhiều dòng phải giống hệt nhau ở sao2blade và sao2js. Sửa một phía
 * thôi là id hydrate lệch nhau — đúng lớp lỗi mà cổng `marker-sync` sinh ra để
 * bắt. Để chung một chỗ thì không thể lệch.
 */
final class Html
{
    private function __construct()
    {
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

            $out .= substr($masked, $i, $end - $i);
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
}
