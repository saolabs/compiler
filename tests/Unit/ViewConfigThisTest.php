<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `this: ViewConfigThis` — tham số CHỈ có ở TypeScript.
 *
 * ViewController gọi 3 hàm config bằng `fn.call(makeConfigThis(), …)`, receiver
 * KHÔNG phải object chứa chúng. Thiếu khai báo thì `this.config` rơi xuống `any`
 * qua index signature của ViewRuntimeConfig — không báo lỗi, chỉ lặng lẽ bỏ kiểm.
 * Ngược lại, để nó lọt vào đầu ra .js là lỗi cú pháp ngay khi trình duyệt nạp.
 *
 * Bất biến khoá ở đây: emit tham số `this` KHI VÀ CHỈ KHI lang === 'ts'.
 * Trước đây nhánh emit bám riêng `setupLang === 'typescript'` trong khi đuôi file
 * do `$this->isTypescript` quyết định → view khai kiểu mà không có <script setup>
 * vẫn ra .ts nhưng thiếu khai báo.
 */
final class ViewConfigThisTest extends TestCase
{
    public static function views(): iterable
    {
        yield 'kiểu trên directive, không có <script setup>' => [
            '@state(n: number = 1)<template><div>{{ n }}</div></template>',
        ];
        yield '<script setup lang=ts> + kiểu trên directive' => [
            "<script setup lang=\"ts\">\nconst greeting: string = 'hi';\n</script>\n"
            .'@state(n: number = 1)<template><div>{{ n }}{{ greeting }}</div></template>',
        ];
        yield 'không khai kiểu → JavaScript' => [
            '@state(n = 1)<template><div>{{ n }}</div></template>',
        ];
        yield '<script setup> không khai kiểu → JavaScript' => [
            "<script setup>\nconst greeting = 'hi';\n</script>\n"
            .'<template><div>{{ greeting }}</div></template>',
        ];
    }

    #[DataProvider('views')]
    public function test_this_parameter_is_emitted_exactly_when_the_output_is_typescript(string $source): void
    {
        $r = (new SaolaCompiler())->compile($source, new CompileOptions(viewPath: 'web.probe'));

        if ($r->lang === 'ts') {
            self::assertStringContainsString("import type { ViewConfigThis } from '@saolabs/client';", $r->js);
            self::assertStringContainsString('commitConstructorData: function(this: ViewConfigThis)', $r->js);
            self::assertStringContainsString('updateVariableData: function(this: ViewConfigThis, data: any)', $r->js);
            self::assertStringContainsString('updateVariableItemData: function(this: ViewConfigThis, key: string, value: any)', $r->js);

            return;
        }

        self::assertSame('js', $r->lang);
        // `this` là tham số ảo của TS; lọt vào .js thì nó thành ĐỐI SỐ THẬT và
        // mọi lời gọi updateVariableItemData(key, value) bị lệch tham số.
        self::assertStringNotContainsString('ViewConfigThis', $r->js);
        self::assertStringNotContainsString('import type', $r->js);
        self::assertStringContainsString('commitConstructorData: function()', $r->js);
        self::assertStringContainsString('updateVariableData: function(data)', $r->js);
        self::assertStringContainsString('updateVariableItemData: function(key, value)', $r->js);
    }
}
