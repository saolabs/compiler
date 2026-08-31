<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Emit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\Emit\BladeBuiltinCheck;

/**
 * Tên có sẵn của JS rơi vào Blade: CSR chạy đúng, SSR nổ — đúng lớp lệch
 * SSR/CSR mà dự án chống. Cổng parity KHÔNG bắt được (cả hai bản compiler sinh
 * ra y hệt nhau, cùng sai), nên bất biến phải giữ bằng test.
 *
 * Giá trị thật của check nằm ở chỗ KHÔNG báo nhầm: nếu báo nhầm văn xuôi hay
 * @verbatim thì lập trình viên sẽ học cách phớt lờ cảnh báo, và nó thành vô dụng.
 */
final class BladeBuiltinCheckTest extends TestCase
{
    private static function names(string $blade): array
    {
        $out = [];
        foreach (BladeBuiltinCheck::scan($blade) as $w) {
            if (preg_match('/`([^`]+)`/', $w, $m)) {
                $out[] = $m[1];
            }
        }
        sort($out);

        return $out;
    }

    public function test_bat_builtin_trong_moi_vi_tri_php_tho(): void
    {
        self::assertSame(['String'], self::names('<p>{{ String($x) }}</p>'));
        self::assertSame(['JSON'], self::names('<p>{!! JSON.stringify($x) !!}</p>'));
        self::assertSame(['Number'], self::names('@if(Number($x) > 0) a @endif'));
        self::assertSame(['Math'], self::names('<?php $a = Math.max($x, 1); ?>'));
    }

    /** '.' trong PHP là nối chuỗi, nên `Math.max(...)` là hằng `Math` chưa định nghĩa. */
    public function test_bat_ca_dang_truy_cap_thuoc_tinh(): void
    {
        self::assertSame(['Date'], self::names('{{ Date.now() }}'));
    }

    /**
     * PHP không phân biệt hoa thường ở tên hàm: `Array(x)`→`array(x)`,
     * `Date(x)`→`date(x)`. Không Fatal nhưng ra giá trị KHÁC — im lặng, tệ hơn.
     */
    public function test_bat_ca_ten_khong_no_nhung_sai_am_tham(): void
    {
        self::assertSame(['Array'], self::names('{{ Array($x) }}'));
    }

    public function test_khong_bao_nham_van_xuoi_va_chuoi_ky_tu(): void
    {
        // Văn bản giữa các thẻ không phải mã
        self::assertSame([], self::names('<p>Bài này nói về Math.max và JSON.parse</p>'));
        // Chuỗi ký tự bên trong biểu thức cũng không phải mã
        self::assertSame([], self::names("{{ 'Math.max nhanh hơn' }}"));
        self::assertSame([], self::names('{{ "dùng JSON.stringify nhé" }}'));
    }

    public function test_khong_bao_nham_trong_comment_va_verbatim(): void
    {
        self::assertSame([], self::names('{{-- {{ String($x) }} --}}'));
        self::assertSame([], self::names('@verbatim <code>{{ Math.max(1) }}</code> @endverbatim'));
    }

    /**
     * `useState(` nằm HỢP LỆ trong `@const(...)` — directive khai báo của Saola
     * có handler riêng, không phải PHP thô. Báo ở đây là báo sai.
     */
    public function test_khong_bao_nham_directive_khai_bao_cua_saola(): void
    {
        self::assertSame([], self::names('@const([$a, $setA] = useState(false))'));
        self::assertSame([], self::names('@useState($x, 0)'));
    }

    public function test_tu_khoa_php_hop_le_khong_bi_bat(): void
    {
        self::assertSame([], self::names('@if(isset($x)) a @endif'));
        self::assertSame([], self::names('{{ count($items) }}'));
    }
}
