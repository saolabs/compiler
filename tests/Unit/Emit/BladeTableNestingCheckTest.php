<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Emit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\Emit\BladeTableNestingCheck;

/**
 * `<tr>` thẳng trong `<table>`: parser HTML của trình duyệt chèn `<tbody>`,
 * DOM API thì không — SSR ra `table > tbody > tr`, CSR ra `table > tr`.
 *
 * Cổng parity SSR↔CSR (`saola/tests/e2e/ssr-csr-parity.e2e.test.ts`) BẮT được
 * lệch này, nhưng chỉ trên route nào có bảng. Check ở đây chặn từ lúc compile,
 * kể cả view chưa có route nào trỏ tới.
 *
 * Như mọi check khác: giá trị nằm ở chỗ KHÔNG báo nhầm. Báo nhầm một lần là
 * người ta học cách phớt lờ cảnh báo, và nó thành vô dụng.
 */
final class BladeTableNestingCheckTest extends TestCase
{
    private static function warns(string $blade): bool
    {
        return BladeTableNestingCheck::scan($blade, 'web.test') !== [];
    }

    public function test_bat_tr_tran_trong_table(): void
    {
        self::assertTrue(self::warns('<table><tr><td>a</td></tr></table>'));
        self::assertTrue(self::warns("<table class=\"x\">\n    <tr><td>a</td></tr>\n</table>"));
        // Thẻ đã qua sao2blade vẫn phải bắt được.
        self::assertTrue(self::warns('<table @class([$__VIEW_ID__ . \'-e1\'])><tr @class([$__VIEW_ID__ . \'-e11\'])><td>a</td></tr></table>'));
    }

    public function test_khong_bao_khi_hang_da_co_nhom(): void
    {
        self::assertFalse(self::warns('<table><tbody><tr><td>a</td></tr></tbody></table>'));
        self::assertFalse(self::warns('<table><thead><tr><th>h</th></tr></thead></table>'));
        self::assertFalse(self::warns('<table><tfoot><tr><td>f</td></tr></tfoot></table>'));
    }

    public function test_khong_bao_khi_khong_lien_quan(): void
    {
        self::assertFalse(self::warns('<div><p>không có bảng nào</p></div>'));
        self::assertFalse(self::warns('<table><caption>chỉ có caption</caption></table>'));
    }

    public function test_moi_bang_duoc_soat_rieng(): void
    {
        // Bảng đúng đứng trước KHÔNG được che cho bảng sai đứng sau.
        $blade = '<table><tbody><tr><td>a</td></tr></tbody></table>'
               . '<table><tr><td>b</td></tr></table>';
        self::assertCount(1, BladeTableNestingCheck::scan($blade, 'web.test'));
    }

    public function test_bang_long_nhau_bo_sot_chu_khong_bao_sai(): void
    {
        // Bảng trong nằm gọn trong `$inner` của bảng ngoài nên lượt quét sau bỏ
        // qua. Bỏ sót có chủ đích — báo thiếu tốt hơn báo sai.
        $blade = '<table><tbody><tr><td><table><tr><td>x</td></tr></table></td></tr></tbody></table>';
        self::assertFalse(self::warns($blade));
    }

    public function test_thieu_the_dong_khong_lam_treo(): void
    {
        self::assertTrue(self::warns('<table><tr><td>a</td></tr>'));
    }
}
