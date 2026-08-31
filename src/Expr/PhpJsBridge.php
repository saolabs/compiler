<?php

declare(strict_types=1);

namespace Saola\Compiler\Expr;

use Saola\Compiler\Support\LiteralMask;
use Saola\Compiler\Support\PyStr;
use Saola\Compiler\Support\Re;

/**
 * Cầu nối PHP↔JS trong biểu thức `.sao` — BẮT BUỘC cho mọi file, không phải
 * đường tương thích cho cú pháp cũ.
 *
 *     ['a' => 1, 'b' => x]  →  {"a": 1, "b": x}
 *     $post->title          →  post.title
 *     $a . ' ' . $b         →  a+` `+b
 *
 * ⚠️ TÊN CŨ CỦA CLASS NÀY LÀ `LegacyPhpSyntax` — SAI VÀ GÂY HIỂU LẦM. Đây
 * không phải nhánh chỉ chạy khi ai đó viết `.sao` kiểu cũ. Preprocessor
 * (`ExpressionTransformer`) LUÔN dịch Saola Syntax sang cú pháp PHP trước —
 * kể cả `item.name` (thuần Saola Syntax hiện đại) cũng ra `$item->name` —
 * vì đó là dạng Blade cần để nhúng thẳng vào SSR. Class này là nửa DỊCH
 * NGƯỢC (PHP → JS) mà JsEmitter luôn cần để đọc lại chính output đó.
 *
 * Xoá class này sẽ làm hỏng MỌI property access trong MỌI view, kể cả view
 * dùng Saola Syntax mới nhất — không chỉ file cũ. Xem docs/05-roadmap.md
 * §11 để biết vì sao lần rà soát trước đã hiểu nhầm vai trò của nó.
 *
 * (Số liệu 87% biểu thức "không đổi" khi đo trên corpus thật KHÔNG có nghĩa
 * là 87% "ít dùng đến" — chỉ nghĩa là 87% biểu thức không có property access/
 * nối chuỗi/mảng nên round-trip PHP→JS trả về đúng y hệt input.)
 *
 * Port từ common/php_js_converter.py — các method xử lý mảng và nối chuỗi.
 *
 * ⚠️ Đây là port THEO HÀNH VI, không phải viết lại. Bản gốc là một chồng
 * heuristic regex tích tụ qua thời gian, kể cả vài chỗ sai đã biết (xem
 * handleStringConcatenation). Cổng parity đòi giống hệt từng byte, nên bug
 * cũng phải giữ nguyên — sửa ở đây mà không sửa bên Python là làm đỏ cổng.
 */
final class PhpJsBridge
{
    /** Trần lặp khi bóc cấu trúc lồng nhau — chống lặp vô hạn, giống bản gốc. */
    private const MAX_ITERATIONS = 10;

    public function __construct(
        private readonly HelperResolver $helpers,
    ) {
    }

    // ── Cấu trúc mảng / object ────────────────────────────────────────

    /**
     * Chuyển mảng PHP sang mảng/object JS, xử lý dần từ cặp ngoặc đầu tiên.
     *
     * Port từ _convert_complex_structures.
     */
    public function convertComplexStructures(string $expr): string
    {
        $iteration = 0;

        while (str_contains($expr, '[') && str_contains($expr, ']') && $iteration < self::MAX_ITERATIONS) {
            $iteration++;

            [$startPos, $endPos] = $this->findBracketPair($expr);

            if ($startPos === -1 || $endPos === -1) {
                break;
            }

            $arrayContent = trim(substr($expr, $startPos + 1, $endPos - $startPos - 1));

            // Truy cập phần tử (`$x['key']`) chứ không phải mảng literal.
            // Bản gốc `continue` với expr không đổi, nên vòng lặp chỉ dừng nhờ
            // MAX_ITERATIONS. Giữ nguyên — đổi thành `break` sẽ lệch kết quả
            // với biểu thức có nhiều cặp ngoặc.
            if ($this->isArrayAccess($arrayContent)) {
                continue;
            }

            $elements = $this->parseArrayElements($arrayContent);

            $hasKeyValue = false;
            $hasSimple = false;
            foreach ($elements as $element) {
                if (str_contains($element, '=>')) {
                    $hasKeyValue = true;
                } else {
                    $hasSimple = true;
                }
            }

            $isMixedArray = $hasKeyValue && $hasSimple;
            $isObjectArray = $hasKeyValue && ! $hasSimple;

            if ($isMixedArray) {
                $jsElements = $this->convertElementsPreservingShape($elements);
            } elseif ($isObjectArray) {
                $hasNestedArrays = false;
                foreach ($elements as $element) {
                    if (str_starts_with($element, '[') && str_ends_with($element, ']')) {
                        $hasNestedArrays = true;
                        break;
                    }
                }

                if ($hasNestedArrays) {
                    $jsElements = $this->convertElementsPreservingShape($elements);
                } else {
                    // Object thuần: gộp mọi cặp key => value thành MỘT object,
                    // thay bằng {...} chứ không phải [...].
                    $objectParts = [];
                    foreach ($elements as $element) {
                        if (str_contains($element, '=>')) {
                            $objectParts[] = $this->convertArrayElement($element);
                        }
                    }

                    $expr = substr($expr, 0, $startPos)
                        . '{' . implode(', ', $objectParts) . '}'
                        . substr($expr, $endPos + 1);

                    continue;
                }
            } else {
                $jsElements = [];
                foreach ($elements as $element) {
                    $jsElements[] = $this->convertArrayElement($element);
                }
            }

            $expr = substr($expr, 0, $startPos)
                . '[' . implode(', ', $jsElements) . ']'
                . substr($expr, $endPos + 1);
        }

        return $expr;
    }

