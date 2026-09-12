<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Preprocessor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `@show(…)`/`@hide(…)` đã bị gỡ — phải báo lỗi, không được sinh HTML sai.
 *
 * Trước đây chúng hỏng ÂM THẦM và hỏng KHÁC NHAU ở hai nhánh: nhánh JS để
 * `AstParser` nuốt `@show(vis)` thành hai thuộc tính tĩnh rác (`<div show vis>`),
 * nhánh Blade để nguyên chuỗi trong thẻ — nơi `@show` lại là directive section
 * CÓ SẴN của Laravel. Hai cây DOM khác nhau thì hydrate không thể khớp.
 *
 * Test đi qua API công khai chứ không gọi thẳng preprocessor, vì bất biến cần
 * giữ là "KHÔNG nhánh nào biên dịch lọt" — chốt ở một nhánh thì nhánh kia lặng
 * lẽ hồi sinh đúng con bug cũ.
 *
 * Xem docs/SAO_ELEMENT_DIRECTIVES_RFC.md §10.1 (E-08).
 */
final class ShowHideRemovedTest extends TestCase
{
    private static function compile(string $source): void
    {
        (new SaolaCompiler())->compile($source, CompileOptions::fromArray([
            'view-path' => 's',
            'fn' => 'S',
            'factory' => 'S',
        ]));
    }

    /** @return iterable<string, array{string}> */
    public static function nguonBiTuChoi(): iterable
    {
        yield '@show trên thẻ' => ["@states({ vis: true })\n\n<div class=\"box\" @show(vis)>x</div>"];
        yield '@hide trên thẻ' => ["@states({ vis: true })\n\n<div class=\"box\" @hide(vis)>x</div>"];
        yield '@show biểu thức phức' => ["@states({ n: 0 })\n\n<div @show(n > 0 && n < 9)>x</div>"];
        yield '@show đứng riêng' => ['<div>x</div>@show(true)'];
        yield '@show có khoảng trắng' => ["@states({ vis: true })\n\n<div @show (vis)>x</div>"];
        yield '@SHOW hoa' => ["@states({ vis: true })\n\n<div @SHOW(vis)>x</div>"];
    }

    #[DataProvider('nguonBiTuChoi')]
    public function testBaoLoiBienDich(string $source): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/đã bị gỡ khỏi Saola/');
        self::compile($source);
    }

    /**
     * `@show` TRẦN là directive section của Laravel Blade (`@section`…`@show`),
     * nghĩa hoàn toàn khác và vẫn hợp lệ. Guard chỉ bắt dạng có ngoặc.
     */
    public function testShowTranCuaBladeVanChay(): void
    {
        self::compile("@section('a')\n<p>x</p>\n@show");
        $this->addToAssertionCount(1);
    }

    /** Vùng nguyên văn là văn bản, không phải directive — tài liệu phải in được `@show(`. */
    public function testVungNguyenVanKhongBiBat(): void
    {
        self::compile("@verbatim\n<div @show(vis)>x</div>\n@endverbatim");
        self::compile('{{-- <div @show(vis)>x</div> --}}<p>x</p>');
        $this->addToAssertionCount(1);
    }
}
