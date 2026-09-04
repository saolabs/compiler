<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Hydration;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * Id hydrate của `@useBlock` phải DUY NHẤT trong cùng một scope.
 *
 * Trước 2026-09-03 `nextBlockOutletId()` trả hằng `'block-outlet'` không kèm bộ
 * đếm, với giả định "mỗi scope chỉ có một outlet". Layout hai outlet vì vậy sinh
 * hai id trùng nhau, marker thứ hai không tồn tại trong DOM và outlet đó không
 * bao giờ mount — Blade lẫn JS cùng sai nên cổng marker-sync vẫn xanh.
 */
final class BlockOutletIdTest extends TestCase
{
    private function compile(string $source): object
    {
        return (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.layout',
            functionName: 'LayoutView',
            factoryName: 'LayoutViewFactory',
        ));
    }

    /** @return list<string> */
    private function outletIds(string $js): array
    {
        preg_match_all('/this\.blockOutlet\(`([^`]+)`/', $js, $m);

        return $m[1];
    }

    public function test_nhieu_useblock_cung_scope_ra_id_khac_nhau(): void
    {
        $js = (string) $this->compile(
            "<template>\n<div>\n@useBlock('a')\n@useBlock('b')\n@useBlock('c')\n</div>\n</template>\n"
        )->js;

        $ids = $this->outletIds($js);

        $this->assertCount(3, $ids, 'phải emit đủ ba outlet');
        $this->assertSame($ids, array_values(array_unique($ids)), 'id outlet trùng nhau: '.implode(', ', $ids));
    }

    public function test_blade_va_js_dung_cung_bo_id(): void
    {
        $result = $this->compile(
            "<template>\n<div>\n@useBlock('a')\n@useBlock('b')\n</div>\n</template>\n"
        );

        preg_match_all("/@startMarker\('blockoutlet', '([^']+)'\)/", (string) $result->blade, $m);

        $this->assertSame(
            $this->outletIds((string) $result->js),
            $m[1],
            'Blade và JS phải cùng dãy id, nếu không hydrate không claim được outlet',
        );
    }
}
