<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

/**
 * Quét chuỗi có tôn trọng ngoặc và nháy.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  HAI BIẾN THỂ, KHÔNG THAY THẾ CHO NHAU ĐƯỢC.
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bản JS có hai cài đặt riêng cho cùng ý tưởng, và chúng KHÁC nhau:
 *
 *   symbol-collector.js      — đếm ( ), [ ], { } bằng BA bộ đếm riêng,
 *                              đòi cả ba đều bằng 0
 *   expression-transformer.js — đếm cả ba loại bằng MỘT bộ đếm chung
 *
 * Khác biệt quan sát được: với `(a]`, bản gộp coi là cân bằng còn bản tách
 * thì không. Gộp hai bản làm một là đổi hành vi ở đúng những input méo mó mà
 * compiler vẫn phải xử lý được. Giữ tách bạch, và ghi rõ chỗ nào dùng bản nào.
 *
 * Bản Python còn một biến thể THỨ BA trong common/utils.py — cũng tách ở mức
 * ngoài cùng nhưng có xử lý dấu escape, trim từng phần, và bỏ phần cuối nếu
 * rỗng. Đó là {@see splitTopLevelStripped}.
 *
 * | Biến thể                  | Đếm ngoặc | Escape | Trim | Nguồn                     |
 * |---------------------------|-----------|--------|------|---------------------------|
 * | splitTopLevel             | 3 bộ đếm  | không  | không| symbol-collector.js       |
 * | splitTopLevelLoose        | 1 bộ đếm  | không  | không| expression-transformer.js |
 * | splitTopLevelStripped     | 1 bộ đếm  | CÓ     | CÓ   | common/utils.py           |
 */
final class Balanced
{
    private function __construct()
    {
    }

    /**
     * Nội dung bên trong cặp ngoặc tròn bắt đầu tại $openPos.
     *
     * @return string|null null nếu $openPos không phải '(' hoặc không có ngoặc đóng
     */
    public static function extractParens(string $str, int $openPos): ?string
    {
        if (($str[$openPos] ?? '') !== '(') {
            return null;
        }

        $depth = 1;
        $i = $openPos + 1;
        $length = strlen($str);

        while ($i < $length && $depth > 0) {
            if ($str[$i] === '(') {
                $depth++;
            } elseif ($str[$i] === ')') {
                $depth--;
            }
            $i++;
        }

        return $depth === 0
            ? substr($str, $openPos + 1, $i - 1 - ($openPos + 1))
            : null;
    }

    /**
     * Nội dung trong ngoặc bắt đầu tại $startPos, KÈM vị trí ngay sau ngoặc đóng.
     *
     * Khác {@see extractParens}: dung thứ với ngoặc không cân bằng — trả về
     * phần còn lại của chuỗi thay vì null. Port từ
     * common/utils.py::extract_balanced_parentheses.
     *
     * @return array{?string, int} [nội dung, vị trí tiếp theo]
     */
    public static function extractParensAt(string $text, int $startPos): array
    {
        $length = strlen($text);

        if ($startPos >= $length || $text[$startPos] !== '(') {
            return [null, $startPos];
        }

        $depth = 0;
        $contentStart = $startPos + 1;

        for ($i = $startPos; $i < $length; $i++) {
            if ($text[$i] === '(') {
                $depth++;
            } elseif ($text[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return [substr($text, $contentStart, $i - $contentStart), $i + 1];
                }
            }
        }

        return [substr($text, $contentStart), $length];
    }

    /**
     * Tách ở mức ngoài cùng, có xử lý escape, trim từng phần.
     *
     * Biến thể thứ ba — port từ common/utils.py::split_top_level_commas và
     * split_top_level_semicolons. Khác hai biến thể trên ở ba điểm: hiểu dấu
     * `\` trong chuỗi, trim từng phần, và BỎ phần cuối nếu rỗng sau trim.
     *
     * @return list<string>
     */
    public static function splitTopLevelStripped(string $text, string $delimiter): array
    {
        $parts = [];
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $escape = false;
        $start = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $escape = true;
                continue;
            }

