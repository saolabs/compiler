<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Hydration;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Hydration\HydrateId;
use Saola\Compiler\SaolaCompiler;

/**
 * Id của element khai báo TRONG `@block` phải duy nhất và cùng một kiểu chữ.
 *
 * Trước 2026-11-11 tiền tố là `B<tên>` không dấu đóng: tên có chữ hoa
 * (`mainContent`) bị terse bỏ hẳn tiền tố nên trùng id với element ngoài block;
 * tên kết thúc bằng mẫu "<loại><số>" (`page2`) nhập nhằng với block `pag`.
 */
final class BlockScopeIdTest extends TestCase
{
    public function test_ten_block_duoc_dong_bang_gach_noi(): void
    {
        $this->assertSame('scontent-12', HydrateId::hash('block-content-div-1-p-2'));
        $this->assertSame('smainContent-1', HydrateId::hash('block-mainContent-div-1'));
        // 'page2' > e1 và 'pag' > e2 > e1 từng cùng ra 'Bpag21'.
        $this->assertNotSame(HydrateId::hash('block-page2-div-1'), HydrateId::hash('block-pag-div-2-p-1'));
    }

    public function test_id_trong_view_khong_trung_va_blade_khop_js(): void
    {
        // @block chỉ hợp lệ ở cấp ngoài cùng của view có @extends (xem BlockPlacementTest).
        $source = "<template>\n@extends('layouts.app')\n@block('head')\n<div>\n<p>{{ \$a }}</p>\n<output>{{ \$a }}</output>\n</div>\n@endblock\n"
            ."@block('mainContent')\n<section><p>x</p></section>\n@endblock\n"
            ."@block('page2')\n<section><p>x</p></section>\n@endblock\n"
            ."@block('pag')\n<section><i>a</i><p>x</p></section>\n@endblock\n</template>\n";
        $result = (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.blocks', functionName: 'V', factoryName: 'VFactory',
        ));

        preg_match_all('/this\.(?:html|output)\(`([^`]+)`/', (string) $result->js, $js);
        $this->assertSame($js[1], array_values(array_unique($js[1])), 'id trùng: '.implode(', ', $js[1]));

        preg_match_all("/__VIEW_ID__ \. '-([^']+)'/", (string) $result->blade, $blade);
        preg_match_all('/this\.html\(`([^`]+)`/', (string) $result->js, $html);
        $this->assertSame($html[1], $blade[1], 'Blade và JS lệch id element');
        $this->assertContains('smainContent-1', $html[1]);
    }
}
