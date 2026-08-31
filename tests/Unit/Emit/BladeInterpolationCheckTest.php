<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Emit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\Emit\BladeInterpolationCheck;

/**
 * Nội suy `"{$...}"` hỏng làm CẢ FILE .blade.php Parse error, không riêng phần
 * tử đó — nên đây là lỗi chí mạng, phải bắt lúc compile.
 *
 * Cổng parity không bắt được: cả hai bản sinh ra chuỗi hỏng giống hệt nhau.
 */
final class BladeInterpolationCheckTest extends TestCase
{
    /** Đúng những dạng @key mà 56 view production đang dùng — không được báo. */
    #[DataProvider('noiSuyHopLe')]
    public function test_khong_bao_noi_suy_php_parse_duoc(string $expr): void
    {
        self::assertSame([], BladeInterpolationCheck::scan('<li @class(["x-' . $expr . '"])>a</li>'));
    }

    /** @return array<string, array{0: string}> */
    public static function noiSuyHopLe(): array
    {
        return [
            'thuộc tính' => ['{$i->id}'],
            'mảng' => ["{\$i['id']}"],
            'loop' => ['{$loop->index}'],
            'lồng nhiều cấp' => ['{$i->a->b}'],
            'gọi method' => ['{$i->m()}'],
        ];
    }

    #[DataProvider('noiSuyHong')]
    public function test_bat_noi_suy_php_khong_parse_duoc(string $expr): void
    {
        $warnings = BladeInterpolationCheck::scan('<li @class(["x-' . $expr . '"])>a</li>', 'v.test');

        self::assertCount(1, $warnings);
        self::assertStringContainsString($expr, $warnings[0]);
        self::assertStringContainsString('v.test', $warnings[0]);
    }

    /** @return array<string, array{0: string}> */
    public static function noiSuyHong(): array
    {
        return [
            'nối chuỗi' => ["{\$i->id . '-' . \$i->n}"],
            'cộng' => ['{$a + $b}'],
            'thiếu $ ở vế sau' => ["{\$i->id + '-' + i->n}"],
        ];
    }

    public function test_blade_khong_co_noi_suy_thi_bo_qua_som(): void
    {
        self::assertSame([], BladeInterpolationCheck::scan('<p>không có nội suy</p>'));
    }

    /** Cùng một biểu thức hỏng xuất hiện nhiều lần chỉ báo MỘT lần. */
    public function test_khu_trung_lap(): void
    {
        $bad = "{\$a . '-' . \$b}";

        self::assertCount(1, BladeInterpolationCheck::scan("\"x{$bad}\" và \"y{$bad}\""));
    }
}
