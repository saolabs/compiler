<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Hydration\BladeHydrateProcessor;
use Saola\Compiler\SaolaCompiler;
use Saola\Compiler\Support\Html;

final class RcdataContentTest extends TestCase
{
    public function test_textarea_and_title_have_direct_ssr_echoes_and_element_bindings(): void
    {
        $result = (new SaolaCompiler())->compile(<<<'SAO'
        <template>
        <textarea id="plain">Prefix &amp; {{ message }} &lt;b&gt;</textarea>
        <textarea id="bound" @bind(message)></textarea>
        <title>{{ message }}</title>
        <p>{{ message }}</p>
        </template>
        <script setup>
        @state(message = 'hello')
        </script>
        SAO, new CompileOptions(viewPath: 'test.rcdata', functionName: 'Rcdata', factoryName: 'Rcdata'));

        preg_match_all('~<(textarea|title)\b[^>]*>(.*?)</\1>~s', $result->blade, $matches);
        $this->assertCount(3, $matches[2]);
        foreach ($matches[2] as $content) {
            $this->assertStringContainsString('{{ $message }}', $content);
            $this->assertStringNotContainsString('@startMarker', $content);
            $this->assertStringNotContainsString('@endMarker', $content);
        }
        $this->assertStringContainsString('content: { factory:', $result->js);
        $this->assertSame(1, substr_count($result->js, "bind: { key: 'message' }"));
        $this->assertSame(1, substr_count($result->js, 'this.output('));
        $this->assertStringContainsString("@startMarker('output'", $result->blade);
    }

    public function test_rcdata_is_not_parsed_as_nested_markup_or_control_directives(): void
    {
        $source = "<textarea>\n@if(message)\n<b>{{ message }}</b>\n</textarea><p>{{ message }}</p>";
        $blade = (new BladeHydrateProcessor(['message']))->process($source);
        $this->assertStringContainsString("\n@if(message)\n<b>{{ message }}</b>\n", $blade);
        $this->assertSame(1, substr_count($blade, '@startMarker'));
        $this->assertSame(2, substr_count($blade, '@class'));
    }

    public function test_normalization_preserves_explicit_content_and_literal_regions(): void
    {
        $this->assertSame('<textarea @bind(message)>{{ message }}</textarea>', Html::normalizeTextareaBindings('<textarea @bind(message)>  </textarea>'));
        foreach ([
            '<textarea>{{ message }}</textarea>',
            '<textarea @bind(message)>{{ other }}</textarea>',
            '<textarea @bind(message)>static text</textarea>',
            '<textarea data-example="@bind(message)"></textarea>',
            '<script>const example = "<textarea @bind(message)></textarea>";</script>',
            '@verbatim<textarea @bind(message)></textarea>@endverbatim',
            '{{-- <textarea @bind(message)></textarea> --}}',
        ] as $source) {
            $this->assertSame($source, Html::normalizeTextareaBindings($source));
        }
    }

    public function test_script_and_style_contents_never_receive_hydration_markers(): void
    {
        $source = '<script>const value = "{{ message }} <b>";</script><style>.x { content: "{{ message }}"; }</style>';
        $this->assertSame($source, (new BladeHydrateProcessor(['message']))->process($source));
    }
}
