<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Hydration;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * Directive chỉ dành cho CSR không được lọt vào Blade.
 *
 * Blade để nguyên văn directive nó không biết, nên mọi thứ lọt qua đây đều
 * biến thành attribute rác trong HTML SSR và làm lệch hydration.
 */
final class BladeCsrDirectiveLeakTest extends TestCase
{
    private function blade(string $template): string
    {
        return (new SaolaCompiler())->compile(
            "@states({ n: 0 })\n<template>{$template}</template>\n",
            new CompileOptions(viewPath: 'test.view', functionName: 'TestView', factoryName: 'TestViewFactory'),
        )->blade ?? '';
    }

    public function test_transition_khong_lot_vao_blade(): void
    {
        $blade = $this->blade("<div class=\"row\" @transition('fade')>x</div>");

        $this->assertStringNotContainsString('@transition', $blade);
        $this->assertStringContainsString("'row'", $blade);
    }

    public function test_event_co_modifier_khong_thanh_attr_rac(): void
    {
        $blade = $this->blade('<button class="del" @click.stop(removeUser(n))>x</button>');

        $this->assertStringNotContainsString('click.stop', $blade);
        $this->assertStringNotContainsString('removeUser', $blade);
    }
}
