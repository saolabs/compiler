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
        $this->assertSame(1, preg_match('/true, "[^"]+", (\d)(?:, \[([^\]]*)\])?\)/', $js, $m), 'không thấy tham số memo');
        $this->deps = $m[2] ?? null;

        return (int) $m[1];
    }

    /** Danh sách state phụ thuộc phát kèm mức memo của lần `mode()` gần nhất. */
    private ?string $deps = null;

    /**
     * Client chỉ chạy lại dòng khi state trong danh sách này đổi. Thiếu một
     * state bị "chụp một lần" (tham số event, data của include, {{ }} không
     * stateKeys…) là UI hiện dữ liệu cũ; thừa thì chỉ chậm.
     */
    public function test_state_chi_nam_trong_binding_tu_cap_nhat_thi_khong_la_phu_thuoc(): void
    {
        $this->assertSame(1, $this->mode("<li @class({'on': selected === row['id']})>{{ row['label'] }}</li>"));
        $this->assertSame('', $this->deps);
    }

    public function test_state_bi_chup_trong_tham_so_event_la_phu_thuoc(): void
    {
        $this->assertSame(1, $this->mode("<li @click(pick(row['id'], selected))>{{ row['label'] }}</li>"));
        $this->assertSame('"selected"', $this->deps);
    }

    public function test_state_trong_if_long_la_phu_thuoc(): void
    {
        $this->assertSame(1, $this->mode("<li>@if(selected === row['id'])<b>x</b>@endif</li>"));
        $this->assertSame('"selected"', $this->deps);
    }

    public function test_muc_0_khong_kem_danh_sach(): void
    {
        $this->assertSame(0, $this->mode("<li>{{ rows.length }}</li>"));
        $this->assertNull($this->deps);
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

    public function test_textarea_va_title_co_attr_tinh_khong_bi_hoist_truoc_khi_noi_content(): void
    {
        $source = "<template>\n<textarea class=\"field\" rows=\"4\">{{ note }}</textarea>\n</template>\n"
            ."<script setup>\n@state(note = '')\n</script>\n";
        $js = (string) (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.textarea', functionName: 'V', factoryName: 'VFactory',
        ))->js;
        $this->assertStringContainsString('content: { factory: () => String(note ?? \'\'), stateKeys: ["note"] } }', $js);
        $this->assertStringNotContainsString('__sc', $js);
    }
}
