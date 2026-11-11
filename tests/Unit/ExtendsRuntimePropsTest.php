<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\{SaolaCompiler, CompileOptions, Lang};
use Saola\Compiler\Directive\DirectiveParsers;

final class ExtendsRuntimePropsTest extends TestCase
{
    public function test_sao_layout_props_remain_runtime_expressions_in_both_outputs(): void
    {
        $source = <<<'SAO'
@vars(navigation: any[] = [], brand: string = '')
@await
<template>
@extends(__base__ + 'layouts.shell', {navigation: navigation, brand: brand})
@block('content')
<p>{{ brand }}</p>
@endblock
</template>
SAO;
        $result = (new SaolaCompiler())->compile($source, new CompileOptions(lang: Lang::Ts));
        $this->assertStringNotContainsString('Warning:', $result->js);
        $this->assertMatchesRegularExpression('/extendView\(this\.superViewPath, \{["\']navigation["\']:\s*navigation,\s*["\']brand["\']:\s*brand\}\)/', $result->js);
        $this->assertStringContainsString("'navigation'=> \$navigation", $result->blade);
        $this->assertStringContainsString("'brand'=> \$brand", $result->blade);
    }

    public function test_nested_calls_and_commas_in_literal_layout_names_do_not_truncate_props(): void
    {
        [$name, , $data] = (new DirectiveParsers())->parseExtends(<<<'SAO'
@extends('layout,wide', ['title' => strtoupper($name), 'items' => [$first, $second]])
SAO);
        $this->assertSame('layout,wide', $name);
        $this->assertStringContainsString('name', $data);
        $this->assertStringContainsString('first', $data);
        $this->assertStringContainsString('second', $data);
        $this->assertStringNotContainsString('null', $data);
    }
}
