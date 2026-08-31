<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\Support\BladeComment;

/**
 * Bất biến GIỮ ĐỘ DÀI là điểm cốt lõi: mọi chỗ gọi đều tính offset trên bản
 * làm trắng rồi cắt bản GỐC. Lệch một byte là cắt nhầm chỗ — hỏng âm thầm.
 */
final class BladeCommentTest extends TestCase
{
    public function test_giu_nguyen_do_dai_va_so_dong(): void
    {
        $src = "a{{-- <style>x</style> --}}b\n@verbatim\n@props({g:1})\n@endverbatim\nc";

        $blanked = BladeComment::blank($src);

        self::assertSame(strlen($src), strlen($blanked), 'độ dài phải bằng nhau');
        self::assertSame(substr_count($src, "\n"), substr_count($blanked, "\n"), 'số dòng phải bằng nhau');
    }

    public function test_lam_trang_noi_dung_nhung_giu_xuong_dong(): void
    {
        self::assertSame('         ', BladeComment::blank('{{-- --}}'));
        self::assertSame("a\nb", BladeComment::blank("a\nb"), 'không có comment thì không đổi');

        // `{{--}}` KHÔNG phải comment Blade hợp lệ (thiếu `--}}`) — Laravel
        // cũng không coi là comment, nên phải để nguyên.
        self::assertSame('{{--}}', BladeComment::blank('{{--}}'));
    }

    public function test_van_ban_ngoai_comment_khong_bi_dung(): void
    {
        $src = '<style scoped>.that{}</style>';

        self::assertSame($src, BladeComment::blank($src));
    }

    public function test_lam_trang_ca_comment_lan_verbatim(): void
    {
        self::assertStringNotContainsString('gia', BladeComment::blank('{{-- gia --}}'));
        self::assertStringNotContainsString('gia', BladeComment::blank('@verbatim gia @endverbatim'));
    }

    public function test_chuoi_rong(): void
    {
        self::assertSame('', BladeComment::blank(''));
    }
}
