<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * Biến hệ thống (`__site`, `__layout__`…) là hằng của view, hợp đồng với core
 * `ViewContextManager::RESERVED`: đọc trần được ở mọi view, không khai báo hay gán.
 */
final class SystemNamesTest extends TestCase
{
    private static function compile(string $source): object
    {
        return (new SaolaCompiler())->compile($source, CompileOptions::fromArray([
            'view-path' => 'web.probe',
            'fn' => 'Probe',
            'factory' => 'Probe',
        ]));
    }

    /** @return iterable<string, array{string}> */
    public static function khaiBaoBiChan(): iterable
    {
        yield 'let trong script' => ["<script setup>\nlet __site = {};\n</script>\n<p>x</p>"];
        yield 'const trong script' => ["<script setup>\nconst __layout__ = 'x';\n</script>\n<p>x</p>"];
        yield '@vars' => ["<script setup>\n@vars(a: any = 1, __site: any = {})\n</script>\n<p>x</p>"];
        yield '@state' => ["<script setup>\n@state(__site: any = {})\n</script>\n<p>x</p>"];
        yield 'gán trần' => ["<script setup>\nfunction f(){ __site = {}; }\n</script>\n<p>x</p>"];
        yield 'gán ghép' => ["<script setup>\nfunction f(){ __context__ += 'x'; }\n</script>\n<p>x</p>"];
        yield 'biến vòng lặp' => ["<script setup>\n@vars(items: any[] = [])\n</script>\n@foreach(items as __site)<p>x</p>@endforeach"];
    }

    #[DataProvider('khaiBaoBiChan')]
    public function testKhaiBaoTenHeThongBaoLoi(string $source): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/biến hệ thống/');
        self::compile($source);
    }

    public function testDocTranOCaHaiPhia(): void
    {
        $result = self::compile(<<<'SAO'
<script setup lang="ts">
    @vars(items: any[] = [])
    const logo = __site.branding?.logo_url || '/x.svg';
    const same = __site == null;
</script>
<p>{{ __site.general.website_name }}</p>
<img src="{{ __site['branding']['logo_url'] }}" alt="">
@foreach(items as item)<span>{{ item }} {{ __site.general.website_name }}</span>@endforeach
SAO);
        $this->assertStringContainsString('__helper = {}, __site = {}} = systemData;', $result->js);
        $this->assertStringContainsString('__site.general.website_name', $result->js);
        $this->assertStringContainsString('$__site->general->website_name', $result->blade);
        // Thân vòng lặp chỉ đọc item + hằng hệ thống ⇒ vẫn được memo (mức ≠ 0).
        $this->assertDoesNotMatchRegularExpression('/__foreach\([^\n]*, 0\)/', $result->js);
    }

    public function testVanBanNguyenVanVaChuoiKhongBiBat(): void
    {
        self::compile("@verbatim\n<pre>let __site = {}</pre>\n@endverbatim\n<p>x</p>");
        self::compile("{{-- __site = 1 --}}<p>x</p>");
        self::compile("<script setup>\nconst code = 'let __site = 1';\n</script>\n<p>x</p>");
        self::compile("<p>{{ a == __layout__ ? 1 : 2 }}</p>");
        $this->addToAssertionCount(1);
    }
}
