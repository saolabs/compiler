<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Lang;
use Saola\Compiler\SaolaCompiler;
use Saola\Compiler\Target;

/**
 * Các nhánh CHÍNH SÁCH của API công khai.
 *
 * Cổng parity không chạm tới được: bản Python không có khái niệm sandbox,
 * `Target`, hay kiểm tra tham số — nên không có gì để đối chiếu. Đây đúng là
 * vùng dành cho unit test (docs/06-coding-standards.md §6).
 */
final class SaolaCompilerTest extends TestCase
{
    private const SOURCE = "@states({ n: 0 })\n<template><div>{{ n }}</div></template>\n";

    private function options(array $overrides = []): CompileOptions
    {
        return new CompileOptions(...[
            'viewPath' => 'test.view',
            'functionName' => 'TestView',
            'factoryName' => 'TestViewFactory',
            ...$overrides,
        ]);
    }

    public function test_sinh_ca_blade_lan_js_theo_mac_dinh(): void
    {
        $result = (new SaolaCompiler())->compile(self::SOURCE, $this->options());

        $this->assertNotNull($result->blade);
        $this->assertNotNull($result->js);
        $this->assertStringContainsString('@startMarker', $result->blade);
        $this->assertStringContainsString('TestViewFactory', $result->js);
    }

    /**
     * Target chỉ lọc field TRẢ VỀ, không đổi cách biên dịch.
     *
     * Cả hai đích luôn được sinh để đi qua CÙNG một cấu hình marker — đó là
     * thứ giữ cho id SSR và CSR khớp nhau.
     */
    public function test_target_chi_loc_field_tra_ve(): void
    {
        $compiler = new SaolaCompiler();

        $both = $compiler->compile(self::SOURCE, $this->options());
        $bladeOnly = $compiler->compile(self::SOURCE, $this->options(['emit' => Target::BladeOnly]));
        $jsOnly = $compiler->compile(self::SOURCE, $this->options(['emit' => Target::JsOnly]));

        $this->assertNull($bladeOnly->js);
        $this->assertNull($jsOnly->blade);
        $this->assertSame($both->blade, $bladeOnly->blade, 'Blade phải giống hệt dù Target khác');
        $this->assertSame($both->js, $jsOnly->js, 'JS phải giống hệt dù Target khác');
    }

    public function test_compile_khong_giu_trang_thai_giua_cac_lan_goi(): void
    {
        $compiler = new SaolaCompiler();
        $a = "@states({ x: 1 })\n<template><div>{{ x }}</div></template>\n";
        $b = "@states({ y: 2 })\n<template><div>{{ y }}</div></template>\n";

        $first = $compiler->compile($a, $this->options());
        $compiler->compile($b, $this->options(['viewPath' => 'other.view']));
        $again = $compiler->compile($a, $this->options());

        // Rò trạng thái là lớp bug đã xảy ra thật ở bản Python (FIX(F3)).
        $this->assertSame($first->js, $again->js);
        $this->assertSame($first->blade, $again->blade);
    }

    #[DataProvider('thamSoRong')]
    public function test_tu_choi_tham_so_rong(array $overrides): void
    {
        $this->expectException(CompileException::class);
        (new SaolaCompiler())->compile(self::SOURCE, $this->options($overrides));
    }

    public static function thamSoRong(): array
    {
        return [
            'viewPath rỗng' => [['viewPath' => '']],
            'functionName rỗng' => [['functionName' => '']],
            'factoryName rỗng' => [['factoryName' => '']],
        ];
    }

    public function test_idMode_khong_hop_le_bi_tu_choi(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/idMode/');
        (new SaolaCompiler())->compile(self::SOURCE, $this->options(['idMode' => 'khong-ton-tai']));
    }

    public function test_vuot_maxFileBytes_bi_tu_choi(): void
    {
        $this->expectException(CompileException::class);
        (new SaolaCompiler())->compile(self::SOURCE, $this->options(['maxFileBytes' => 10]));
    }

