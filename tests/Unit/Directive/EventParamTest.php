<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Directive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * Hồi quy §19: runtime gọi `p(event)` cho mỗi param là hàm
 * (ViewController.ts), nhưng compiler sinh `() => ...` — closure bỏ qua tham
 * số nên `event` bên trong là biến TỰ DO. Nó chạy được nhờ `window.event`
 * (deprecated) nên bug này im lặng suốt, và sẽ hỏng khi param được gọi ngoài
 * lúc dispatch.
 *
 * Cổng parity KHÔNG bắt được: cả hai bản sinh sai giống hệt nhau.
 */
final class EventParamTest extends TestCase
{
    private function compile(string $handler): string
    {
        $source = "@states({ n: 1 })\n<template><input @input({$handler})></template>\n"
            . "<script setup>\nexport default { m(a, b) {} }\n</script>";

        $js = (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.view',
            functionName: 'TestView',
            factoryName: 'TestViewFactory',
        ))->js ?? '';

        preg_match('/events: \{ input: (\[.*?\]) \}/', $js, $m);

        return $m[1] ?? '';
    }

    #[DataProvider('thamSoCoEvent')]
    public function test_moi_tham_so_dung_event_deu_duoc_boc_closure_co_tham_so(string $handler): void
    {
        $params = $this->compile($handler);

        self::assertNotSame('', $params, 'không sinh được events');

        // Bỏ mọi closure ĐÚNG rồi soi phần còn lại: còn `event` nào là biến tự do
        $remaining = preg_replace('/\(event\) =>[^,\]]*/', '', $params) ?? $params;

        self::assertDoesNotMatchRegularExpression(
            '/(?:^|[^.\w])event\b/',
            $remaining,
            "còn tham chiếu `event` ngoài closure: {$params}",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function thamSoCoEvent(): array
    {
        return [
            'event trần' => ['m(event, 1)'],
            'truy cập thuộc tính' => ['m(event.target.value)'],
            'gọi hàm lồng' => ['m(String(event.key))'],
            'nhiều tham số' => ['m(event.target.value, event.type)'],
            'biểu thức' => ['m(event.target.value + 1)'],
            'mảng' => ['m([event.target.value])'],
            'object' => ['m({k: event.target.value})'],
        ];
    }

    /**
     * `preventDefault` CÓ chứa chuỗi "event" (p-r-"event"-Default) nhưng không
     * tham chiếu biến nào — luật cũ dùng `str_contains` nên gắn tham số thừa.
     */
    public function test_ten_chi_chua_chuoi_event_khong_bi_boc(): void
    {
        self::assertStringNotContainsString('(event) =>', $this->compile('m(n.preventDefault)'));
    }

    public function test_tham_so_khong_lien_quan_event_giu_nguyen(): void
    {
        self::assertStringNotContainsString('(event) =>', $this->compile('m(1, 2)'));
    }
}
