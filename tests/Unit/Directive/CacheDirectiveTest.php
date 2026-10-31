<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/** @cache(...) ghi config `cache` của view cho PageCache; không lọt vào cây render hay Blade. */
final class CacheDirectiveTest extends TestCase
{
    private function compile(string $source): \Saola\Compiler\CompileResult
    {
        return (new SaolaCompiler())->compile($source, new CompileOptions(viewPath: 'test.form', functionName: 'TestForm', factoryName: 'TestFormFactory'));
    }

    public function test_cache_false_ngoai_template_vao_view_config(): void
    {
        $result = $this->compile("@vars(name = '')\n@await\n@cache(false)\n<template><p>{{ name }}</p></template>\n");

        $this->assertMatchesRegularExpression('/prerenderSections: \[[^\]]*\],\s*cache: false/', $result->js);
        $this->assertStringNotContainsString('@cache', $result->js);
        $this->assertStringNotContainsString("'@cache", $result->js);
    }

    public function test_cache_ttl_giu_nguyen_object(): void
    {
        $result = $this->compile("@cache({ ttl: 60000 })\n<template><p>x</p></template>\n");

        $this->assertStringContainsString('cache: { ttl: 60000 }', $result->js);
    }

    public function test_khong_khai_bao_thi_khong_co_khoa_cache(): void
    {
        $this->assertStringNotContainsString('cache:', $this->compile("<template><p>x</p></template>\n")->js);
    }

    public function test_gia_tri_la_bi_bao_loi(): void
    {
        $this->expectException(CompileException::class);
        $this->compile("@cache(maybe)\n<template><p>x</p></template>\n");
    }
}
