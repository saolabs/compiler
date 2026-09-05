<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * Backtick trong văn xuôi template phải ra HTML y như người viết gõ.
 *
 * Bản cũ escape mọi ` thành \` trước khi parse, cho ngữ cảnh template literal.
 * Sai cả hai đầu, và cả hai đều im lặng:
 *
 *   - văn xuôi: text đi tiếp vào `jsTextLiteral` (chuỗi NHÁY ĐƠN), nơi \ lại
 *     thành \\ → người đọc trang thấy dấu \ thừa. Đo trên /docs/architecture
 *     và /docs/sao-file ngày 05/09/2026: nguồn viết `.sao`, trang hiện \`.sao\`.
 *   - biểu thức: id hydrate được bọc trong template literal, nên `@key` chứa
 *     template literal sinh ra `${`k-${…}`}` — JS HỢP LỆ (lồng nhau được).
 *     Bản escape sinh `${\`k-${…}\`}` → SyntaxError. Tức là nó hỏng đúng ca
 *     nó định bảo vệ.
 *
 * Cổng parity SSR↔CSR bắt được lỗi văn xuôi, nhưng chỉ trên route có backtick.
 * Golden examples KHÔNG bắt được: không example nào có backtick trong văn xuôi.
 */
final class BacktickInTextTest extends TestCase
{
    private function compile(string $source, string $path = 'test.backtick'): object
    {
        return (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: $path,
            functionName: 'BacktickView',
            factoryName: 'BacktickViewFactory',
        ));
    }

    public function test_backtick_trong_van_xuoi_khong_bi_them_dau_gach_cheo(): void
    {
        $result = $this->compile(<<<'SAO'
        <template>
            <p>Nhúng component `.sao` con vào template.</p>
        </template>
        SAO);

        $js = (string) $result->js;

        // Blade là mốc so sánh: nó luôn giữ nguyên văn.
        $this->assertStringContainsString('`.sao`', (string) $result->blade);

        $this->assertStringContainsString(
            "this.text('Nhúng component `.sao` con vào template.')",
            $js,
            'Backtick trong văn xuôi phải vào chuỗi nháy đơn nguyên vẹn',
        );
        $this->assertStringNotContainsString(
            '\\\\`',
            $js,
            'Escape kép: chuỗi nháy đơn biến \\\\` thành một dấu \\ thật rồi mới tới backtick',
        );
    }

    public function test_template_literal_trong_key_sinh_ra_js_hop_le(): void
    {
        $result = $this->compile(<<<'SAO'
        @states({ rows: [] })

        <template>
            <ul>
                @foreach(rows as r)
                    @key(`k-${r['id']}`)
                    <li>{{ r['id'] }}</li>
                @endforeach
            </ul>
        </template>
        SAO);

        // Template literal LỒNG trong `${...}` của id — hợp lệ, và là thứ duy nhất
        // giữ đúng ngữ nghĩa. Có \` ở đây là SyntaxError khi trình duyệt nạp file.
        $this->assertMatchesRegularExpression(
            '/this\.html\(`[^`]*\$\{`k-\$\{r\[\'id\'\]\}`\}`/',
            (string) $result->js,
        );
        $this->assertStringNotContainsString('\\`', (string) $result->js);
    }
}
