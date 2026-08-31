<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\Re;

/**
 * Đọc directive `@import` để dựng bảng THẺ → ĐƯỜNG DẪN.
 *
 * Ba dạng:
 *
 *   @import($__template__.'sessions.tasks')                 → tasks
 *   @import($__layout__.'base' as baseLayout)               → baseLayout
 *   @import(['counter' => 'a.b', 'demo' => $__template__.'c'])
 *
 * Port từ compiler/src/common/import_parser.py.
 */
final class ImportParser
{
    /** @var array<string, string> thẻ → biểu thức đường dẫn nguyên văn */
    private array $imports = [];

    /**
     * @return array<string, string>
     */
    public function parseImports(string $code): array
    {
        $this->imports = [];

        $scan = self::maskVerbatim($code);
        $pos = 0;
        $length = strlen($code);

        while ($pos < $length) {
            if (! Re::match('/@import\s*\(/i', substr($scan, $pos), $m, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $matchStart = $pos + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);
            $parenStart = $matchEnd - 1;

            // Quét trên bản ĐÃ CHE nhưng cắt chuỗi trên code THẬT — mask giữ
            // nguyên độ dài nên hai chỉ số luôn khớp nhau.
            [$content, $endPos] = Balanced::extractParensAt($code, $parenStart);

            if ($content === null) {
                $pos = $matchEnd;
                continue;
            }

            $content = trim(Re::replace('/\{\{--.*?--\}\}/s', '', trim($content)));

            if (str_starts_with($content, '[') || str_starts_with($content, '{')) {
                $this->parseArrayImport($content);
            } else {
                $this->parseSingleImport($content);
            }

            $pos = $endPos;
        }

        return $this->imports;
    }

    /** Gỡ mọi directive `@import` khỏi code. */
    public function removeImports(string $code): string
    {
        $scan = self::maskVerbatim($code);

        /** @var list<array{int, int}> $matches */
        $matches = [];
        $pos = 0;
        $length = strlen($code);

        while ($pos < $length) {
            if (! Re::match('/@import\s*\(/i', substr($scan, $pos), $m, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $matchStart = $pos + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);

            [$content, $endPos] = Balanced::extractParensAt($code, $matchEnd - 1);

            if ($content === null) {
                $pos = $matchEnd;
                continue;
            }

            $matches[] = [$matchStart, $endPos];
            $pos = $endPos;
        }

        $result = $code;

        // Gỡ từ CUỐI ngược lên để chỉ số phía trước không bị xê dịch
        foreach (array_reverse($matches) as [$start, $end]) {
            // Kèm luôn comment Blade nằm cùng dòng phía sau
            if (Re::match('/^\s*\{\{--.*?--\}\}\s*/s', substr($result, $end), $c)) {
                $end += strlen($c[0]);
            }

            if ($end < strlen($result) && $result[$end] === "\n") {
                $end++;
            }

            $result = substr($result, 0, $start) . substr($result, $end);
        }

        return $result;
    }

    /**
     * `@import` nằm trong `@verbatim` là VĂN BẢN MẪU, không phải directive.
     *
     * Che vùng đó bằng khoảng trắng CÙNG ĐỘ DÀI để offset vẫn khớp code gốc —
     * nhờ vậy chỉ cần quét trên bản che, còn cắt chuỗi thì dùng code thật.
     */
    private static function maskVerbatim(string $code): string
    {
        return Re::replaceCallback(
            '/@verbatim\b.*?@endverbatim\b/si',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $code,
        );
    }

    /** `path_expr` hoặc `path_expr as aliasName`. */
    private function parseSingleImport(string $content): void
    {
        $content = trim($content);

        if (Re::match('/\s+as\s+\$?([a-zA-Z_][a-zA-Z0-9_]*)\s*$/', $content, $m, PREG_OFFSET_CAPTURE)) {
            $tag = $m[1][0];
            $path = trim(substr($content, 0, $m[0][1]));
        } else {
            $path = $content;
            $tag = self::extractTagFromPath($path);
        }

        // Python: `if tag and path` — '' và None đều falsy
        if ($tag !== null && $tag !== '' && $path !== '') {
            $this->imports[$tag] = $path;
        }
    }

    /** `[ 'tag1' => 'path1', 'tag2' => path2, ... ]` */
    private function parseArrayImport(string $content): void
    {
        $inner = trim($content);

        if (
            (str_starts_with($inner, '[') && str_ends_with($inner, ']'))
            || (str_starts_with($inner, '{') && str_ends_with($inner, '}'))
        ) {
            $inner = trim(substr($inner, 1, -1));
        }

        $inner = Re::replace('/\{\{--.*?--\}\}/s', '', $inner);
        $inner = Re::replace('/\/\/[^\n]*/', '', $inner);

        foreach (self::splitAtLevelZero($inner, ',') as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (Re::match('/^(?:[\'"]?([a-zA-Z_][a-zA-Z0-9_]*)[\'"]?)\s*(?:=>|:)\s*(.+)/s', $entry, $m)) {
                $this->imports[$m[1]] = trim(rtrim(trim($m[2]), ','));
            }
        }
    }

    /**
     * Tên thẻ suy từ đoạn cuối của biểu thức đường dẫn.
     *
     *   $__template__.'sessions.tasks'  → tasks
     *   'a'                             → a
     *   "b.d"                           → d
     *   $__blade_custom_path__          → blade_custom_path
     *
     * PHẢI trùng luật với {@see ImportAliases::deriveName} phía preprocessor —
     * tên thẻ và alias mà trỏ hai nơi khác nhau là hỏng.
     */
    public static function extractTagFromPath(string $path): ?string
    {
        $path = trim($path);

        $literals = Re::matchAll('/[\'"]([^\'"]+)[\'"]/', $path);

        if ($literals !== []) {
            $last = $literals[count($literals) - 1][1];
            $parts = explode('.', $last);

            return $parts[count($parts) - 1];
        }

        if (Re::match('/^\$_*([a-zA-Z][a-zA-Z0-9_]*?)_*$/', $path, $m)) {
            return $m[1];
        }

        // Nhánh cuối trả null (không phải '') khi không còn ký tự hợp lệ —
        // khớp `return clean if clean else None` của bản Python.
        $clean = Re::replace('/[^a-zA-Z0-9_]/', '', $path);

        return $clean === '' ? null : $clean;
    }

    /**
     * Tách ở mức lồng 0, tôn trọng `[]`, `()`, `{}` và nháy (có đếm escape).
     *
     * Biến thể riêng của import_parser.py — khác ba biến thể trong
     * {@see Balanced} ở chỗ đếm số dấu `\` liên tiếp để biết nháy có bị escape
     * thật hay không. Giữ private vì chỉ chỗ này dùng.
     *
     * @return list<string>
     */
    private static function splitAtLevelZero(string $text, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $bracket = 0;
        $paren = 0;
        $brace = 0;
        $inQuote = false;
        $quoteChar = '';

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $ch = $text[$i];

            if ($inQuote) {
                $current .= $ch;

                if ($ch === $quoteChar) {
                    $escapes = 0;
                    for ($j = $i - 1; $j >= 0 && $text[$j] === '\\'; $j--) {
                        $escapes++;
                    }

                    if ($escapes % 2 === 0) {
                        $inQuote = false;
                    }
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inQuote = true;
                $quoteChar = $ch;
                $current .= $ch;
                continue;
            }

            match ($ch) {
                '[' => $bracket++,
                ']' => $bracket--,
                '(' => $paren++,
                ')' => $paren--,
                '{' => $brace++,
                '}' => $brace--,
                default => null,
            };

            if ($ch === $delimiter && $bracket === 0 && $paren === 0 && $brace === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