    // ── Sandbox ───────────────────────────────────────────────────────

    #[DataProvider('nguonBiSandboxChan')]
    public function test_sandbox_chan_nguon_nguy_hiem(string $source): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/Sandbox/');
        (new SaolaCompiler())->compile($source, $this->options(['sandbox' => true]));
    }

    public static function nguonBiSandboxChan(): array
    {
        return [
            '@php' => ["<template>@php echo 1; @endphp</template>"],
            '@exec' => ["<template>@exec(1)</template>"],
            'hàm hệ thống' => ["<template>{{ system('ls') }}</template>"],
            'đọc file' => ["<template>{{ file_get_contents('/etc/passwd') }}</template>"],
            '@import vượt thư mục' => ["@import('../ngoai/theme')\n<template><div></div></template>"],
        ];
    }

    public function test_sandbox_cho_qua_nguon_lanh(): void
    {
        $result = (new SaolaCompiler())->compile(self::SOURCE, $this->options(['sandbox' => true]));

        $this->assertNotNull($result->js);
    }

    public function test_sandbox_khong_ho_tro_typescript(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/TypeScript/');
        (new SaolaCompiler())->compile(self::SOURCE, $this->options(['sandbox' => true, 'lang' => Lang::Ts]));
    }

    // ── Kênh cảnh báo (docs/05-roadmap.md §12) ────────────────────────

    /**
     * Trước đây `warnings` bị hardcode `[]` nên MỌI cảnh báo đều rơi vào hư
     * không. Test này giữ đường ống thông: gãy chỗ nào cũng thành mảng rỗng,
     * và mảng rỗng thì im lặng — đúng kiểu hỏng không ai nhận ra.
     */
    public function test_canh_bao_sao2js_ten_ham_la_den_duoc_ket_qua(): void
    {
        $source = "<template><p>{{ hamHoanToanLa(1) }}</p></template>";

        $result = (new SaolaCompiler())->compile($source, $this->options());

        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('hamHoanToanLa', implode("\n", $result->warnings));
    }

    /** Gom từ HAI nguồn: [sao2js] lúc sinh JS và [sao2blade] soát output Blade. */
    public function test_canh_bao_gom_du_ca_hai_nguon(): void
    {
        $source = "<template><p>{{ hamHoanToanLa(1) }}</p><p>{{ String(1) }}</p></template>";

        $all = implode("\n", (new SaolaCompiler())->compile($source, $this->options())->warnings);

        $this->assertStringContainsString('[sao2js]', $all);
        $this->assertStringContainsString('[sao2blade]', $all);
    }

    /** Method khai trong <script setup> KHÔNG phải hàm lạ — báo là báo sai. */
    public function test_method_cua_script_setup_khong_bi_canh_bao(): void
    {
        $source = "<template><p>{{ coThat(1) }}</p></template>\n"
            . "<script setup>\nexport default { coThat(v) { return v; } }\n</script>";

        $result = (new SaolaCompiler())->compile($source, $this->options());

        $this->assertSame([], $result->warnings);
    }

    /** Arrow viết tay vẫn phải phân giải method component qua `this.view`. */
    public function test_event_arrow_phan_giai_method_cua_script_setup(): void
    {
        $source = "<template><button @click(() => pickItem(item.name))>pick</button></template>\n"
            . "<script setup>\nexport default { pickItem(name) { return name; } }\n</script>";

        $result = (new SaolaCompiler())->compile($source, $this->options());

        $this->assertNotNull($result->js);
        $this->assertStringContainsString('() => this.view.pickItem(item.name)', $result->js);
        $this->assertStringNotContainsString('this.view.this.view.pickItem', $result->js);
        $this->assertSame([], $result->warnings);
    }

    public function test_nguon_lanh_khong_sinh_canh_bao(): void
    {
        $result = (new SaolaCompiler())->compile(self::SOURCE, $this->options());

        $this->assertSame([], $result->warnings);
    }
}
