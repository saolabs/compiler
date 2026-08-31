<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use Saola\Compiler\Support\Re;

/**
 * Đổi thẻ component đã `@import` thành `@include` / `@importInclude`.
 *
 * Thẻ tự đóng trở thành `@include`. Thẻ cặp có children trở thành khối
 * `@importInclude` để emitter Blade/JS xử lý slot ở bước sau.
 *
 * Port từ compiler/src/common/import_tag_resolver.py.
 */
final class ImportTagResolver
{
    /**
     * Thuộc tính của thẻ component — cho phép '>' NẰM TRONG chuỗi nháy.
     *
     * `[^>]*?` cắt ở dấu '>' đầu tiên, mà '>' xuất hiện hợp lệ trong giá trị
     * prop: `:v="a > b"`, và cả object literal sau khi preprocessor dịch —
     * `:v="{x: a}"` thành `:v="['x'=> $a]"`. Thẻ không khớp ⇒ component KHÔNG
     * được resolve thành @include: nó ở lại làm element thường, bị hạ chữ
     * thường, được cấp hydrate id, còn prop thành chuỗi lồng nháy mà PHP không
     * parse nổi.
     *
     * Đây là bug §8② lần thứ BA (matchOpenTag, splitInlineDirectives, và đây).
     * Mọi chỗ quét thẻ HTML đều phải tính tới '>' trong chuỗi/ngoặc.
     */
    private const ATTRS = '(?:[^>\'"]|\'[^\']*\'|"[^"]*")*?';

    private const MAX_PAIRED_ITERATIONS = 100;

    /**
     * @param array<string, string> $imports thẻ → biểu thức đường dẫn nguyên văn
     */
    public function __construct(
        private readonly array $imports = [],
        private readonly string $target = 'js',
    ) {
    }

