<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Directive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * Ba dạng handler phải dùng được Ở CẢ element lẫn component — chúng đi chung
 * một DSL, khác nhau chỉ ở chỗ gắn listener.
 *
 * Hai dạng từng hỏng CÂM vì preprocessor thêm '$' vào tên và tham số trước khi
 * EventDirectiveProcessor soi tới, mà regex nhận dạng lại viết không có '$':
 *
 *   @click(handler)          → `() => handler`            bấm không chạy gì
 *   @click((a, b) => f(a,b)) → `() => (a, b) => f(a,b)`   trả về hàm, không gọi
 *
 * Cổng parity KHÔNG bắt được: `@click` không sinh ra gì ở phía Blade nên hai
 * bên vẫn khớp trong khi phía client chết lặng.
 */
final class HandlerArityTest extends TestCase
{
    private function compile(string $template, string $setup = ''): string
    {
        $source = "@states({ n: 1 })\n<template>\n{$template}\n</template>\n"
            . "<script setup>\nfunction f(a, b) {}\nfunction g(a) {}\n{$setup}\n</script>";

        return (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.view',
            functionName: 'TestView',
            factoryName: 'TestViewFactory',
        ))->js ?? '';
    }

    private function eventConfig(string $handler): string
    {
        preg_match(
            '/events: \{ click: (\[.*?\]) \}/',
            $this->compile("<button @click({$handler})>x</button>"),
            $m,
        );

        return $m[1] ?? '';
    }

    /**
     * Tên trần là TRUYỀN THAM CHIẾU, khác `handler()` là gọi không tham số.
     *
     * Bỏ hẳn khoá `params` chứ không để `[]`: runtime chỉ rơi vào mặc định
     * `[event]` khi `params` vắng mặt, còn `[]` nghĩa là "gọi với đúng không
     * đối số" — đúng cho `handler()` nhưng nuốt mất event của dạng này.
     */
    public function test_ten_tran_truyen_tham_chieu_chu_khong_boc_arrow(): void
    {
        $this->assertSame('[{"handler":"g"}]', $this->eventConfig('g'));
    }

    public function test_goi_khong_tham_so_van_giu_params_rong(): void
    {
        $this->assertSame('[{"handler":"g","params":[]}]', $this->eventConfig('g()'));
    }

    #[DataProvider('arrowGiuNguyenArity')]
    public function test_arrow_nguoi_dung_viet_khong_bi_boc_them_lop(string $handler, string $mongDoi): void
    {
        $this->assertSame($mongDoi, $this->eventConfig($handler));
    }

    /**
     * Nhận dạng arrow theo CẤU TRÚC (có `=>` ở mức ngoài, vế trái là định danh
     * hoặc cặp ngoặc cân bằng), không liệt kê dạng tham số — nếu không thì mỗi
     * kiểu tham số mới lại phải vá một lần.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function arrowGiuNguyenArity(): iterable
    {
        yield 'khong tham so' => ['() => g(1)', '[() => this.view.g(1)]'];
        yield 'mot tham so' => ['a => g(a)', '[a => this.view.g(a)]'];
        yield 'hai tham so' => ['(a, b) => f(a, b)', '[(a, b) => this.view.f(a, b)]'];
        yield 'rest' => ['(...args) => g(args)', '[(...args) => this.view.g(args)]'];
        yield 'mac dinh' => ['(a = 1) => g(a)', '[(a = 1) => this.view.g(a)]'];
        yield 'go roi' => ['({id, title}) => f(id, title)', '[({id, title}) => this.view.f(id, title)]'];
    }

    /**
     * `@on('tên', handler)` — dạng tổng quát: tên sự kiện là DỮ LIỆU nên đặt
     * được cả tên không phải định danh, và không đọc nhầm với sự kiện DOM.
     * Ra CÙNG khoá `on$<tên>` với dạng `@edit(handler)`.
     */
    public function test_on_dat_duoc_ten_khong_phai_dinh_danh(): void
    {
        $source = "@import('a.b' as child)\n@states({ n: 1 })\n"
            . "<template>\n<child :x=\"n\" @on('user:saved', g) "
            . "@on('cart.add', ({id}) => f(id, 1)) />\n</template>\n"
            . "<script setup>\nfunction f(a, b) {}\nfunction g(a) {}\n</script>";

        $result = (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.view',
            functionName: 'TestView',
            factoryName: 'TestViewFactory',
        ));

        $this->assertStringContainsString(
            '{ "user:saved": g, "cart.add": ({id}) => this.view.f(id, 1) }',
            $result->js ?? '',
        );
        $this->assertStringNotContainsString('on$', $result->blade ?? '');
    }

    /** Tên sự kiện có ':' KHÔNG được bộ quét `:prop` khớp lại rồi dịch lần hai. */
    public function test_ten_su_kien_co_dau_hai_cham_khong_bi_dich_lan_hai(): void
    {
        $source = "@import('a.b' as child)\n@states({ n: 1 })\n"
            . "<template>\n<child @on('user:saved', (...args) => g(args)) />\n</template>\n"
            . "<script setup>\nfunction g(a) {}\n</script>";

        $js = (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.view',
            functionName: 'TestView',
            factoryName: 'TestViewFactory',
        ))->js ?? '';

        $this->assertStringContainsString('"user:saved": (...args) => this.view.g(args)', $js);
        $this->assertStringNotContainsString('$args', $js);
    }

    public function test_on_thieu_ten_chuoi_thi_bao_loi_ro_rang(): void
    {
        $this->expectExceptionMessageMatches("/@on\(\) cần tên sự kiện dạng chuỗi/");

        (new SaolaCompiler())->compile(
            "@import('a.b' as child)\n<template>\n<child @on(g) />\n</template>\n"
            . "<script setup>\nfunction g(a) {}\n</script>",
            new CompileOptions(viewPath: 'test.view', functionName: 'TestView', factoryName: 'TestViewFactory'),
        );
    }

    /**
     * Thân khối `=> { … }`: dấu ngoặc nhọn phải sống sót (preprocessor đổi mọi
     * '{' thành '[' của mảng PHP), và dấu ';' bên trong KHÔNG được cắt biểu
     * thức thành hai handler rời.
     */
    public function test_arrow_than_khoi_giu_ngoac_nhon_va_khong_bi_cat_o_dau_cham_phay(): void
    {
        $this->assertSame(
            '[(a, b) => { this.view.g(a); this.view.f(a, b) }]',
            $this->eventConfig('(a, b) => { g(a); f(a, b) }'),
        );
    }

    /**
     * `on$<tên>` trong data của @include là LISTENER, không phải prop: nó rời
     * mảng data, thành đối số listener của this.include(), và không được tính
     * vào stateKeys — một handler nhắc tới state không phải lý do để đẩy prop
     * mới xuống con mỗi lần state đó đổi.
     */
    public function test_khoa_on_tach_khoi_props_va_khong_vao_stateKeys(): void
    {
        $js = $this->compile("@include('a.b', {x: n, on\$save: g, on\$pair: (a, b) => f(a, b)})");

        $this->assertStringContainsString(
            '(parentElement) => ({"x": n}), { "save": g, "pair": (a, b) => this.view.f(a, b) })',
            $js,
        );
        // stateKeys chỉ đến từ props
        $this->assertStringContainsString("'a.b', parentElement, [\"n\"],", $js);
    }

    /** Listener là closure của view cha — sang PHP là biến không tồn tại. */
    public function test_blade_khong_bao_gio_nhan_khoa_on(): void
    {
        $blade = (new SaolaCompiler())->compile(
            "@states({ n: 1 })\n<template>\n@include('a.b', {x: n, on\$save: g})\n</template>\n"
            . "<script setup>\nfunction g(a) {}\n</script>",
            new CompileOptions(viewPath: 'test.view', functionName: 'TestView', factoryName: 'TestViewFactory'),
        )->blade ?? '';

        $this->assertStringContainsString("@include('a.b', ['x'=> \$n])", $blade);
        $this->assertStringNotContainsString('on$', $blade);
    }

    /** Thẻ component và @include viết tay phải ra CÙNG một biểu diễn. */
    public function test_the_component_sinh_ra_dung_khoa_on(): void
    {
        $source = "@import('a.b' as child)\n@states({ n: 1 })\n"
            . "<template>\n<child :x=\"n\" @save(g) @pair((a, b) => f(a, b)) />\n</template>\n"
            . "<script setup>\nfunction f(a, b) {}\nfunction g(a) {}\n</script>";

        $result = (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.view',
            functionName: 'TestView',
            factoryName: 'TestViewFactory',
        ));

        $this->assertStringContainsString(
            '(parentElement) => ({"x": n}), { "save": g, "pair": (a, b) => this.view.f(a, b) })',
            $result->js ?? '',
        );
        $this->assertStringNotContainsString('on$', $result->blade ?? '');
    }
}
