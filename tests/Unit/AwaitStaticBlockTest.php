<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `@await` chia view làm hai: phần TĨNH (không đụng biến await) vào `prerender()`,
 * phần phụ thuộc dữ liệu vào `render()`. Bất biến bắt buộc: nội dung nào tới được
 * Blade thì cũng phải tới được JS — nếu không, SSR hiện mà CSR mất.
 *
 * Test hand-written phía client (tests/view/prerender-layout.test.ts) tự viết ra
 * `this.block('b-footer', ...)` trong prerender rồi assert nó có mặt, nên nó xanh
 * bất kể compiler sinh gì. Chỗ duy nhất bắt được lệch là chạy compiler thật.
 */
final class AwaitStaticBlockTest extends TestCase
{
    private const SOURCE = <<<'SAO'
@vars(user = null)
@await
@extends('layout')
@block('content')
    <p>{{ user.name }}</p>
@endblock
@block('footer')
    <footer>Copyright 2026</footer>
@endblock
@section('sidebar', 'posts')
SAO;

    private function compile(string $source): object
    {
        return (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.await',
            functionName: 'AwaitView',
            factoryName: 'AwaitViewFactory',
        ));
    }

    public function test_block_tinh_khong_bien_mat_khoi_js_khi_co_await(): void
    {
        $result = $this->compile(self::SOURCE);

        // SSR có footer — đây là mốc so sánh, không phải thứ đang nghi ngờ.
        $this->assertStringContainsString('Copyright 2026', (string) $result->blade);

        $this->assertStringContainsString(
            "this.block('block-footer'",
            (string) $result->js,
            '@block tĩnh trên trang @await phải được emit là block, không phải section rỗng',
        );
        $this->assertStringContainsString(
            'Copyright 2026',
            (string) $result->js,
            'Nội dung @block tĩnh bị đánh rơi khỏi JS — SSR hiện footer, CSR mất',
        );
    }

    public function test_gia_tri_section_ngan_khong_bi_rong_hoa_khi_co_await(): void
    {
        $result = $this->compile(self::SOURCE);

        $this->assertStringContainsString(
            "'posts'",
            (string) $result->js,
            "@section('sidebar', 'posts') bị emit thành () => '' khi có @await",
        );
    }

    public function test_khong_co_await_thi_van_dung___moc_doi_chieu(): void
    {
        $result = $this->compile(str_replace("@await\n", '', self::SOURCE));

        $this->assertStringContainsString("this.block('block-footer'", (string) $result->js);
        $this->assertStringContainsString('Copyright 2026', (string) $result->js);
        $this->assertStringContainsString("'posts'", (string) $result->js);
    }
}
