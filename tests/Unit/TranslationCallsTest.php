<?php

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Expr\TranslationCalls;
use Saola\Compiler\Expr\HelperResolver;
use Saola\Compiler\Hydration\MarkerParity;
use Saola\Compiler\SaolaCompiler;

class TranslationCallsTest extends TestCase
{
    public function test_sao_translation_calls_lower_to_text_in_php_and_client(): void
    {
        $source = <<<'SAO'
@vars(key = 'auth.keep_login', name = 'Lan')
<template>
  <p>{{ __('save') }}</p>
  <b>{{ __(key, ['name' => name], null, 'vi') }}</b>
  <i>{{ text('root') }}</i>
  <span>literal __('unchanged')</span>
</template>
SAO;
        $result = (new SaolaCompiler())->compile($source, new CompileOptions(viewPath: 'test.lang', functionName: 'V', factoryName: 'VF'));
        $this->assertStringContainsString("text('save')", $result->blade);
        $this->assertStringContainsString("text(\$key, ['name' => \$name], null, 'vi')", $result->blade);
        $this->assertStringContainsString("App.Helper.text('save')", $result->js);
        $this->assertStringContainsString("App.Helper.text(key,", $result->js);
        $this->assertStringContainsString("App.Helper.text('root')", $result->js);
        $this->assertStringContainsString("literal __('unchanged')", $result->blade);
        $this->assertSame(['blade' => [], 'js' => []], MarkerParity::diff($result->blade, $result->js));
    }

    public function test_literals_members_comments_and_verbatim_are_not_rewritten(): void
    {
        $input = <<<'BLADE'
{{-- {{ __('comment') }} --}}
@verbatim{{ __('verbatim') }}@endverbatim
<script>const label = "__('script')";</script>
<p>{{ "__('literal')" }}</p>
@if(__('enabled')){{ $obj->__('method') }}@endif
@php($x = __('save'))
BLADE;
        $output = TranslationCalls::blade($input);
        $this->assertStringContainsString("{{ __('comment') }}", $output);
        $this->assertStringContainsString("{{ __('verbatim') }}", $output);
        $this->assertStringContainsString("__('script')", $output);
        $this->assertStringContainsString("__('literal')", $output);
        $this->assertStringContainsString("\$obj->__('method')", $output);
        $this->assertStringContainsString("@if(text('enabled'))", $output);
        $this->assertStringContainsString("@php(\$x = text('save'))", $output);
    }

    public function test_handwritten_event_arrows_use_text_without_rewriting_literals_or_members(): void
    {
        $resolver = new HelperResolver();
        $this->assertSame("() => App.Helper.text(key)", $resolver->resolveUserMethodCalls('() => __(key)'));
        $this->assertSame("() => obj.__('save')", $resolver->resolveUserMethodCalls("() => obj.__('save')"));
        $this->assertSame('() => "__(key)"', $resolver->resolveUserMethodCalls('() => "__(key)"'));
    }
}
