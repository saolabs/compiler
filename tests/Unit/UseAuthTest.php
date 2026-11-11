<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\{CompileException, CompileOptions, SaolaCompiler};
use Saola\Compiler\Hydration\MarkerParity;

final class UseAuthTest extends TestCase
{
    public function test_all_aliases_inside_and_outside_setup_emit_shared_state_and_one_subscription(): void
    {
        foreach (['@useAuth', '@useAuth()', '@useauth', '@useauth()'] as $directive) {
            foreach ([false, true] as $setup) {
                $source = ($setup ? "<script setup>\n$directive\nfunction clear(){set__auth__(null);}\n</script>" : $directive)
                    ."\n<template>@if(__auth__)<b>{{ __auth__.name }}</b>@else<i>Guest</i>@endif</template>";
                $r = (new SaolaCompiler())->compile($source, new CompileOptions(viewPath: 'test.use-auth'));
                self::assertSame('js', $r->lang);
                self::assertStringContainsString('@useState($__auth__, (', $r->blade);
                self::assertStringContainsString('\\Saola\\Core\\Services\\ClientAuth::class', $r->blade);
                self::assertStringContainsString('@if($__auth__)', $r->blade);
                self::assertStringContainsString('const set__auth__ =', $r->js);
                self::assertSame(1, substr_count($r->js, 'App.Auth.subscribe('));
                self::assertStringContainsString('$controller.scope.defer(App.Auth.subscribe(() => set__auth__(App.Auth.user())))', $r->js);
                self::assertStringNotContainsString("subscribe('@auth'", $r->js);
                self::assertStringNotContainsString('@useAuth', $r->blade);
                self::assertStringNotContainsString('@useauth', $r->js);
                self::assertSame(['blade' => [], 'js' => []], MarkerParity::diff($r->blade, $r->js));
            }
        }
    }

    public function test_comments_strings_and_verbatim_examples_do_not_opt_a_view_into_auth(): void
    {
        $r = (new SaolaCompiler())->compile(<<<'SAO'
{{-- @useAuth --}}
<script setup>
// @useauth()
const example = '@useAuth';
const pattern = /@useAuth/;
</script>
<template><p>Example</p>@verbatim @useAuth @endverbatim</template>
SAO, new CompileOptions(viewPath: 'test.auth-examples'));
        self::assertStringNotContainsString('App.Auth.subscribe(', $r->js);
        self::assertStringNotContainsString('const set__auth__', $r->js);
    }

    public function test_rejects_duplicate_reserved_state_arguments_and_nested_setup_flags(): void
    {
        foreach (["@useAuth\n@useauth()", "@useAuth\n@state(__auth__ = null)",
            "@useAuth\n@vars(__auth__ = null)", "@useAuth('admin')", '<script setup>function f(){@useAuth}</script>'] as $source) {
            try {
                (new SaolaCompiler())->compile($source.'<template><p>Test</p></template>', new CompileOptions(viewPath: 'test.invalid-auth'));
                self::fail('Expected invalid @useAuth to fail: '.$source);
            } catch (CompileException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }
}
