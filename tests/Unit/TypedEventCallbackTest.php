<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Lang;
use Saola\Compiler\SaolaCompiler;

final class TypedEventCallbackTest extends TestCase
{
    public function test_nested_named_routes_accept_the_laravel_absolute_flag(): void
    {
        $source = "<template><a href=\"{{ route('login', {next: route('agents', {}, false)}, false) }}\">Login</a></template>";
        $output = (new SaolaCompiler())->compile($source, new CompileOptions(lang: Lang::Ts));
        self::assertStringContainsString("App.Router.getURL('agents', [])", $output->js);
        self::assertStringNotContainsString(', false)', $output->js);
        self::assertStringContainsString('false', $output->blade);
    }

    public function test_nested_event_objects_preserve_script_scope_and_do_not_leak_to_render(): void
    {
        $source = <<<'SAO'
<script setup>
function localValue(event) { return event.target.value; }
function label() { return 'Label'; }
</script>
<template>
<input @change(App.Web.patch({value:localValue(event),nested:[window.clean(label()),App.Web.read()]}))>
<span>{{ label() }}</span>
</template>
SAO;
        $output = (new SaolaCompiler())->compile($source, new CompileOptions(lang: Lang::Ts))->js;
        self::assertStringNotContainsString('App.Helper.localValue', $output);
        self::assertStringContainsString('window.clean(', $output);
        self::assertStringContainsString('App.Web.read()', $output);
        self::assertStringContainsString('App.Helper.label()', $output);
    }

    public function test_arrow_listeners_calling_state_setters_do_not_warn(): void
    {
        $source = <<<'SAO'
@import('fixtures.emit-child' as emitchild)
@states({ picked: 0 })
<template><div>
<emitchild @pair((a, b) => setPicked(a + b)) />
@include('fixtures.emit-child', { on$close: (v) => setPicked(v + 1), on$open: (v) => unknownFn(v) })
</div></template>
<script setup>function takeOne(v) { setPicked(v); }</script>
SAO;
        $warnings = implode("\n", (new SaolaCompiler())->compile($source, new CompileOptions(lang: Lang::Js))->warnings);
        self::assertStringNotContainsString('setPicked', $warnings);
        self::assertStringContainsString('unknownFn', $warnings);
    }

    public function test_nested_event_parameters_are_typed_only_in_typescript_output(): void
    {
        $source = <<<'SAO'
<template>
<form @submit(save(event))>
    <input @change(changed(event.target.value))>
    <button @click(log('(event) => literal'))>Save</button>
</form>
</template>
<script setup>
export default { save(event) {}, changed(value) {}, log(value) {} }
</script>
SAO;
        $compiler = new SaolaCompiler();
        $js = $compiler->compile($source, new CompileOptions(lang: Lang::Js));
        $ts = $compiler->compile($source, new CompileOptions(lang: Lang::Ts));
        self::assertStringContainsString('"params":[(event: any) => event]', $ts->js);
        self::assertStringContainsString('(event: any) => event.target.value', $ts->js);
        self::assertStringContainsString('(event) => literal', $ts->js);
        self::assertStringNotContainsString('(event: any) => literal', $ts->js);
        self::assertStringContainsString('"params":[(event) => event]', $js->js);
        self::assertStringNotContainsString('event: any', $js->js);
        self::assertSame($js->blade, $ts->blade);
    }
}
