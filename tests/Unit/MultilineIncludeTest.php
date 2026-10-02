<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `@include` trải nhiều dòng, và `@include` nằm chung dòng với thẻ HTML.
 *
 * Cả hai emitter đọc theo DÒNG. `@include` không nằm trong CONTROL_DIRECTIVES
 * nên `Html::splitInlineDirectives` chưa từng chạm tới nó:
 *
 *   - xuống dòng → sao2js bỏ qua cả khối (không sinh `this.include` nào), còn
 *     sao2blade để lại một `@include('a.b', [` cụt.
 *   - inline `<p>@include('x')</p>` → server render đúng, client in ra nguyên
 *     văn chuỗi "@include('x')" — lệch SSR/CSR thấy được bằng mắt.
 *
 * Sửa nằm trong bộ chuẩn hoá DÙNG CHUNG nên hai đích không thể lệch nhau; bài
 * này canh đúng điều đó bằng cách so dãy marker của hai bên.
 */
final class MultilineIncludeTest extends TestCase
{
    /** @return array{string, string} [js, blade] */
    private function compile(string $template): array
    {
        $result = (new SaolaCompiler())->compile(
            "@states({ n: 0 })\n<template>\n{$template}\n</template>\n"
            . "<script setup>\nfunction f(a, b) {}\n</script>",
            new CompileOptions(viewPath: 'test.view', functionName: 'TestView', factoryName: 'TestViewFactory'),
        );

        return [$result->js ?? '', $result->blade ?? ''];
    }

    /** Dãy id component của hai bên phải khớp TUYỆT ĐỐI, kể cả thứ tự. */
    private function assertMarkerParity(string $js, string $blade): void
    {
        preg_match_all('/this\.include\(`([\w-]+)`/', $js, $jsIds);
        preg_match_all('/@startMarker\(\'component\', \'([\w-]+)\'\)/', $blade, $bladeIds);

        $this->assertNotEmpty($jsIds[1], 'sao2js không sinh this.include nào');
        $this->assertSame($bladeIds[1], $jsIds[1], 'dãy marker component lệch giữa SSR và CSR');
    }

    public function test_include_trai_nhieu_dong_van_sinh_ra_component(): void
    {
        [$js, $blade] = $this->compile(<<<'SAO'
            <div>
                @include('a.b', {
                    x: n,
                    y: 2
                })
            </div>
            SAO);

        $this->assertStringContainsString('(parentElement) => ({"x": n, "y": 2})', $js);
        // Khoảng trắng thừa từ chỗ xuống dòng đã gộp là vô hại; nội dung mới quan trọng.
        $this->assertMatchesRegularExpression(
            "/@include\('a\.b', \[\s*'x'=> \\\$n, 'y'=> 2\s*\]\)/",
            $blade,
        );
        $this->assertMarkerParity($js, $blade);
    }

    public function test_include_nam_chung_dong_voi_the_html(): void
    {
        [$js, $blade] = $this->compile("<div><p>@include('a.b', {x: n})</p></div>");

        $this->assertStringContainsString("'a.b', parentElement", $js);
        // Không được rơi lại thành text node mang nguyên văn directive
        $this->assertStringNotContainsString("this.text('@include", $js);
        $this->assertMarkerParity($js, $blade);
    }

    /** Listener xuống dòng — lý do chính khiến object data dài ra. */
    public function test_khoa_on_van_tach_dung_khi_include_xuong_dong(): void
    {
        [$js, $blade] = $this->compile(<<<'SAO'
            <div>
                @include('a.b', {
                    x: n,
                    on$pair: (a, b) => { f(a, b); f(b, a) }
                })
            </div>
            SAO);

        $this->assertStringContainsString(
            '(parentElement) => ({"x": n}), { "pair": (a, b) => { this.view.f(a, b); this.view.f(b, a) } })',
            $js,
        );
        $this->assertStringNotContainsString('on$', $blade);
        $this->assertMarkerParity($js, $blade);
    }

    /** Nội dung trong nháy giữ nguyên xuống dòng của nó. */
    public function test_khong_gop_xuong_dong_ben_trong_chuoi(): void
    {
        [$js] = $this->compile("<div>@include('a.b', {t: 'x\\ny'})</div>");

        $this->assertStringContainsString("\\ny", $js);
    }
}
