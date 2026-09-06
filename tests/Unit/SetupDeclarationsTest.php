<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;
use Saola\Compiler\Source\SourceSplitter;

final class SetupDeclarationsTest extends TestCase
{
    public function test_inside_and_outside_declarations_compile_to_the_same_targets(): void
    {
        $declarations = <<<'SAO'
@props({price: 5}: {price: number})
@vars(rows: Array<{id: number; active: boolean}> = [])
@state(qty: number = 2)
@states({enabled: true}: {enabled: boolean})
@let(offset: number = 1, cardPath: string = __component__ + 'statcard')
@const(factor: number = 2)
@computed(total: number = price * qty + offset)
@asset(logo = 'images/logo.svg')
@assets({icon: 'images/icon.svg'})
@import(cardPath as Card)
SAO;
        $template = "<template>\n<img src=\"{{ logo }}\"><img src=\"{{ icon }}\"><span>{{ total }}</span>\n<Card />\n</template>";
        $script = "export default { increment() { setQty(qty + 1); } };";
        $compiler = new SaolaCompiler();
        $options = new CompileOptions(viewPath: 'test.setup');
        $outside = $compiler->compile($declarations."\n".$template."\n<script setup>\n".$script."\n</script>", $options);
        $inside = $compiler->compile("<script setup>\n".str_replace('@import(', '@importView(', $declarations)."\n".$script."\n</script>\n".$template, $options);
        self::assertSame('ts', $inside->lang);
        self::assertSame($outside->blade, $inside->blade);
        $compact = static fn (string $s): string => preg_replace('/^\h+|\h+$|^\s*\n/m', '', $s);
        self::assertSame($compact($outside->js), $compact($inside->js));
        self::assertSame($outside->imports, $inside->imports);
        self::assertStringNotContainsString('@state(', $inside->js);
        self::assertStringNotContainsString('@importView', $inside->js);
        self::assertStringContainsString(', cardPath, parentElement,', $inside->js);
        self::assertLessThan(strpos($inside->blade, '@exec($__ONE_COMPONENT_REGISTRY__'), strpos($inside->blade, '@let($cardPath'));
    }

    public function test_imported_view_paths_keep_variables_for_tags_with_children(): void
    {
        $r = (new SaolaCompiler())->compile(<<<'SAO'
<script setup>
@props({cardPath: 'web.card'}: {cardPath: string})
@importView(cardPath as Card)
</script>
<template>
<Card><span>Child</span></Card>
@include('web.static')
</template>
SAO, new CompileOptions(viewPath: 'test.setup-path'));
        self::assertStringContainsString(', cardPath, parentElement,', $r->js);
        self::assertStringContainsString(", 'web.static', parentElement,", $r->js);
        self::assertStringContainsString("'Card' => \$cardPath", $r->blade);
    }

    public function test_mixed_positions_and_multiple_setup_blocks_keep_source_order(): void
    {
        $parts = (new SourceSplitter())->split(<<<'SAO'
@vars(initial = 2)
<script setup lang="ts">
import type { Row } from './rows';
@state(count: number = initial);
</script>
@const(factor = 2)
<script lang="ts" setup>
@computed(total: number = count * factor)
export default { increment() { setCount(count + 1); } }
</script>
<template>{{ total }}</template>
SAO);
        self::assertSame(['@vars(initial = 2)', '@state(count: number = initial)', '@const(factor = 2)', '@computed(total: number = count * factor)'], $parts->declarations);
        self::assertStringContainsString("import type { Row } from './rows'", $parts->cleanedContent);
        self::assertStringNotContainsString('@state', $parts->script);
    }

    public function test_strings_comments_regex_and_nested_templates_are_not_declarations(): void
    {
        $parts = (new SourceSplitter())->split(<<<'SAO'
<script setup>
// @state(comment = 1)
/* @import('not.a.view') */
const text = "@state(quoted = 2)";
const pattern = /[@state(regex = 3)]/;
const template = `hello ${`nested ${"@state(nested = 4)"}`} @props(fake = 1)`;
const flag = '@await @fetch("fake")';
@state(real: number = 5)
export default { read() { return /@state(method = 6)/; } }
</script>
<script>const plain = '@state(plain = 1)';</script>
<style>div::after { content: '@state(css = 1)'; }</style>
<template>{{ real }}</template>
SAO);
        self::assertSame(['@state(real: number = 5)'], $parts->declarations);
        self::assertSame('{{ real }}', $parts->blade);
        self::assertStringContainsString('@state(quoted = 2)', $parts->script);
        self::assertStringContainsString('@state(method = 6)', $parts->script);
    }

    public function test_nested_parentheses_and_quoted_closing_parenthesis(): void
    {
        $parts = (new SourceSplitter())->split(<<<'SAO'
<script setup>
@vars(rows: Array<{id: number}> = [], caption: string = ')')
@computed(total = rows.filter(row => (row.id > 0)).length)
</script>
<template>{{ total }}</template>
SAO);
        self::assertCount(2, $parts->declarations);
        self::assertStringEndsWith("caption: string = ')')", $parts->declarations[0]);
    }

    public function test_setup_literals_survive_the_full_pipeline_without_enabling_fetch(): void
    {
        foreach (['setup lang="ts"', 'lang="ts" setup'] as $attributes) {
            $r = (new SaolaCompiler())->compile('<script '.$attributes.'>'.<<<'SAO'
@state(real: number = 5)
const text = "@state(quoted = 2)";
const flag = '@await @fetch("fake")';
export default { read() { return "@state(method = 6)"; } }
</script>
<template>{{ real }}</template>
SAO, new CompileOptions(viewPath: 'test.setup-literals'));
            self::assertStringContainsString('"@state(quoted = 2)"', $r->js);
            self::assertStringContainsString('"@state(method = 6)"', $r->js);
            self::assertStringContainsString('hasAwaitData: false', $r->js);
            self::assertStringContainsString('hasFetchData: false', $r->js);
            self::assertStringNotContainsString('$quoted', $r->blade);
        }
    }

    public function test_import_in_setup_has_an_actionable_error(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('Use @importView');
        (new SourceSplitter())->split("<script setup>\n@import('web.card')\n</script>");
    }

    public function test_declarations_in_functions_are_rejected_at_the_source_line(): void
    {
        try {
            (new SourceSplitter())->split("<script setup>\nexport default {\n mounted() {\n @state(count = 0)\n }\n}\n</script>");
            self::fail('Expected a scope error.');
        } catch (CompileException $e) {
            self::assertSame(4, $e->sourceLine);
            self::assertStringContainsString('top-level', $e->getMessage());
        }
    }

    public function test_unclosed_declarations_are_rejected(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('Unclosed setup declaration');
        (new SourceSplitter())->split('<script setup>@state(count = (1 + 2)</script>');
    }

    public function test_simple_component_still_needs_no_script(): void
    {
        $r = (new SaolaCompiler())->compile('@state(count = 0)<button @click(setCount(count + 1))>{{ count }}</button>', new CompileOptions(viewPath: 'test.simple'));
        self::assertSame('js', $r->lang);
        self::assertStringContainsString('setCount', $r->js);
        self::assertStringContainsString('$count', $r->blade);
    }

    public function test_duplicate_bindings_across_inside_and_outside_are_rejected(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('Duplicate view declaration "count"');
        (new SourceSplitter())->split('@state(count: number = 0)<script setup>@state(count: number = 1)</script>');
    }
}