    public function resolveTags(string $code): string
    {
        if ($this->imports === []) {
            return $code;
        }

        $tagNames = array_keys($this->imports);
        usort($tagNames, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $tagPattern = implode('|', array_map(
            static fn (string $tag): string => preg_quote($tag, '~'),
            $tagNames,
        ));

        $code = $this->resolveSelfClosingTags($code, $tagPattern);

        // Giữ thứ tự khai báo của mapping, đúng với dict có thứ tự bên Python.
        foreach (array_keys($this->imports) as $tagName) {
            $code = $this->resolveSinglePairedTag($code, $tagName);
        }

        return $code;
    }

    private function resolveSelfClosingTags(string $code, string $tagPattern): string
    {
        $pattern = '~<(' . $tagPattern . ')(\s' . self::ATTRS . ')?\s*/\s*>~s';

        return Re::replaceCallback(
            $pattern,
            function (array $match): string {
                $tagName = $match[1];
                $attrs = $this->parseAttributes($match[2] ?? '');

                return $this->buildInclude($this->imports[$tagName], $attrs);
            },
            $code,
        );
    }

    private function resolveSinglePairedTag(string $code, string $tagName): string
    {
        $escapedTag = preg_quote($tagName, '~');
        $openPattern = '~<' . $escapedTag . '(\s' . self::ATTRS . ')?(?<!/)\s*>~s';

        for ($iteration = 0; $iteration < self::MAX_PAIRED_ITERATIONS; $iteration++) {
            if (! Re::match($openPattern, $code, $match, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $openStart = $match[0][1];
            $openEnd = $openStart + strlen($match[0][0]);
            $attrsSource = isset($match[1]) && $match[1][1] !== -1 ? $match[1][0] : '';
            $closePos = $this->findMatchingCloseTag($code, $tagName, $openEnd);

            // Giống oracle: gặp thẻ mở đầu tiên không có thẻ đóng thì dừng xử
            // lý tag này, không nhảy qua để tìm occurrence kế tiếp.
            if ($closePos === -1) {
                break;
            }

            $children = self::pyStrip(substr($code, $openEnd, $closePos - $openEnd));
            $closeEnd = $closePos + strlen("</{$tagName}>");
            $attrs = $this->parseAttributes($attrsSource);

            $include = $children !== ''
                ? $this->buildIncludeWithSlot($this->imports[$tagName], $attrs, $children, $tagName)
                : $this->buildInclude($this->imports[$tagName], $attrs);

            $code = substr($code, 0, $openStart) . $include . substr($code, $closeEnd);
        }

        return $code;
    }

    /** Vị trí bắt đầu của thẻ đóng khớp, hoặc -1 nếu không có. */
    private function findMatchingCloseTag(string $code, string $tagName, int $startPos): int
    {
        $escapedTag = preg_quote($tagName, '~');
        $openPattern = '~<' . $escapedTag . '(?:\s' . self::ATTRS . ')?(?<!/)\s*>~s';
        $selfClosePattern = '~<' . $escapedTag . '(?:\s' . self::ATTRS . ')?\s*/\s*>~s';
        $closePattern = '~</' . $escapedTag . '\s*>~';
        $depth = 1;
        $pos = $startPos;
        $length = strlen($code);

        while ($pos < $length && $depth > 0) {
            $events = [];

            if (Re::match($openPattern, $code, $open, PREG_OFFSET_CAPTURE, $pos)) {
                $events[] = ['open', $open[0][1], $open[0][1] + strlen($open[0][0])];
            }
            if (Re::match($closePattern, $code, $close, PREG_OFFSET_CAPTURE, $pos)) {
                $events[] = ['close', $close[0][1], $close[0][1] + strlen($close[0][0])];
            }
            if (Re::match($selfClosePattern, $code, $selfClose, PREG_OFFSET_CAPTURE, $pos)) {
                $events[] = [
                    'self_close',
                    $selfClose[0][1],
                    $selfClose[0][1] + strlen($selfClose[0][0]),
                ];
            }

            if ($events === []) {
                return -1;
            }

            usort($events, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
            [$eventType, $eventStart, $eventEnd] = $events[0];

            // Regex thẻ mở cũng match thẻ tự đóng. Khi cùng offset, self-close
            // phải thắng để không làm tăng depth giả.
            foreach ($events as [$candidateType, $candidateStart, $candidateEnd]) {
                if ($candidateStart === $events[0][1] && $candidateType === 'self_close') {
                    $eventType = $candidateType;
                    $eventStart = $candidateStart;
                    $eventEnd = $candidateEnd;
                    break;
                }
            }

            if ($eventType === 'self_close') {
                $pos = $eventEnd;
            } elseif ($eventType === 'open') {
                $depth++;
                $pos = $eventEnd;
            } else {
                $depth--;
                if ($depth === 0) {
                    return $eventStart;
                }
                $pos = $eventEnd;
            }
        }

        return -1;
    }

    /**
     * @return list<array{name: string, value: ?string, binding: bool}>
     */
    private function parseAttributes(string $source): array
    {
        if ($source === '' || self::pyStrip($source) === '') {
            return [];
        }

        $source = self::pyStrip($source);
        $attrs = [];
        $pos = 0;
        $length = strlen($source);

        while ($pos < $length) {
            while ($pos < $length && str_contains(" \t\n\r", $source[$pos])) {
                $pos++;
            }
            if ($pos >= $length) {
                break;
            }

            $binding = false;
            if ($source[$pos] === ':') {
                $binding = true;
                $pos++;
            }

            if (! Re::match('/\G[a-zA-Z_][a-zA-Z0-9_-]*/', $source, $name, PREG_OFFSET_CAPTURE, $pos)) {
                $pos++;
                continue;
            }

            $attrName = $name[0][0];
            $pos += strlen($attrName);

            while ($pos < $length && str_contains(" \t", $source[$pos])) {
                $pos++;
            }

            if ($pos < $length && $source[$pos] === '=') {
                $pos++;
                while ($pos < $length && str_contains(" \t", $source[$pos])) {
                    $pos++;
                }

                if ($pos < $length && ($source[$pos] === '"' || $source[$pos] === "'")) {
                    [$value, $pos] = $this->extractQuotedValue($source, $pos, $source[$pos]);
                    $attrs[] = ['name' => $attrName, 'value' => $value, 'binding' => $binding];
                } elseif (Re::match('/\G[^\s>]+/', $source, $bare, PREG_OFFSET_CAPTURE, $pos)) {
                    // Biến RIÊNG: nhánh nháy ở trên gán $value là chuỗi, nên
                    // dùng lại nó làm tham số by-ref `?array` sẽ TypeError ở
                    // lần lặp sau. Chỉ lộ ra khi một thẻ có cả thuộc tính nháy
                    // lẫn không nháy — trước đây thẻ đó không khớp nổi vì '>'
                    // trong giá trị, nên nhánh này chưa từng chạy.
                    $attrs[] = ['name' => $attrName, 'value' => $bare[0][0], 'binding' => $binding];
                    $pos += strlen($bare[0][0]);
                }
            } else {
                $attrs[] = ['name' => $attrName, 'value' => null, 'binding' => $binding];
            }
        }

        return $attrs;
    }

    /** @return array{string, int} [nội dung, vị trí ngay sau nháy đóng] */
    private function extractQuotedValue(string $text, int $pos, string $quoteChar): array
    {
        $length = strlen($text);
        if ($pos >= $length || $text[$pos] !== $quoteChar) {
            return ['', $pos];
        }

        $start = $pos + 1;
        $bracket = 0;
        $paren = 0;
        $brace = 0;
        $innerQuote = null;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($innerQuote !== null) {
                if ($char === $innerQuote && self::precedingBackslashes($text, $i) % 2 === 0) {
                    $innerQuote = null;
                }
                continue;
            }

            if ($char === $quoteChar && $bracket === 0 && $paren === 0 && $brace === 0) {
                return [substr($text, $start, $i - $start), $i + 1];
            }

            if (($char === "'" || $char === '"') && $char !== $quoteChar) {
                $innerQuote = $char;
            } else {
                match ($char) {
                    '[' => $bracket++,
                    ']' => $bracket--,
                    '(' => $paren++,
                    ')' => $paren--,
                    '{' => $brace++,
                    '}' => $brace--,
                    default => null,
                };
            }
        }

        return [substr($text, $start), $length];
    }

    private static function precedingBackslashes(string $text, int $pos): int
    {
        $count = 0;
        for ($i = $pos - 1; $i >= 0 && $text[$i] === '\\'; $i--) {
            $count++;
        }

        return $count;
    }

    /**
     * @param list<array{name: string, value: ?string, binding: bool}> $attrs
     */
    private function buildInclude(string $path, array $attrs): string
    {
        if ($attrs === []) {
            return "@include({$path})";
        }

        return "@include({$path}, [" . implode(', ', $this->buildAttributeParts($attrs)) . '])';
    }

    /**
     * @param list<array{name: string, value: ?string, binding: bool}> $attrs
     */
    private function buildIncludeWithSlot(
        string $path,
        array $attrs,
        string $children,
        string $tagName = 'unknown',
    ): string {
        $args = $attrs === []
            ? "{$tagName}, {$path}"
            : "{$tagName}, {$path}, [" . implode(', ', $this->buildAttributeParts($attrs)) . ']';

        return "@importInclude({$args})\n{$children}\n@endImportInclude";
    }

    /**
     * @param list<array{name: string, value: ?string, binding: bool}> $attrs
     * @return list<string>
     */
    private function buildAttributeParts(array $attrs): array
    {
        $parts = [];

        foreach ($attrs as $attr) {
            $name = $attr['name'];
            $value = $attr['value'];

            if ($value === null) {
                $parts[] = "'{$name}' => true";
            } elseif ($attr['binding']) {
                $parts[] = "'{$name}' => {$value}";
            } elseif (
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
                || (str_starts_with($value, '"') && str_ends_with($value, '"'))
            ) {
                $parts[] = "'{$name}' => {$value}";
            } else {
                $parts[] = "'{$name}' => \"{$value}\"";
            }
        }

        return $parts;
    }

    /** Python str.strip(), gồm cả whitespace Unicode và U+001C–U+001F. */
    private static function pyStrip(string $value): string
    {
        return Re::replace(
            '/^[\p{Z}\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0085}]+$/u',
            '',
            $value,
        );
    }
}
