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

    /**
     * `$view` là biến hệ thống trỏ tới chính view — CHỈ có ở client.
     *
     * Thay cho biến `emit` mà compiler từng tự chèn vào phạm vi: đọc code không
     * thấy nó đến từ đâu, và view viết tay thì không có.
     */
    public function test_view_emit_chay_ca_trong_handler_lan_script_setup(): void
    {
        $js = $this->compile(
            "<button @click(\$view.emit('pick', n))>x</button>",
            "function go() { \$view.emit('go', 1); }",
        );

        $this->assertStringContainsString("[() => \$view.emit('pick', n)]", $js);
        $this->assertStringContainsString("function go() { \$view.emit('go', 1); }", $js);
    }

    /** Object literal LỒNG trong đối số phải thành object JS, không phải mảng PHP. */
    public function test_object_literal_long_trong_doi_so_thanh_object_js(): void
    {
        $js = $this->compile("<button @click(\$view.emit('tag', {id: n, k: 'v'}))>x</button>");

        $this->assertStringContainsString('{"id": n, "k": "v"}', $js);
        $this->assertStringNotContainsString("'id'=>", $js);
    }

    /**
     * `emit` là method CÓ SẴN của View, không phải biến ma thuật — nên trong
     * template nó phân giải y hệt method người dùng viết, không cần `$view.`.
     *
     * Ba vị trí, ba đường phân giải khác nhau, đều phải ra được `View.emit`:
     * handler theo tên, thân arrow, và biểu thức của listener.
     */
    public function test_emit_tran_phan_giai_duoc_o_moi_vi_tri(): void
    {
        // 1. Handler theo tên — runtime tra `view['emit']`
        $this->assertSame('[{"handler":"emit","params":[\'x\']}]', $this->eventConfig("emit('x')"));

        // 2. Thân arrow người dùng viết
        $this->assertSame("[() => this.view.emit('x')]", $this->eventConfig("() => emit('x')"));

        // 3. Biểu thức của listener ở thẻ component
        $js = $this->compile("@include('a.b', {on\$re: emit('other')})");
        $this->assertStringContainsString('{ "re": () => this.view.emit(\'other\') }', $js);
    }

    /** Chuỗi chứa `emit(` là DỮ LIỆU, không phải lời gọi. */
    public function test_khong_dung_vao_emit_trong_chuoi(): void
    {
        $this->assertSame("[() => this.view.g('emit(')]", $this->eventConfig("() => g('emit(')"));
    }

    /**
     * `emit(...)` trần TRONG `<script setup>` không bắt được lúc biên dịch —
     * nội dung setup đi qua nguyên văn, không qua trình dịch biểu thức. Quét
     * văn bản để bắt sẽ báo nhầm cả comment lẫn `socket.emit(`, nên để nguyên:
     * runtime ném ReferenceError có kèm đúng tên, không phải hỏng câm.
     */
    /**
     * Trong `<script setup>` thì KHÁC: nội dung đó đi qua nguyên văn, không
     * qua trình dịch biểu thức, nên `emit(...)` trần không được phân giải và
     * nổ ReferenceError lúc chạy. Ở đó phải viết `$view.emit(...)`.
     */
    public function test_emit_tran_trong_script_setup_di_qua_nguyen_van(): void
    {
        $js = $this->compile('<button @click(go())>a</button>', "function go() { emit('x'); }");

        $this->assertStringContainsString("function go() { emit('x'); }", $js);
    }

    /** `$view` không tồn tại lúc Blade render — bắt lúc biên dịch, không để ra trang trắng. */
    public function test_view_o_vi_tri_ssr_bao_loi(): void
    {
        $this->expectExceptionMessageMatches('/chỉ dùng được ở phía client/');
        $this->compile('<p>{{ $view.path }}</p>');
    }

    /**
     * `@edit($view.emit)` — CHUYỂN TIẾP lên tầng trên, giữ nguyên tên sự kiện.
     *
     * `emit` chỉ nhảy MỘT tầng, nên cháu muốn tới ông thì tầng giữa phải phát
     * lại. Dạng này là bản gọn của `(...args) => $view.emit('edit', ...args)`,
     * và `...args` giữ nguyên số đối số lẫn GIÁ TRỊ TRẢ VỀ — nhờ đó
     * `if ($view.emit('confirm', id) === false)` vẫn đúng qua nhiều tầng.
     */
    public function test_view_emit_tran_o_the_component_la_chuyen_tiep(): void
    {
        $source = "@import('a.b' as child)\n@states({ n: 1 })\n"
            . "<template>\n<child :x=\"n\" @edit(\$view.emit) "
            . "@on('row:changed', \$view.emit) />\n</template>";

        $js = (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.view',
            functionName: 'TestView',
            factoryName: 'TestViewFactory',
        ))->js ?? '';

        $this->assertStringContainsString(
            '{ "edit": (...args) => $view.emit(\'edit\', ...args), '
            . '"row:changed": (...args) => $view.emit(\'row:changed\', ...args) }',
            $js,
        );
    }

    public function test_chuyen_tiep_dung_duoc_ca_voi_khoa_on(): void
    {
        $js = $this->compile("@include('a.b', {x: n, on\$save: \$view.emit})");

        $this->assertStringContainsString('{ "save": (...args) => $view.emit(\'save\', ...args) }', $js);
    }

    /** Trên element thường thì không có "tầng trên" nào để chuyển tiếp. */
    public function test_chuyen_tiep_tren_element_bao_loi(): void
    {
        $this->expectExceptionMessageMatches('/chỉ dùng được cho sự kiện của THẺ COMPONENT/');
        $this->compile('<button @click($view.emit)>x</button>');
    }

    /**
     * `$view` phải giữ nguyên đúng MỘT '$' ở MỌI directive.
     *
     * Số '$' đi vào EventDirectiveProcessor không ổn định: preprocessor thêm
     * một cái nữa cho đối số của directive nó BIẾT, còn `@dragstart(...)`
     * (không có trong EVENT_DIRECTIVES) và `@submit.prevent(...)` (regex
     * `@submit\s*\(` trượt vì modifier) thì giữ nguyên một cái. Bóc '$' mù
     * theo số lượng làm hai dạng đó ra `view.emit(...)` — ReferenceError lúc
     * bấm, mà CHỈ ở đúng những directive đó nên rất dễ lọt.
     */
    #[DataProvider('directiveGiuNguyenDollarView')]
    public function test_view_giu_nguyen_dollar_o_moi_directive(string $directive): void
    {
        $js = $this->compile("<b @{$directive}(\$view.emit('x'))>y</b>");

        $this->assertStringContainsString("\$view.emit('x')", $js);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.$])view\.emit/', $js);
    }

    /** @return iterable<string, array{string}> */
    public static function directiveGiuNguyenDollarView(): iterable
    {
        yield 'trong danh sách event' => ['click'];
        yield 'NGOÀI danh sách event' => ['dragstart'];
        yield 'có modifier' => ['submit.prevent'];
        yield 'ngoài danh sách + modifier' => ['animationend.once'];
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
