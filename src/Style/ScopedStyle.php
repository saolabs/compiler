<?php

declare(strict_types=1);

namespace Saola\Compiler\Style;

use Saola\Compiler\Support\BladeComment;
use Saola\Compiler\Support\Re;

/**
 * Scoped CSS ở tầng BIÊN DỊCH — dùng chung cho Blade và JS emitter.
 *
 * Trước đây scope được quyết định lúc CHẠY: AssetManager viết lại CSS thành
 * `[data-sao-scope="id"] .foo` rồi gắn attribute lên "node gốc của instance",
 * mà node gốc lấy từ marker của Wrapper. Trang extends layout KHÔNG có Wrapper
 * nên không node nào được gắn ⇒ toàn bộ `<style scoped>` của trang thành CSS
 * chết.
 *
 * Ở đây scope quyết định lúc biên dịch: mọi element của view mang thêm một
 * class ổn định, selector ghép thẳng vào class đó (kiểu `data-v-` của Vue).
 * Không cần wrapper, không phụ thuộc thứ tự mount, HTML từ Blade đã mang sẵn
 * class nên CSS ăn ngay từ lần sơn đầu.
 *
 * scopeId suy từ CHÍNH nội dung CSS chứ không từ đường dẫn view: Blade emitter
 * không có sẵn view path còn JS emitter thì có, nên hash theo nội dung khiến
 * hai nhánh tự ra cùng giá trị mà không phải truyền gì qua nhau.
 *
 * Port từ compiler/src/common/scoped_style.py.
 */
final class ScopedStyle
{
    /**
     * Selector bên trong các at-rule này KHÔNG phải selector CSS (from/to/50%),
     * đụng vào là hỏng animation.
     */
    private const SKIP_AT_RULES = ['keyframes', 'font-face', 'import', 'charset', 'namespace'];

    /** At-rule bọc rule thật → phải đi vào trong mà scope. */
    private const NESTED_AT_RULES = ['media', 'supports', 'container', 'layer', 'scope'];

    private function __construct()
    {
    }

    /**
     * Nội dung của mọi `<style ... scoped ...>` trong file.
     *
     * @return list<string>
     */
    public static function extract(string $content): array
    {
        $out = [];

        // Ví dụ `<style scoped>` trong comment không phải CSS thật (xem
        // {@see BladeComment}) — scope class suy từ nội dung CSS nên lọt một
        // block giả là đổi class của toàn bộ element trong view.
        foreach (Re::matchAll('/<style([^>]*)>([\s\S]*?)<\/style>/i', BladeComment::blank($content)) as $m) {
            if (Re::match('/\bscoped\b/i', $m[1])) {
                $out[] = $m[2];
            }
        }

        return $out;
    }

    /**
     * Class scope ổn định suy từ nội dung CSS. '' nếu không có block scoped nào.
     *
     * @param list<string> $cssBlocks
     */
    public static function classFor(array $cssBlocks): string
    {
        if ($cssBlocks === []) {
            return '';
        }

        $joined = implode("\n", $cssBlocks);

        // djb2 — cùng thuật toán với AssetManager.hashString phía client, để
        // hai bên còn đối chiếu được khi cần.
        //
        // Duyệt theo CODEPOINT chứ không theo byte: bản Python lặp qua ký tự
        // Unicode, nên CSS có tiếng Việt (vd `content: "Chào"`) sẽ ra hash khác
        // nếu ở đây duyệt byte — và scope class lệch là toàn bộ CSS chết.
        $hash = 5381;

        foreach (mb_str_split($joined, 1, 'UTF-8') as $ch) {
            $code = mb_ord($ch, 'UTF-8');
            $hash = (($hash * 33) ^ ($code === false ? 0 : $code)) & 0xFFFFFFFF;
        }

        return 's' . dechex($hash);
    }