    /**
     * Tìm dấu '[' đầu tiên và ']' khớp với nó.
     *
     * Bản gốc chú thích là "innermost" nhưng thực tế lấy cặp NGOÀI CÙNG ĐẦU
     * TIÊN. Giữ nguyên hành vi, chỉ sửa lại tên cho khỏi hiểu nhầm.
     *
     * @return array{int, int} [startPos, endPos]; -1 nếu không tìm thấy
     */
    private function findBracketPair(string $expr): array
    {
        $startPos = -1;
        $bracketCount = 0;
        $length = strlen($expr);

        for ($i = 0; $i < $length; $i++) {
            if ($expr[$i] === '[') {
                if ($startPos === -1) {
                    $startPos = $i;
                }
                $bracketCount++;
            } elseif ($expr[$i] === ']') {
                $bracketCount--;
                if ($bracketCount === 0) {
                    return [$startPos, $i];
                }
            }
        }

        return [$startPos, -1];
    }

    /**
     * Chuyển từng phần tử nhưng GIỮ hình dạng mảng ngoài.
     *
     * Bản Python lặp lại nguyên khối này ở hai nhánh (mixed array và object
     * array có mảng lồng). Gộp lại một chỗ — hành vi giống hệt.
     *
     * @param  list<string> $elements
     * @return list<string>
     */
    private function convertElementsPreservingShape(array $elements): array
    {
        $jsElements = [];

        foreach ($elements as $element) {
            if (! str_contains($element, '=>')) {
                $jsElements[] = $this->convertValue($element);
                continue;
            }

            if (str_starts_with($element, '[') && str_ends_with($element, ']')) {
                $jsElements[] = $this->convertNestedArray($element);
                continue;
            }

            $jsElements[] = '{' . $this->convertArrayElement($element) . '}';
        }

        return $jsElements;
    }

