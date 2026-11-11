<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Hydration;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `{!! !!}` phải có marker output ở CẢ Blade lẫn JS, kể cả khi không đọc state.
 *
 * Trước 11/2026 JS luôn dựng `this.output()` cho HTML thô còn Blade bỏ marker
 * khi biểu thức tĩnh. Hydrate không claim được nên chèn thêm một bản: trang
 * docs dùng `{!! trans('…') !!}` hiện mỗi tiêu đề hai lần.
 */
final class RawEchoMarkerTest extends TestCase
{
    /** @return array{list<string>, list<string>} id output phía Blade và phía JS */
    private function outputIds(string $template): array
    {
        $result = (new SaolaCompiler())->compile("<template>\n{$template}\n</template>\n", new CompileOptions(
            viewPath: 'test.raw', functionName: 'V', factoryName: 'VFactory',
        ));
        preg_match_all("/@startMarker\('output', '([^']+)'\)/", (string) $result->blade, $blade);
        preg_match_all('/this\.output\(`([^`]+)`/', (string) $result->js, $js);

        return [$blade[1], $js[1]];
    }

    public function test_echo_tho_khong_state_van_co_marker_hai_phia(): void
    {
        [$blade, $js] = $this->outputIds("<h1>{!! trans('a.b') !!}</h1>");
        $this->assertCount(1, $js);
        $this->assertSame($js, $blade);
    }

    public function test_echo_escape_khong_state_khong_co_marker_o_ca_hai_phia(): void
    {
        [$blade, $js] = $this->outputIds("<h1>{{ trans('a.b') }}</h1>");
        $this->assertSame([], $js);
        $this->assertSame([], $blade);
    }

    public function test_tron_tho_va_escape_trong_cung_mot_the_van_khop_id(): void
    {
        [$blade, $js] = $this->outputIds("<p>{{ trans('a') }} {!! trans('b') !!} {!! trans('c') !!}</p>");
        $this->assertCount(2, $js);
        $this->assertSame($js, $blade);
    }

    /** Slot nội dung con đi qua `__children()` phía JS — bọc marker ở Blade là lệch. */
    public function test_slot_children_khong_bi_boc_marker(): void
    {
        [$blade, $js] = $this->outputIds("<div>@children</div>");
        $this->assertSame([], $js);
        $this->assertSame([], $blade);
    }
}