    /**
     * Ghép `.scopeClass` vào từng selector.
     *
     *   `.a .b { }`      → `.a .b.scope { }`      (chỉ compound CUỐI, như Vue)
     *   `.a, .b { }`     → `.a.scope, .b.scope { }`
     *   `.a:hover { }`   → `.a.scope:hover { }`   (chèn TRƯỚC pseudo)
     *   `@media ... { }` → đi vào trong scope tiếp
     *   `@keyframes`     → giữ nguyên
     */
    public static function apply(string $css, string $scopeClass): string
    {
        if ($scopeClass === '' || $css === '') {
            return $css;
        }

        return self::scopeBlock($css, '.' . $scopeClass);
    }

    private static function scopeBlock(string $css, string $suffix): string
    {
        $out = '';
        $i = 0;
        $length = strlen($css);

        while ($i < $length) {
            $brace = strpos($css, '{', $i);

            if ($brace === false) {
                return $out . substr($css, $i);
            }

            $bodyEnd = self::matchBrace($css, $brace);

            if ($bodyEnd === -1) {
                return $out . substr($css, $i);
            }

            $prelude = substr($css, $i, $brace - $i);
            $body = substr($css, $brace + 1, $bodyEnd - $brace - 1);

            $stripped = trim($prelude);
            $atName = '';

            if (str_starts_with($stripped, '@') && Re::match('/@([a-zA-Z-]+)/', $stripped, $m)) {
                $atName = str_replace(['-webkit-', '-moz-'], '', strtolower($m[1]));
            }

            $skip = in_array($atName, self::SKIP_AT_RULES, true);

            if (! $skip && $atName !== '') {
                foreach (self::SKIP_AT_RULES as $rule) {
                    if (str_ends_with($atName, $rule)) {
                        $skip = true;
                        break;
                    }
                }
            }

            if ($skip) {
                $out .= $prelude . '{' . $body . '}';
            } elseif (in_array($atName, self::NESTED_AT_RULES, true)) {
                $out .= $prelude . '{' . self::scopeBlock($body, $suffix) . '}';
            } elseif ($atName !== '') {
                $out .= $prelude . '{' . $body . '}';
            } else {
                $out .= self::scopeSelectorList($prelude, $suffix) . '{' . $body . '}';
            }

            $i = $bodyEnd + 1;
        }

        return $out;
    }

    private static function matchBrace(string $css, int $openIdx): int
    {
        $depth = 0;

        for ($i = $openIdx, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    private static function scopeSelectorList(string $prelude, string $suffix): string
    {
        $body = trim($prelude);

        if ($body === '') {
            return $prelude;
        }

        $lead = substr($prelude, 0, strlen($prelude) - strlen(ltrim($prelude)));

        $parts = array_map(
            static fn (string $s): string => self::scopeOneSelector($s, $suffix),
            self::splitSelectors($body),
        );

        return $lead . implode(', ', $parts) . ' ';
    }

    /** @return list<string> */
    private static function splitSelectors(string $sel): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;

        for ($i = 0, $n = strlen($sel); $i < $n; $i++) {
            $ch = $sel[$i];

            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }

            $buf .= $ch;
        }

        $parts[] = $buf;

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $p): bool => $p !== '',
        ));
    }

    /** Ghép suffix vào compound CUỐI, chèn trước pseudo đầu tiên của compound đó. */
    private static function scopeOneSelector(string $sel, string $suffix): string
    {
        $sel = trim($sel);

        if ($sel === '') {
            return $sel;
        }

        // Ranh giới compound cuối: khoảng trắng hoặc tổ hợp > + ~ ở mức ngoài
        $depth = 0;
        $cut = 0;

        for ($i = 0, $n = strlen($sel); $i < $n; $i++) {
            $ch = $sel[$i];

            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            } elseif ($depth === 0 && (ctype_space($ch) || $ch === '>' || $ch === '+' || $ch === '~')) {
                $cut = $i + 1;
            }
        }

        $head = substr($sel, 0, $cut);
        $last = substr($sel, $cut);

        if ($last === '') {
            return $sel . $suffix;
        }

        // Chèn trước pseudo đầu tiên (':hover', '::before') để pseudo vẫn ở cuối
        if (Re::match('/(?<!\\\\):/', $last, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1];

            return $head . substr($last, 0, $at) . $suffix . substr($last, $at);
        }

        return $head . $last . $suffix;
    }
}