    /** Mảng lồng: toàn key => value thì thành object, còn lại giữ mảng. */
    private function convertNestedArray(string $element): string
    {
        $nestedContent = trim(substr($element, 1, -1));
        $nestedElements = $this->parseArrayElements($nestedContent);

        // Python: all([]) là True — mảng lồng rỗng ra '{}'. Giữ nguyên.
        $allKeyValue = true;
        foreach ($nestedElements as $nested) {
            if (! str_contains($nested, '=>')) {
                $allKeyValue = false;
                break;
            }
        }

        $parts = [];

        if ($allKeyValue) {
            foreach ($nestedElements as $nested) {
                $parts[] = $this->convertArrayElement($nested);
            }

            return '{' . implode(', ', $parts) . '}';
        }

        foreach ($nestedElements as $nested) {
            $parts[] = str_contains($nested, '=>')
                ? '{' . $this->convertArrayElement($nested) . '}'
                : $this->convertValue($nested);
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /** Heuristic: chuỗi trong nháy hoặc số ⇒ truy cập phần tử, không phải literal. */
    private function isArrayAccess(string $content): bool
    {
        $content = trim($content);

        if (str_starts_with($content, "'") && str_ends_with($content, "'") && substr_count($content, "'") === 2) {
            return true;
        }

        if (str_starts_with($content, '"') && str_ends_with($content, '"') && substr_count($content, '"') === 2) {
            return true;
        }

        return PyStr::isDigit($content) || PyStr::isDigit(str_replace('.', '', $content));
    }

    /**
     * Tách phần tử mảng theo dấu phẩy mức ngoài cùng, tôn trọng ngoặc và nháy.
     *
     * @return list<string>
     */
    public function parseArrayElements(string $content): array
    {
        $elements = [];
        $current = '';
        $parenCount = 0;
        $bracketCount = 0;
        $braceCount = 0;
        $inQuotes = false;
        $quoteChar = '';
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];

            if (($char === '"' || $char === "'") && ! $inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                if ($i > 0 && $content[$i - 1] === '\\') {
                    $current .= $char;
                } else {
                    $inQuotes = false;
                    $quoteChar = '';
                    $current .= $char;
                }
            } elseif (! $inQuotes) {
                match ($char) {
                    '(' => $parenCount++,
                    ')' => $parenCount--,
                    '[' => $bracketCount++,
                    ']' => $bracketCount--,
                    '{' => $braceCount++,
                    '}' => $braceCount--,
                    default => null,
                };

                if ($char === ',' && $parenCount === 0 && $bracketCount === 0 && $braceCount === 0) {
                    $elements[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $elements[] = trim($current);
        }

        return $elements;
    }

    /** Một phần tử mảng → JS. Cặp key => value thành `"key": value`. */
    public function convertArrayElement(string $element): string
    {
        $element = trim($element);

        if (str_contains($element, '=>')) {
            $parts = $this->splitKeyValue($element);

            if (count($parts) === 2) {
                return '"' . $this->convertKey($parts[0]) . '": ' . $this->convertValue($parts[1]);
            }
        }

        return $this->convertValue($element);
    }

    /**
     * Tách `key => value` ở dấu => đầu tiên nằm ngoài nháy và ngoài ngoặc.
     *
     * @return list<string> Một phần tử nếu không tìm thấy dấu tách
     */
    private function splitKeyValue(string $element): array
    {
        $parenCount = 0;
        $bracketCount = 0;
        $inQuotes = false;
        $quoteChar = '';
        $length = strlen($element);

        for ($i = 0; $i < $length; $i++) {
            $char = $element[$i];

            if (($char === '"' || $char === "'") && ! $inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                if ($i > 0 && $element[$i - 1] === '\\') {
                    continue;
                }
                $inQuotes = false;
                $quoteChar = '';
            } elseif (! $inQuotes) {
                match ($char) {
                    '(' => $parenCount++,
                    ')' => $parenCount--,
                    '[' => $bracketCount++,
                    ']' => $bracketCount--,
                    default => null,
                };

                if (
                    $char === '='
                    && $i + 1 < $length
                    && $element[$i + 1] === '>'
                    && $parenCount === 0
                    && $bracketCount === 0
                ) {
                    return [
                        trim(substr($element, 0, $i)),
                        trim(substr($element, $i + 2)),
                    ];
                }
            }
        }

        return [$element];
    }

    /** Khoá mảng → khoá JS: bỏ nháy bao ngoài nếu có. */
    private function convertKey(string $key): string
    {
        $key = trim($key);

        if (
            (str_starts_with($key, "'") && str_ends_with($key, "'"))
            || (str_starts_with($key, '"') && str_ends_with($key, '"'))
        ) {
            return substr($key, 1, -1);
        }

        return $key;
    }

    /** Giá trị phần tử mảng → giá trị JS. */
    private function convertValue(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return '"' . substr($value, 1, -1) . '"';
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return $value;
        }

        if (PyStr::isDigit($value) || PyStr::isDigit(str_replace('.', '', $value))) {
            return $value;
        }

        if (in_array(strtolower($value), ['true', 'false', 'null'], true)) {
            return strtolower($value);
        }

        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            return $this->convertComplexStructures($value);
        }

        // Nhánh này không bao giờ chạy: hai điều kiện nháy ở trên đã bắt hết.
        // Giữ lại để đối chiếu 1-1 với bản Python khi soát parity.
        if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
            return $value;
        }

        if (PyStr::isAlnum($value) || PyStr::isAlnum(str_replace('_', '', $value))) {
            return $value;
        }

        return $this->convertSimpleExpression($value);
    }

    // ── Nối chuỗi ─────────────────────────────────────────────────────

    /**
     * Tách biểu thức theo dấu '.' nối chuỗi ở mức ngoài cùng.
     *
     * Chỉ coi '.' là toán tử nối khi nó không nằm trong ngoặc và không phải
     * dấu thập phân của số (1.5). Từng toán hạng trả về NGUYÊN VĂN nên phép
     * toán bên trong (`$count * 10`) không bị vỡ.
     *
     * @return list<string>
     */
    private function splitConcatOperands(string $expr): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($expr);

