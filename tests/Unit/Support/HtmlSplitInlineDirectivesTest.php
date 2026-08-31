<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\Support\Html;

/**
 * Hồi quy cho §14: nội dung nằm CÙNG DÒNG với `@if`/`@foreach` bị mất khỏi JS
 * và không được cấp hydrate id ở Blade.
 *
 * Cổng parity KHÔNG bắt được bug này — cả Python lẫn PHP đều sai giống hệt
 * nhau nên gate vẫn xanh. Đó là điểm mù của parity: nó chứng minh hai bản
 * GIỐNG nhau, không chứng minh bản nào ĐÚNG. Bất biến phải giữ bằng test.
 */
final class HtmlSplitInlineDirectivesTest extends TestCase
{
    public function test_tach_noi_dung_dinh_cung_dong(): void
    {
        self::assertSame(
            "@if(x > 0)\n<span>a</span>\n@endif",
            Html::splitInlineDirectives('@if(x > 0)<span>a</span>@endif'),
        );

        self::assertSame(
            "@foreach(a as b)\n<i>k</i>\n@endforeach",
            Html::splitInlineDirectives('@foreach(a as b)<i>k</i>@endforeach'),
        );
    }

    /** View viết bình thường phải KHÔNG đổi một byte — kể cả thụt lề. */
    public function test_view_da_dung_dinh_dang_la_no_op(): void
    {
        $input = "    @if(x > 0)\n        <span>a</span>\n    @endif\n";

        self::assertSame($input, Html::splitInlineDirectives($input));
    }

    /** Trong thẻ HTML, `@class`/`@click`/`@if` là directive THUỘC TÍNH. */
    public function test_khong_xe_directive_thuoc_tinh_trong_the(): void
    {
        $input = '<div @class([1]) @click(go())>x</div>';

        self::assertSame($input, Html::splitInlineDirectives($input));
    }

    /** Trang docs in ví dụ `@if(...)` là văn bản, tách sẽ hỏng thứ nó mô tả. */
    public function test_khong_dung_vao_comment_va_verbatim(): void
    {
        $comment = '{{-- @if(x)<b>a</b>@endif --}}';
        self::assertSame($comment, Html::splitInlineDirectives($comment));

        $verbatim = '@verbatim <code>@if(x)<b>a</b>@endif</code> @endverbatim';
        self::assertSame($verbatim, Html::splitInlineDirectives($verbatim));
    }

    /** `@iffy(` không phải `@if`; tên dài phải thắng tên ngắn. */
    public function test_khong_khop_nham_ten_dai_hon(): void
    {
        self::assertSame('@iffy(x)<b>a</b>', Html::splitInlineDirectives('@iffy(x)<b>a</b>'));

        // @endforeach phải được nhận trọn, không bị @endfor nuốt mất
        self::assertSame(
            "@foreach(a as b)\n<i>k</i>\n@endforeach",
            Html::splitInlineDirectives('@foreach(a as b)<i>k</i>@endforeach'),
        );
    }

    public function test_directive_khong_ngoac_van_duoc_tach(): void
    {
        self::assertSame(
            "@if(x)\n<b>a</b>\n@else\n<i>b</i>\n@endif",
            Html::splitInlineDirectives('@if(x)<b>a</b>@else<i>b</i>@endif'),
        );
    }

    /**
     * Bug §8② LẶP LẠI trong chính hàm này: `>` NẰM TRONG ngoặc là toán tử so
     * sánh, không phải dấu đóng thẻ. Không đếm ngoặc thì `<div @if(x>0) a
     * @endif>` bị coi là hết thẻ ở `x>0`, rồi `@endif` bị tách ra dòng riêng —
     * xé nát thẻ và làm mất luôn hydrate id.
     */
    public function test_dau_lon_hon_trong_ngoac_khong_dong_the(): void
    {
        $input = "<div @if(x>0) data-a='1' @endif>y</div>";

        self::assertSame($input, Html::splitInlineDirectives($input));
    }

    /**
     * Mỗi directive dưới đây từng hỏng RIÊNG và chỉ lộ ra khi sweep có hệ
     * thống — probe thủ công ban đầu báo `@section` "OK" do lỗi escaping bash.
     */
    #[DataProvider('directiveNuotDong')]
    public function test_moi_directive_nuot_dong_deu_duoc_tach(string $input, string $expected): void
    {
        self::assertSame($expected, Html::splitInlineDirectives($input));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function directiveNuotDong(): array
    {
        return [
            '@key' => ['@key(i.id)<li>a</li>', "@key(i.id)\n<li>a</li>"],
            '@wrapper' => ['@wrapper<b>a</b>@endwrapper', "@wrapper\n<b>a</b>\n@endwrapper"],
            '@section' => ["@section('s')<h3>a</h3>@endsection", "@section('s')\n<h3>a</h3>\n@endsection"],
            '@block' => ["@block('b')<h4>a</h4>@endblock", "@block('b')\n<h4>a</h4>\n@endblock"],
        ];
    }
}
