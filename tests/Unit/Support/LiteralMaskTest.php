<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\Support\LiteralMask;

/**
 * Hồi quy cho bug ① (docs/05-roadmap.md §7): rò `__STR_LIT_0__` ra output.
 *
 * Cổng parity KHÔNG bảo vệ được chỗ này sau khi bản Python bị gỡ ở P6 — lúc
 * đó không còn oracle nào. Test này giữ lại bất biến thứ tự.
 */
final class LiteralMaskTest extends TestCase
{
    public function test_khoi_phuc_lop_ngoai_truoc_lop_trong(): void
    {
        $mask = new LiteralMask('STR_LIT');

        // Nháy đơn được che TRƯỚC nên mang index THẤP hơn chuỗi bọc ngoài nó
        $expr = '"@if(status === \'ready\')"';
        $masked = $mask->mask($expr);

        $this->assertStringNotContainsString('ready', $masked, 'Literal phải được che hết');
        $this->assertSame($expr, $mask->unmask($masked), 'Khôi phục phải ra đúng chuỗi ban đầu');
    }

    public function test_khong_con_placeholder_sot_lai(): void
    {
        $mask = new LiteralMask('STR_LIT');
        $restored = $mask->unmask($mask->mask('"ngoài \'trong\' ngoài"'));

        // Đây chính là triệu chứng của bug: `__STR_LIT_0__` còn nguyên trong output
        $this->assertStringNotContainsString('__STR_LIT_', $restored);
    }

    public function test_nhieu_literal_long_nhau(): void
    {
        $mask = new LiteralMask('X');
        $expr = '"a \'b\' c" + \'d\' + "e \'f\' g"';

        $this->assertSame($expr, $mask->unmask($mask->mask($expr)));
    }

    public function test_khong_co_literal_thi_giu_nguyen(): void
    {
        $mask = new LiteralMask('X');

        $this->assertTrue($mask->isEmpty());
        $this->assertSame('a + b', $mask->unmask($mask->mask('a + b')));
        $this->assertTrue($mask->isEmpty());
    }

    public function test_transform_ap_dung_cho_tung_literal(): void
    {
        $mask = new LiteralMask('X');
        $masked = $mask->mask("'a' + 'b'");

        $out = $mask->unmask($masked, static fn (string $lit): string => strtoupper($lit));

        $this->assertSame("'A' + 'B'", $out);
    }
}
