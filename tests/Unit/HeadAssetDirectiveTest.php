<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `<link rel=stylesheet>` / `<script src>` khai báo trong .sao phải ra
 * `@addCssLink` / `@addScriptSrc` — ĐĂNG KÝ, không in thẻ tại chỗ.
 *
 * In tại chỗ là lỗi thật đã gặp: trang `@extends` echo phần ngoài block TRƯỚC
 * khi layout in `<!DOCTYPE html>`, doctype đứng sau nội dung nên trình duyệt bỏ
 * nó và cả trang chạy quirks mode (document.compatMode = "BackCompat").
 *
 * Bất biến kèm theo: href/src + attribute ở Blade phải KHỚP spec phía JS, vì
 * AssetManager của client tìm đúng bộ đó để adopt node SSR thay vì chèn bản thứ
 * hai. Hai đường cùng đọc RegisterParser nên test soát cả hai đầu ra.
 */
final class HeadAssetDirectiveTest extends TestCase
{
    private function compile(string $source): object
    {
        return (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.assets',
            functionName: 'AssetView',
            factoryName: 'AssetViewFactory',
        ));
    }

    public function test_link_va_script_thanh_directive_dang_ky(): void
    {
        $result = $this->compile(<<<'SAO'
        <template><div>x</div></template>

        <link rel="stylesheet" href="/static/app.css">
        <script src="https://cdn/prism.min.js" data-manual></script>
        SAO);

        $this->assertStringContainsString("@addCssLink('/static/app.css')", $result->blade);
        $this->assertStringContainsString("@addScriptSrc('https://cdn/prism.min.js', ['data-manual' => true])", $result->blade);
        // Không còn thẻ thô trong Blade — đó mới là thứ đẩy doctype xuống dưới.
        $this->assertStringNotContainsString('<link', $result->blade);
        $this->assertStringNotContainsString('<script src', $result->blade);
    }

    public function test_directive_dung_truoc_pageStart_cua_layout(): void
    {
        // Layout đăng ký SAU khi @pageStart in <head> thì css ra tận cuối body.
        $result = $this->compile(<<<'SAO'
        <template>
            @pageStart
            <div>@useBlock('shell')</div>
            @pageEnd
        </template>

        <link rel="stylesheet" href="/static/site.css">
        SAO);

        $this->assertLessThan(
            strpos($result->blade, '@pageStart'),
            strpos($result->blade, '@addCssLink'),
        );
    }

    public function test_url_noi_suy_blade_thanh_bieu_thuc_php(): void
    {
        $result = $this->compile(<<<'SAO'
        <template><div>x</div></template>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        SAO);

        $this->assertStringContainsString("@addCssLink((asset('css/app.css')))", $result->blade);
    }

    public function test_blade_va_js_dung_cung_url_va_attribute(): void
    {
        $result = $this->compile(<<<'SAO'
        <template><div>x</div></template>

        <link rel="stylesheet" id="theme" href="/t.css" media="print">
        SAO);

        // Client adopt theo href + id + attribute; lệch một chỗ là hydrate chèn
        // <link> thứ hai.
        $this->assertStringContainsString('"href":"/t.css"', $result->js);
        $this->assertStringContainsString('"id":"theme"', $result->js);
        $this->assertStringContainsString("@addCssLink('/t.css', ['media' => 'print', 'id' => 'theme'])", $result->blade);
    }

    public function test_khai_bao_trung_chi_sinh_mot_dong(): void
    {
        $result = $this->compile(<<<'SAO'
        <template><div>x</div></template>

        <link rel="stylesheet" href="/a.css">
        <link rel="stylesheet" href="/a.css">
        SAO);

        $this->assertSame(1, substr_count($result->blade, "@addCssLink('/a.css')"));
    }
}
