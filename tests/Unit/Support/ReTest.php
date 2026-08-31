<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\Support\Re;
use Saola\Compiler\Support\RegexException;

/**
 * `Re` tồn tại vì `preg_*` trả null khi lỗi mà KHÔNG phát tín hiệu gì — giá trị
 * null đó trôi qua cả pipeline rồi mới lộ ra ở output sai.
 *
 * Test này giữ đúng tính chất đó: lỗi phải NỔ, không được im lặng.
 */
final class ReTest extends TestCase
{
    public function test_pattern_hong_thi_nem_chu_khong_tra_null(): void
    {
        $this->expectException(RegexException::class);
        @Re::match('/(chưa đóng ngoặc/', 'abc');
    }

    /**
     * UTF-8 hỏng + cờ /u là lỗi PCRE dễ gặp nhất trong dự án này: nguồn `.sao`
     * đến từ người dùng và có thể chứa byte không hợp lệ.
     *
     * (Không test backtrack limit: PCRE2 tự tối ưu possessive nên các pattern
     * "thảm hoạ" kinh điển không còn kích hoạt được nó một cách tin cậy.)
     */
    public function test_utf8_hong_voi_co_u_thi_nem(): void
    {
        $this->expectException(RegexException::class);
        Re::replace('/./u', 'x', "\xC3\x28");
    }

    public function test_replace_callback_pattern_hong_khong_ro_warning_php(): void
    {
        $this->expectException(RegexException::class);
        $this->expectExceptionMessage("Unknown modifier 'c'");

        Re::replaceCallback('/a/compiler', static fn (): string => 'b', 'a');
    }

    public function test_offset_neo_dung_vi_tri_voi_G(): void
    {
        // \G + offset là cách thay cho regex.match(subject, pos) của Python
        $this->assertTrue(Re::match('/\G[a-z]+/', 'AA-bbb', $m, 0, 3));
        $this->assertSame('bbb', $m[0]);
        $this->assertFalse(Re::match('/\G[a-z]+/', 'AA-bbb', $m, 0, 0));
    }

    public function test_split_va_matchAll_tra_ve_mang(): void
    {
        $this->assertSame(['a', 'b', 'c'], Re::split('/,/', 'a,b,c'));
        $this->assertCount(2, Re::matchAll('/\d+/', 'x1 y22'));
    }
}
