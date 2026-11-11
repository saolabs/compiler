<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\{CompileException, CompileOptions, Lang, SaolaCompiler};
use Saola\Compiler\Hydration\MarkerParity;

final class MiddlewareTest extends TestCase
{
    public function test_static_aliases_in_both_declaration_locations_emit_metadata_without_changing_ssr(): void
    {
        foreach (["@middleware('auth', 'role:admin,editor')", '@middleware (["auth", "role:admin,editor",])'] as $directive) {
            foreach ([false, true] as $setup) {
                foreach ([Lang::Js, Lang::Ts] as $lang) {
                    $source = ($setup ? "<script setup>\n$directive\n</script>" : $directive)
                        ."\n<template><p>Protected</p></template>";
                    $r = (new SaolaCompiler())->compile($source, new CompileOptions(viewPath:'test.protected', factoryName:'ProtectedFactory', lang:$lang));
                    self::assertStringContainsString('export const __SAOLA_MIDDLEWARE__ = ["auth","role:admin,editor"];', $r->js);
                    self::assertStringContainsString('Object.assign(ProtectedFactory, {middleware: __SAOLA_MIDDLEWARE__})', $r->js);
                    self::assertStringNotContainsString('@middleware', $r->blade);
                    self::assertStringNotContainsString('@middleware', $r->js);
                    self::assertSame(['blade'=>[], 'js'=>[]], MarkerParity::diff($r->blade, $r->js));
                }
            }
        }
    }

    public function test_repeated_declarations_preserve_order_and_do_not_change_a_view_without_middleware(): void
    {
        $compiler = new SaolaCompiler();
        $r = $compiler->compile("@middleware('auth')\n@middleware(['profile:complete'])<template><p>Hello</p></template>", new CompileOptions());
        self::assertStringContainsString('["auth","profile:complete"]', $r->js);
        $plain = $compiler->compile('<template><p>Hello</p></template>', new CompileOptions());
        self::assertStringNotContainsString('__SAOLA_MIDDLEWARE__', $plain->js);
    }

    public function test_rejects_dynamic_empty_nested_or_unclosed_policies(): void
    {
        foreach (["@middleware()", "@middleware([])", "@middleware(auth)", "@middleware(auth())", "@middleware(\$name)",
            "@middleware('bad alias')", "@middleware('auth'", '@middleware',
            "<template>@middleware('auth')<p>Hello</p></template>",
            "<script setup>function f(){@middleware('auth')}</script>"] as $source) {
            try {
                (new SaolaCompiler())->compile($source, new CompileOptions(viewPath:'test.invalid-policy'));
                self::fail('Expected compile error: '.$source);
            } catch (CompileException $error) {
                self::assertStringContainsString('middleware', $error->getMessage());
                self::assertStringContainsString('test.invalid-policy', $error->getMessage());
            }
        }
    }

    public function test_ignores_comments_strings_styles_and_verbatim_examples(): void
    {
        $r = (new SaolaCompiler())->compile(<<<'SAO'
{{-- @middleware('auth') --}}
<script setup>
// @middleware('auth')
const example = "@middleware('auth')";
</script>
<style>/* @middleware('auth') */</style>
<template><p>Hello</p>@verbatim @middleware('auth') @endverbatim</template>
SAO, new CompileOptions());
        self::assertStringNotContainsString('__SAOLA_MIDDLEWARE__', $r->js);
    }
}
