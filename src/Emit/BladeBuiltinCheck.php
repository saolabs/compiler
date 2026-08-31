<?php

declare(strict_types=1);

namespace Saola\Compiler\Emit;

use Saola\Compiler\Expr\KnownFunctions;
use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\Re;

/**
 * Soát Blade ĐÃ SINH, tìm tên có sẵn của JS mà PHP không chạy được.
 *
 * `KnownFunctions::JS_BUILTINS` tồn tại để JsEmitter KHÔNG gắn tiền tố
 * `App.Helper.` — đúng cho phía JS. Nhưng cùng chuỗi biểu thức đó cũng đi
 * thẳng vào Blade, nơi PHP không có `String`, `Math`, `JSON`. Hệ quả là đúng
 * lớp lệch SSR/CSR mà cả dự án đang chống: CSR chạy đúng, SSR nổ lúc render.
 *
 *     {{ String(x) }}      → PHP: Call to undefined function String()
 *     {{ Math.max(a, b) }} → PHP: Undefined constant "Math"  ('.' là nối chuỗi)
 *
 * Tệ hơn Fatal là im lặng: PHP KHÔNG phân biệt hoa thường ở tên hàm, nên
 * `Array(x)` chạy thành `array(x)` và `Date(x)` thành `date(x)` — ra giá trị
 * khác hẳn JS mà không báo gì. Đó là lý do cảnh báo cả những tên "không nổ".
 *
 * KHÔNG tự dịch sang hàm PHP tương đương (`Math.round`→`round`,
 * `JSON.stringify`→`json_encode`): ngữ nghĩa lệch nhau ở biên (làm tròn .5,
 * escape unicode, ms so với giây), nên dịch sai sẽ biến một lỗi Fatal ồn ào
 * thành sai số im lặng — tệ hơn hẳn. Báo lúc compile, để tác giả view chọn.
 */
final class BladeBuiltinCheck
{
    /**
     * Từ khoá JS: không bao giờ là lời gọi hàm trong Blade, và phần lớn cũng là
     * từ khoá PHP hợp lệ (`if`, `for`, `class`...). Bỏ ra khỏi danh sách soát.
     */
    private const KEYWORDS = [
        'function', 'return', 'if', 'else', 'for', 'while', 'do', 'switch',
        'case', 'break', 'continue', 'new', 'delete', 'typeof', 'instanceof',
        'void', 'throw', 'try', 'catch', 'finally', 'class', 'extends',
        'import', 'export', 'default', 'const', 'let', 'var', 'async', 'await',
        'yield', 'super', 'this', 'true', 'false', 'null', 'undefined',
    ];

    /**
     * Directive mà Blade dịch thẳng thành PHP thô (`@if(x)` → `<?php if(x): ?>`).
     *
     * CỐ Ý không gồm directive khai báo của Saola (`@const`, `@let`, `@vars`,
     * `@useState`): `useState(` nằm hợp lệ trong đó và có handler riêng, soát
     * vào sẽ báo nhầm. Bỏ sót ở đó chấp nhận được — báo thiếu tốt hơn báo sai.
     */
    private const PHP_DIRECTIVES = [
        'if', 'elseif', 'unless', 'foreach', 'forelse', 'while', 'switch', 'case',
    ];

    /**
     * @return list<string> cảnh báo, đã khử trùng lặp theo tên
     */
    public static function scan(string $blade, string $viewPath = ''): array
    {
        if ($blade === '') {
            return [];
        }

        // Comment và @verbatim là văn bản nguyên văn — trang docs in ví dụ
        // `Math.max` sẽ bị báo nhầm nếu không che.
        $blade = Re::replace('/\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b/si', ' ', $blade);

        $names = array_values(array_diff(KnownFunctions::JS_BUILTINS, self::KEYWORDS));
        if ($names === []) {
            return [];
        }

        $pattern = '/\b(' . implode('|', array_map('preg_quote', $names)) . ')\s*[.(]/';

        $found = [];
        foreach (self::phpRegions($blade) as $region) {
            // Chuỗi ký tự không phải mã: `{{ 'Math.max nhanh hơn' }}` không sai.
            $region = Re::replace('/\'[^\']*\'|"[^"]*"/', ' ', $region);

            foreach (Re::matchAll($pattern, $region) as $set) {
                $found[$set[1]] = true;
            }
        }

        if ($found === []) {
            return [];
        }

        $where = $viewPath === '' ? '' : sprintf(' trong "%s"', $viewPath);
        $warnings = [];

        foreach (array_keys($found) as $name) {
            $warnings[] = sprintf(
                '[sao2blade] Cảnh báo%s: `%s` là tên có sẵn của JS, PHP không có — '
                . 'CSR chạy đúng nhưng SSR sẽ lỗi lúc render (hoặc tệ hơn: `Array`/`Date` '
                . 'trùng `array`/`date` của PHP nên ra giá trị khác mà không báo). '
                . 'Dùng helper của view hoặc tính sẵn trong <script setup>.',
                $where,
                $name,
            );
        }

        return $warnings;
    }

    /**
     * Các vùng CHẮC CHẮN là PHP thô trong Blade. Nội dung văn bản giữa các thẻ
     * không được soát — `<p>JSON.parse là hàm JS</p>` là văn xuôi, không phải mã.
     *
     * @return list<string>
     */
    private static function phpRegions(string $blade): array
    {
        $regions = [];

        // {!! !!} phải khớp TRƯỚC {{ }}, nếu không '{' đầu bị nuốt sai.
        foreach (Re::matchAll('/\{!!(.*?)!!\}|\{\{(.*?)\}\}/s', $blade) as $set) {
            $regions[] = ($set[1] ?? '') . ' ' . ($set[2] ?? '');
        }

        foreach (Re::matchAll('/<\?php(.*?)\?>/s', $blade) as $set) {
            $regions[] = $set[1];
        }

        $directives = implode('|', self::PHP_DIRECTIVES);
        $sets = Re::matchAll(
            '/@(' . $directives . ')\s*\(/i',
            $blade,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($sets as $set) {
            // $set[0] = [text, offset]; '(' là ký tự cuối của match.
            $open = $set[0][1] + strlen($set[0][0]) - 1;
            [$args] = Balanced::extractParensAt($blade, $open);

            if ($args !== null) {
                $regions[] = $args;
            }
        }

        return $regions;
    }
}
