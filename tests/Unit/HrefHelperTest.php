<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Lang;
use Saola\Compiler\SaolaCompiler;

/** href() (docs/CONTEXT_DOMAINS_AND_HREF_PLAN_2026-11-18.md H5): core href() at SSR, App.Router.href at CSR. */
final class HrefHelperTest extends TestCase
{
    public function test_href_compiles_to_the_router_in_js_and_stays_a_php_call_in_blade(): void
    {
        $source = <<<'SAO'
<script setup>
state id = 7;
</script>
<template>
<a href="{{ href('study.show', {slug: 'a'}) }}">A</a>
<a :href="href('app.home.item', {id: id}, {absolute: true})">B</a>
<span>{{ href('home.index') }}</span>
<button @click(App.Router.go('home.index'))>Go</button>
</template>
SAO;
        $output = (new SaolaCompiler())->compile($source, new CompileOptions(lang: Lang::Ts));

        self::assertStringContainsString("App.Router.href('study.show'", $output->js);
        self::assertStringContainsString("App.Router.href('app.home.item'", $output->js);
        self::assertStringContainsString("App.Router.href('home.index')", $output->js);
        self::assertStringNotContainsString('App.Helper.href', $output->js);
        self::assertStringNotContainsString('this.view.href', $output->js);
        self::assertStringContainsString("href('study.show'", $output->blade);
        self::assertStringContainsString("href('home.index')", $output->blade);
        self::assertStringNotContainsString('$href', $output->blade);
        self::assertStringNotContainsString('App.Router', $output->blade);
    }
}
