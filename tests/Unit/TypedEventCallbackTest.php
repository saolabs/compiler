<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Lang;
use Saola\Compiler\SaolaCompiler;

final class TypedEventCallbackTest extends TestCase
{
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
