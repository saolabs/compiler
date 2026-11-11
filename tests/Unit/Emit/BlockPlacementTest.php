<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Emit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `@block` đặt sai chỗ hoặc đặt tên lạ phải là LỖI BIÊN DỊCH.
 *
 * Trước 2026-11-11: tên có gạch nối in ra màn hình thành chữ `@block('side-bar')` ở CSR;
 * block lồng trong thẻ, hoặc ở view không @extends, sinh JS sai cú pháp (`]);` giữa một mảng)
 * và chỉ lộ khi bundle.
 */
final class BlockPlacementTest extends TestCase
{
    private function compile(string $template): string
    {
        return (string) (new SaolaCompiler())->compile("<template>\n{$template}\n</template>\n", new CompileOptions(
            viewPath: 'test.block', functionName: 'V', factoryName: 'VFactory',
        ))->js;
    }

    public function test_block_o_cap_ngoai_cung_cua_view_ke_thua_thi_hop_le(): void
    {
        $js = $this->compile("@extends('layouts.app')\n@block('main')\n<p>a</p>\n@endblock");
        $this->assertStringContainsString("this.block('block-main', 'main'", $js);
    }

    public function test_ten_co_gach_noi_bi_tu_choi(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage("@block('side-bar')");
        $this->compile("@extends('layouts.app')\n@block('side-bar')\n<p>a</p>\n@endblock");
    }

    public function test_block_long_trong_the_bi_tu_choi(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('cấp ngoài cùng');
        $this->compile("@extends('layouts.app')\n@block('outer')\n<div>\n@block('main')\n<p>a</p>\n@endblock\n</div>\n@endblock");
    }

    public function test_block_o_view_khong_ke_thua_bi_tu_choi(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('@extends');
        $this->compile("@block('main')\n<p>a</p>\n@endblock");
    }
}