            if ($inSingle) {
                if ($ch === "'") {
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($ch === '"') {
                    $inDouble = false;
                }
                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
            } elseif ($ch === '"') {
                $inDouble = true;
            } elseif ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
            } elseif ($ch === $delimiter && $depth === 0) {
                $parts[] = trim(substr($text, $start, $i - $start));
                $start = $i + 1;
            }
        }

        $tail = trim(substr($text, $start));

        if ($tail !== '') {
            $parts[] = $tail;
        }

        return $parts;
    }

    /**
     * Tách theo $delimiter ở mức ngoài cùng — BA bộ đếm riêng.
     *
     * Dùng bởi SymbolCollector. Port từ symbol-collector.js::_splitTopLevel.
     *
     * @return list<string>
     */
    public static function splitTopLevel(string $str, string $delimiter): array
    {
        return self::split($str, $delimiter, separateDepths: true);
    }

    /**
     * Tách theo $delimiter ở mức ngoài cùng — MỘT bộ đếm gộp.
     *
     * Dùng bởi ExpressionTransformer. Port từ
     * expression-transformer.js::_splitTopLevelStr.
     *
     * @return list<string>
     */
    public static function splitTopLevelLoose(string $str, string $delimiter): array
    {
        return self::split($str, $delimiter, separateDepths: false);
    }

    /**
     * Vị trí dấu '=' GÁN đầu tiên ở mức ngoài cùng — BA bộ đếm riêng.
     *
     * Bỏ qua `==`, `===`, `=>`, `!=`, `<=`, `>=`.
     * Port từ symbol-collector.js::_findAssignmentEquals.
     */
    public static function findAssignment(string $str): int
    {
        return self::findEquals($str, separateDepths: true);
    }

    /**
     * Vị trí dấu '=' GÁN đầu tiên ở mức ngoài cùng — MỘT bộ đếm gộp.
     *
     * Port từ expression-transformer.js::_findFirstEquals.
     */
    public static function findAssignmentLoose(string $str): int
    {
        return self::findEquals($str, separateDepths: false);
    }

    /** @return list<string> */
    private static function split(string $str, string $delimiter, bool $separateDepths): array
    {
        $parts = [];
        $current = '';
        $paren = 0;
        $bracket = 0;
        $brace = 0;
        $inString = false;
        $stringChar = '';
        $length = strlen($str);

        for ($i = 0; $i < $length; $i++) {
            $ch = $str[$i];

            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar && self::prevChar($str, $i) !== '\\') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = true;
                $stringChar = $ch;
                $current .= $ch;
                continue;
            }

            [$paren, $bracket, $brace] = self::track($ch, $paren, $bracket, $brace, $separateDepths);

            $atTopLevel = $separateDepths
                ? ($paren === 0 && $bracket === 0 && $brace === 0)
                : $paren === 0;

            if ($ch === $delimiter && $atTopLevel) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        // Phần cuối chỉ được thêm khi KHÔNG rỗng sau trim — các phần trước thì
        // luôn được thêm. Bất đối xứng này có trong bản JS, giữ nguyên.
        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private static function findEquals(string $str, bool $separateDepths): int
    {
        $paren = 0;
        $bracket = 0;
        $brace = 0;
        $inString = false;
        $stringChar = '';
        $length = strlen($str);

        for ($i = 0; $i < $length; $i++) {
            $ch = $str[$i];

            if ($inString) {
                if ($ch === $stringChar && self::prevChar($str, $i) !== '\\') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = true;
                $stringChar = $ch;
                continue;
            }

            [$paren, $bracket, $brace] = self::track($ch, $paren, $bracket, $brace, $separateDepths);

            $atTopLevel = $separateDepths
                ? ($paren === 0 && $bracket === 0 && $brace === 0)
                : $paren === 0;

            if ($ch !== '=' || ! $atTopLevel) {
                continue;
            }

            $prev = self::prevChar($str, $i);
            $next = $str[$i + 1] ?? '';

            if ($next === '=' || $next === '>') {
                continue;   // ==, ===, =>
            }

            if ($prev === '!' || $prev === '<' || $prev === '>') {
                continue;   // !=, <=, >=
            }

            return $i;
        }

        return -1;
    }

    /**
     * @return array{int, int, int}
     */
    private static function track(string $ch, int $paren, int $bracket, int $brace, bool $separateDepths): array
    {
        if (! $separateDepths) {
            // Bản gộp: mọi loại ngoặc cùng đếm vào $paren
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $paren++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $paren--;
            }

            return [$paren, $bracket, $brace];
        }

        match ($ch) {
            '(' => $paren++,
            ')' => $paren--,
            '[' => $bracket++,
            ']' => $bracket--,
            '{' => $brace++,
            '}' => $brace--,
            default => null,
        };

        return [$paren, $bracket, $brace];
    }

    /**
     * Ký tự liền trước, hoặc '' khi ở đầu chuỗi.
     *
     * BẪY JS→PHP: `str[-1]` trong JS là `undefined`, còn `$str[-1]` trong PHP
     * là ký tự CUỐI của chuỗi. Không chặn thì việc kiểm tra dấu escape ở vị
     * trí 0 sẽ đọc nhầm sang cuối chuỗi.
     */
    private static function prevChar(string $str, int $i): string
    {
        return $i > 0 ? $str[$i - 1] : '';
    }
}
