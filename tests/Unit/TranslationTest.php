<?php
namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

final class TranslationTest extends TestCase
{
    public function test_translation_calls_and_inline_directives_are_compiled_to_runtime_helpers(): void
    {
        $result = (new SaolaCompiler())->compile(<<<'SAO'
<p>@lang('messages.welcome', ['name' => 'Lan'])</p>
<p>@choice('messages.apples', 2)</p>
<p>{{ __('Hello') }}</p>
SAO, new CompileOptions(viewPath: 'test.translations', functionName: 'Translations', factoryName: 'Translations'));
        self::assertStringContainsString("App.Helper.__('messages.welcome'", $result->js);
        self::assertStringContainsString("App.Helper.trans_choice('messages.apples', 2)", $result->js);
        self::assertStringContainsString("App.Helper.__('Hello')", $result->js);
        self::assertStringNotContainsString("this.text('@lang", $result->js);
        self::assertSame([], $result->warnings);
    }
}