        for ($i = 0; $i < $length; $i++) {
            $char = $expr[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === '.' && $depth === 0) {
                $prev = $i > 0 ? $expr[$i - 1] : '';
                $next = $i + 1 < $length ? $expr[$i + 1] : '';

                if (! (PyStr::isDigit($prev) && PyStr::isDigit($next))) {
                    $parts[] = $buffer;
                    $buffer = '';
                    continue;
                }
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    /**
     * Placeholder → literal JS.
     *
     * Chuỗi nháy kép của PHP có nội suy biến nên thành template literal;
     * nháy đơn giữ nguyên.
     */
    public function restoreStringLiteral(string $literal): string
    {
        if (str_starts_with($literal, '"')) {
            $inner = Re::replace(
                '/\$([a-zA-Z_][a-zA-Z0-9_]*)/',
                '${${1}}',
                substr($literal, 1, -1),
            );

            return '`' . $inner . '`';
        }

        return $literal;
    }

    /**
     * Chuyển '.' nối chuỗi thành '+', giữ nguyên truy cập property.
     *
     * Port từ _handle_string_concatenation.
     *
     * Việc khôi phục string literal đi qua {@see LiteralMask}, nơi giữ bất
     * biến "lớp ngoài trước lớp trong". Bản gốc chép vòng khôi phục ở nhiều
     * nơi với thứ tự xuôi, làm rò `__STR_LIT_0__` ra output production —
     * đã sửa ở cả hai bản cài đặt cùng lúc để cổng parity vẫn xanh.
     */
    public function handleStringConcatenation(string $expr): string
    {
        $trimmed = trim($expr);

        $looksLikeConcat = str_contains($expr, '.')
            && (str_contains($expr, '$') || str_contains($expr, '"') || str_contains($expr, "'"))
            && ! Re::match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $trimmed);

        if ($looksLikeConcat) {
            $skip = (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
                || (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
                || str_contains($expr, '??');

            if (! $skip) {
                // Soát toán tử NGOÀI chuỗi, để '<' trong '<strong>' không bị
                // hiểu nhầm là phép so sánh.
                $withoutStrings = Re::replace('/"[^"]*"/', '', Re::replace("/'[^']*'/", '', $expr));

                $skip = Re::match('/[=!<>]=?|==|!=|<=|>=|===|!==/', $withoutStrings)
                    || Re::match('/\?.*:/', $expr)
                    || Re::match('/\b[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*\b/', $withoutStrings)
                    || Re::match('/[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\)\s*\.\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $expr);
            }

            if ($skip) {
                return $expr;
            }

            return $this->joinConcatOperands($expr);
        }

        return $this->convertDotsOutsideStrings($expr);
    }

    private function joinConcatOperands(string $expr): string
    {
        $mask = new LiteralMask('STR_LIT');
        $masked = $mask->mask($expr);

        $jsParts = [];

        foreach ($this->splitConcatOperands($masked) as $operand) {
            $operand = trim($operand);

            if ($operand === '') {
                continue;
            }

            // Bỏ '$' TRƯỚC khi khôi phục literal: làm ngược lại thì '$foo' nằm
            // trong chuỗi nháy đơn cũng bị cắt mất '$'.
            $operand = Re::replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', '${1}', $operand);

            $jsParts[] = $mask->unmask($operand, $this->restoreStringLiteral(...));
        }

        return implode('+', $jsParts);
    }

    private function convertDotsOutsideStrings(string $expr): string
    {
        $mask = new LiteralMask('STR_LIT');
        $expr = $mask->mask($expr);

        $accesses = [];
        $protectAccess = function (array $m) use (&$accesses): string {
            $placeholder = '__OBJ_ACCESS_' . count($accesses) . '__';
            $accesses[] = $m[0];

            return $placeholder;
        };

        $expr = Re::replaceCallback('/\b\w+\.\w+\b/', $protectAccess, $expr);

        $expr = Re::replace('/\s+\.\s+/', ' + ', $expr);
        $expr = Re::replace('/\.\s+/', ' + ', $expr);
        $expr = Re::replace('/\s+\./', ' + ', $expr);

        // Thứ tự xuôi là an toàn ở đây: pattern \b\w+\.\w+\b khớp không
        // chồng lấn nên placeholder truy cập property không thể lồng nhau.
        foreach ($accesses as $i => $pattern) {
            $expr = str_replace('__OBJ_ACCESS_' . $i . '__', $pattern, $expr);
        }

        // Literal khôi phục CUỐI, và ngược thứ tự — xem LiteralMask.
        return $mask->unmask($expr);
    }

    /** Biểu thức đơn giản: `->` thành `.`, nối chuỗi, rồi gắn tiền tố helper. */
    private function convertSimpleExpression(string $expr): string
    {
        $expr = Re::replace('/->/', '.', trim($expr));
        $expr = $this->handleStringConcatenation($expr);

        return $this->helpers->resolve($expr);
    }
}
