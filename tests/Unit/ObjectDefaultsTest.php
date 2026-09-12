<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `{a = 1}` (chuẩn TS, trong <script setup>) và `{a: 1}` (gõ nhanh ngoài
 * script) là một nghĩa và phải ra cùng output với dạng phẳng.
 */
final class ObjectDefaultsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function directives(): iterable
    {
        yield '@props' => ['props'];
        yield '@vars' => ['vars'];
        yield '@states' => ['states'];
    }

    #[DataProvider('directives')]
    public function test_equals_and_colon_object_forms_match_the_flat_form(string $directive): void
    {
        $equals = "@{$directive}({a = 1, b = 'x', u = {name: 'S'}}: {a: number; b: string; u: {name: string}})";
        $colon = "@{$directive}({a: 1, b: 'x', u: {name: 'S'}}: {a: number; b: string; u: {name: string}})";
        $flat = "@{$directive}(a: number = 1, b: string = 'x', u: {name: string} = {name: 'S'})";
        [$e, $c, $f] = array_map($this->compile(...), [$equals, $colon, $flat]);
        self::assertSame('ts', $e->lang);
        self::assertSame($c->js, $e->js);
        self::assertSame($c->blade, $e->blade);
        self::assertSame($f->js, $e->js);
        // Dạng phẳng có kiểu để lại một khoảng trắng thừa sau `=` trong Blade.
        self::assertSame(preg_replace('/= +/', '= ', $f->blade), preg_replace('/= +/', '= ', $e->blade));
        self::assertStringContainsString($directive === 'states' ? 'let a: number = 1;' : "let {a = 1, b = 'x', u = ", $e->js);
    }

    public function test_untyped_object_form_declares_every_field(): void
    {
        $r = $this->compile("@vars({a = 1, b: 'x', c})");
        self::assertSame('js', $r->lang);
        self::assertStringContainsString("let {a = 1, b = 'x', c} = __data__;", $r->js);
        self::assertStringContainsString("@vars(\$a = 1, \$b = 'x', \$c)", $r->blade);
    }

    public function test_bad_field_is_rejected_instead_of_leaking(): void
    {
        $this->expectExceptionMessage('Invalid object default in @props');
        $this->compile("@props({a = 1, 'b': 2})");
    }

    private function compile(string $declaration): \Saola\Compiler\CompileResult
    {
        return (new SaolaCompiler())->compile(
            "<template><div>{{ a }} {{ b }}</div></template>\n<script setup>\n{$declaration}\n</script>\n",
            new CompileOptions(viewPath: 'test.object-defaults'),
        );
    }
}
