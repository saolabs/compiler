<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Lang;
use Saola\Compiler\SaolaCompiler;

/**
 * `[TYPE:x]` chỉ được gỡ khi viết đúng dạng `:[TYPE:x]` hoặc ` as [TYPE:x]`.
 * Viết lệch một khoảng trắng (`: [TYPE:this]`) thì hai regex trong
 * MainCompiler::processTypeMarkers/removeTypeMarkers đều trượt, marker lọt
 * nguyên vào output — lỗi cú pháp ở CẢ js lẫn ts, mà compiler không kêu.
 */
final class TypeMarkerLeakTest extends TestCase
{
    private function compile(Lang $lang): string
    {
        $result = (new SaolaCompiler())->compile('<p>x</p>', new CompileOptions(
            viewPath: 'test.marker',
            functionName: 'MarkerView',
            factoryName: 'MarkerViewFactory',
            lang: $lang,
        ));

        return (string) $result->js;
    }

    /** @return array<string,array{Lang}> */
    public static function langs(): array
    {
        return ['js' => [Lang::Js], 'ts' => [Lang::Ts]];
    }

    #[DataProvider('langs')]
    public function test_khong_con_marker_TYPE_trong_output(Lang $lang): void
    {
        $this->assertStringNotContainsString('[TYPE:', $this->compile($lang));
    }
}
