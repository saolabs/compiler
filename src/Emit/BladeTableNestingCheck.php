<?php

declare(strict_types=1);

namespace Saola\Compiler\Emit;

use Saola\Compiler\Support\Re;

/**
 * Soát `<tr>` nằm THẲNG trong `<table>` — nguồn lệch SSR/CSR âm thầm nhất còn lại.
 *
 * Bộ phân tích HTML của trình duyệt tự chèn `<tbody>` khi gặp `<tr>` trong
 * `<table>`; các hàm DOM (`createElement` + `appendChild`) thì KHÔNG. Hai đường
 * render của Saola đi đúng hai lối đó, nên cùng một nguồn ra hai cây khác nhau:
 *
 *     nguồn : <table><tr>…</tr></table>
 *     SSR   : table → tbody → tr      (parser chèn tbody)
 *     CSR   : table → tr              (DOM API không chèn)
 *
 * Đo được trên `/docs/directives` ngày 05/09/2026. Nguy ở chỗ nó KHÔNG hỏng
 * ngay: hydrate vẫn claim được `<tr>` vì tìm theo lớp con cháu. Nhưng cây
 * element của client ghi parent là `table` còn DOM thật là `tbody`, nên mọi
 * thao tác chèn/xoá sau đó nhắm sai parent — `@foreach` trong bảng sẽ chèn
 * `<tr>` thành anh em của `<tbody>`. Selector `table > tr` cũng chạy khác nhau.
 *
 * KHÔNG tự chèn `<tbody>` giùm. Chèn ở phía JS thôi thì `<tbody>` mới mang lớp
 * `{viewId}-{id}` mà bản SSR không có → hydrate không claim được, đổi một lỗi
 * im lặng lấy một lỗi im lặng khác. Chèn ở cả hai emitter thì dịch id của mọi
 * node phía sau. Cách sửa đúng nằm ở nguồn và chỉ tốn một dòng, nên báo lúc
 * compile để tác giả view tự thêm — cùng lập luận với {@see BladeBuiltinCheck}.
 */
final class BladeTableNestingCheck
{
    /** Thẻ nhóm hàng hợp lệ: thấy một trong số này thì `<tr>` phía sau đã có nhà. */
    private const ROW_GROUPS = ['tbody', 'thead', 'tfoot'];

    /** @return list<string> */
    public static function scan(string $blade, string $viewPath = ''): array
    {
        if (stripos($blade, '<table') === false || stripos($blade, '<tr') === false) {
            return [];
        }

        $where = $viewPath === '' ? '' : sprintf(' trong "%s"', $viewPath);
        $warnings = [];
        $offset = 0;

        while (Re::match('/<table\b/i', $blade, $m, PREG_OFFSET_CAPTURE, $offset) === true) {
            $start = (int) $m[0][1];
            $end = self::findTableEnd($blade, $start);
            $inner = substr($blade, $start, $end - $start);
            $offset = $end;

            // Chỉ soát bảng NGOÀI CÙNG của mỗi lần lặp; bảng lồng nhau nằm trong
            // `$inner` và sẽ được lượt sau bỏ qua — chấp nhận bỏ sót hơn báo sai.
            if (self::hasBareRow($inner)) {
                $warnings[] = sprintf(
                    '[sao2blade] Cảnh báo%s: `<tr>` nằm thẳng trong `<table>`. '
                    . 'Trình duyệt tự chèn `<tbody>` khi parse HTML từ server nhưng '
                    . 'render phía client thì không, nên SSR ra `table > tbody > tr` '
                    . 'còn CSR ra `table > tr`. Bọc các hàng trong `<tbody>` để hai '
                    . 'phía giống nhau.',
                    $where,
                );
            }
        }

        return $warnings;
    }

    /** Vị trí ngay sau `</table>` khớp với thẻ mở tại `$start` (hết chuỗi nếu thiếu). */
    private static function findTableEnd(string $blade, int $start): int
    {
        $depth = 0;
        $pos = $start;
        $length = strlen($blade);

        while ($pos < $length && Re::match('/<\/?table\b/i', $blade, $m, PREG_OFFSET_CAPTURE, $pos) === true) {
            $at = (int) $m[0][1];
            $depth += str_starts_with($m[0][0], '</') ? -1 : 1;
            $pos = $at + strlen($m[0][0]);
            if ($depth === 0) {
                $close = strpos($blade, '>', $pos);
                return $close === false ? $length : $close + 1;
            }
        }

        return $length;
    }

    /** Có `<tr>` xuất hiện trước bất kỳ thẻ nhóm hàng nào không. */
    private static function hasBareRow(string $table): bool
    {
        $firstRow = self::positionOf($table, 'tr');
        if ($firstRow === null) {
            return false;
        }

        foreach (self::ROW_GROUPS as $group) {
            $at = self::positionOf($table, $group);
            if ($at !== null && $at < $firstRow) {
                return false;
            }
        }

        return true;
    }

    private static function positionOf(string $haystack, string $tag): ?int
    {
        return Re::match('/<' . $tag . '\b/i', $haystack, $m, PREG_OFFSET_CAPTURE) === true
            ? (int) $m[0][1]
            : null;
    }
}
