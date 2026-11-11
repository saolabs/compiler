<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Emit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * Tham số cuối của `__foreach` — mức "giữ nguyên dòng không đổi".
 *
 * Client tin tuyệt đối vào con số này: 1/2 mà thân vòng lặp lại đọc thứ runtime
 * không theo dõi được thì UI hiện dữ liệu cũ, không lỗi, không cảnh báo. Vì vậy
 * mọi ca "không chắc" PHẢI ra 0.
 */
final class ForeachMemoModeTest extends TestCase
{
    private function mode(string $body, string $script = ''): int
    {
        $source = "<template>\n<ul>\n@foreach(rows as row)\n@key(row['id'])\n{$body}\n@endforeach\n</ul>\n</template>\n"
            ."<script setup>\n@state(rows = [], selected = 0)\n{$script}\n</script>\n";
        $js = (string) (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.memo', functionName: 'V', factoryName: 'VFactory',
        ))->js;
        $this->assertSame(1, preg_match('/true, "[^"]+", (\d)\)/', $js, $m), 'không thấy tham số memo');

        return (int) $m[1];
    }

    public function test_than_chi_dung_item_va_state_thi_bat(): void
    {
        $this->assertSame(1, $this->mode("<li @class({'on': selected === row['id']}) @click(pick(row['id']))>{{ row['label'] }}</li>"));
    }

    public function test_dung_vi_tri_trong_vong_lap_thi_kem_dieu_kien_vi_tri(): void
    {
        $this->assertSame(2, $this->mode("<li>{{ loop.index }} {{ row['label'] }}</li>"));
    }

    public function test_bien_let_hoac_ham_tu_viet_thi_tat(): void
    {
        $this->assertSame(0, $this->mode("<li>{{ editingId === row['id'] ? 'x' : 'y' }}</li>", 'let editingId = null;'));
        // Method của view đọc được biến `let`: runtime không biết khi nào nó đổi.
        $this->assertSame(0, $this->mode("<li>{{ this.fmt(row['label']) }}</li>", 'function fmt(v) { return v; }'));
        $this->assertSame(0, $this->mode("<li>{{ this.view.fmt(row['label']) }}</li>", 'function fmt(v) { return v; }'));
    }

    /** `fmt(...)` trần trong template là helper toàn cục (App.Helper), không phải method của view. */
    public function test_helper_toan_cuc_van_bat(): void
    {
        $this->assertSame(1, $this->mode("<li>{{ fmt(row['label']) }}</li>"));
    }

    public function test_than_doc_chinh_state_cua_danh_sach_thi_tat(): void
    {
        $this->assertSame(0, $this->mode("<li>{{ rows.length }} {{ row['label'] }}</li>"));
    }
}
