<?php

declare(strict_types=1);

namespace Saola\Compiler\Emit;

use Saola\Compiler\Support\Re;

/**
 * Soát nội suy `"{$...}"` trong Blade ĐÃ SINH xem PHP có parse nổi không.
 *
 * Id hydrate của phần tử trong vòng lặp được nhúng vào chuỗi nháy kép:
 *
 *     <li @class([$__VIEW_ID__ . "-l11-{$i->id}"])>
 *
 * Nội suy `{$...}` của PHP CHỈ nhận biểu thức đơn giản — biến, `->prop`,
 * `['key']`, gọi method. KHÔNG nhận toán tử. Nên `@key(i.id + '-' + i.n)` sinh
 * ra `"{$i->id . '-' . $i->n}"` và cả FILE blade thành Parse error, không riêng
 * gì phần tử đó.
 *
 * `saoToBladeExpr()` (BladeHydrateProcessor) là transform bằng regex chỉ gắn
 * MỘT dấu `$` ở đầu — thiết kế cho biểu thức đơn giản. Sửa nó để "cố dịch cho
 * đúng" cũng vô ích: PHP vẫn không nội suy nổi biểu thức có toán tử. Muốn đỡ
 * thì phải đổi cách sinh id sang nối chuỗi ngoài literal — đụng vào đúng phần
 * an toàn nhất của hệ thống (bất biến I2) vì một cú pháp 0/56 view dùng.
 *
 * Nên: BÁO lúc compile, đừng để tác giả view phát hiện bằng trang trắng.
 *
 * Không đoán bằng heuristic — hỏi thẳng PHP qua `token_get_all(..., TOKEN_PARSE)`.
 */
final class BladeInterpolationCheck
{
    /**
     * @return list<string> cảnh báo, đã khử trùng lặp
     */
    public static function scan(string $blade, string $viewPath = ''): array
    {
        if (! str_contains($blade, '{$')) {
            return [];
        }

        $found = [];

        foreach (Re::matchAll('/\{\$[^{}]*\}/', $blade) as $set) {
            $expr = $set[0];

            if (isset($found[$expr])) {
                continue;
            }

            try {
                token_get_all('<?php "x' . $expr . '";', TOKEN_PARSE);
            } catch (\ParseError) {
                $found[$expr] = true;
            }
        }

        if ($found === []) {
            return [];
        }

        $where = $viewPath === '' ? '' : sprintf(' trong "%s"', $viewPath);
        $warnings = [];

        foreach (array_keys($found) as $expr) {
            $warnings[] = sprintf(
                '[sao2blade] LỖI%s: `%s` — nội suy chuỗi của PHP không nhận biểu thức '
                . 'có toán tử, nên CẢ FILE .blade.php sẽ Parse error lúc render. '
                . 'Thường gặp ở `@key(...)` dùng biểu thức phức hợp: hãy tính sẵn giá trị '
                . '(vd thêm một field `key` vào từng phần tử) rồi `@key(item.key)`.',
                $where,
                $expr,
            );
        }

        return $warnings;
    }
}
