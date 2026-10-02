<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

final class TypedComputedTest extends TestCase
{
    public function test_computed_cycles_are_reported_before_emitting_ssr(): void
    {
        $this->expectException(\Saola\Compiler\CompileException::class);
        $this->expectExceptionMessage('Computed dependency cycle');
        (new SaolaCompiler())->compile('@computed(a = b + 1, b = a + 1)<template>{{ a }}</template>', new CompileOptions(viewPath: 'test.cycle'));
    }

    public function test_compile_file_writes_the_actual_typescript_extension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sao-typed-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, '@state(n: number = 1)<template>{{ n }}</template>');
            $r = (new SaolaCompiler())->compileFile($path, new CompileOptions(viewPath: 'test.typed', jsOutputPath: $path.'.js'));
            self::assertSame('ts', $r->lang);
            self::assertFileExists($path.'.ts');
            self::assertFileDoesNotExist($path.'.js');
        } finally {
            unlink($path);
            if (is_file($path.'.ts')) unlink($path.'.ts');
        }
    }

    public function test_optional_annotations_are_erased_for_ssr_and_preserved_for_typescript(): void
    {
        $source = <<<'SAO'
@props({price: 5, label: 'total'}: {price: number; label: string})
@state(qty: number = 2, active: boolean = true)
@vars(items: Array<{id: number; name: string}> = [])
@let(offset: number = 1, suffix: string = '!')
@const(factor: number = 2, caption: string = 'sum')
@computed(total: number = price * qty + offset)
<template><div>{{ total }} {{ suffix }} {{ caption }}</div></template>
SAO;
        $r = (new SaolaCompiler())->compile($source, new CompileOptions(viewPath: 'test.typed'));
        self::assertSame('ts', $r->lang);
        self::assertStringContainsString('let qty: number = 2', $r->js);
        self::assertStringContainsString('const setQty = (state: number)', $r->js);
        self::assertStringContainsString('let offset: number = 1', $r->js);
        self::assertStringContainsString('const caption: string =', $r->js);
        self::assertStringContainsString('price?: number', $r->js);
        self::assertStringContainsString('items?: Array<{id: number; name: string}>', $r->js);
        self::assertStringContainsString("computed('total', (): number =>", $r->js);
        self::assertStringContainsString('get$total()', $r->js);
        self::assertStringNotContainsString('let total', $r->js);
        self::assertStringContainsString('@php($total = $price * $qty + $offset)', $r->blade);
        self::assertStringContainsString('{{ $total }}', $r->blade);
        self::assertStringNotContainsString(': number', $r->blade);
        self::assertStringNotContainsString('@computed', $r->blade);
    }

    public function test_untyped_computed_remains_javascript_and_uses_lazy_getters_in_chains(): void
    {
        $r = (new SaolaCompiler())->compile(<<<'SAO'
@state(count = 2)
@computed(double = count * 2, quad = double * 2)
<template><span>{{ quad }}</span></template>
SAO, new CompileOptions(viewPath: 'test.computed'));
        self::assertSame('js', $r->lang);
        self::assertStringContainsString("computed('quad', () => get\$double() * 2", $r->js);
        self::assertStringContainsString('@php($quad = $double * 2)', $r->blade);
    }

    public function test_nested_types_and_parentheses_inside_strings(): void
    {
        $r = (new SaolaCompiler())->compile(<<<'SAO'
@vars(map: Record<string, Array<number>> = {}, text: string = ')')
@let(compare: boolean = 1 < 2, next: number = 3)
<template><span>{{ text }}</span></template>
SAO, new CompileOptions(viewPath: 'test.nested'));
        self::assertStringContainsString('Record<string, Array<number>>', $r->js);
        self::assertStringContainsString('let next: number = 3', $r->js);
        self::assertStringContainsString("text?: string", $r->js);
    }
}
