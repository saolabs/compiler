<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Directive;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Directive\DirectiveRegistry;
use Saola\Compiler\Preprocessor\ExpressionTransformer;
use Saola\Compiler\SaolaCompiler;

/**
 * Ba tầng directive (docs/03-directives.md §3).
 *
 * Không cổng parity nào phủ được: bản Python không có registry, nên không có
 * gì để đối chiếu. Luật phân tầng là chính sách của bản PHP.
 */
final class DirectiveRegistryTest extends TestCase
{
    public function test_dang_ky_directive_moi_va_phat_hai_dich(): void
    {
        $registry = DirectiveRegistry::builtins();
        $registry->directive('money', static fn (string $e): array => [
            'blade' => "{{ number_format({$e}) }}",
            'js' => "fmtMoney({$e})",
        ]);

        $this->assertSame('{{ number_format(price) }}', $registry->transform('@money(price)', 'blade'));
        $this->assertSame('fmtMoney(price)', $registry->transform('@money(price)', 'js'));
    }

    public function test_tang_T0_khong_the_ghi_de(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/T0/');
        DirectiveRegistry::builtins()->directive('foreach', static fn (): array => ['blade' => '', 'js' => '']);
    }

    public function test_tang_T1_can_override_tuong_minh(): void
    {
        $registry = DirectiveRegistry::builtins();
        $handler = static fn (string $e): array => ['blade' => 'B', 'js' => 'J'];

        try {
            $registry->directive('include', $handler);
            $this->fail('Phải từ chối khi thiếu override');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('T1', $e->getMessage());
        }

        // Có override thì cho qua — người dùng đã nói rõ ý định
        $registry->directive('include', $handler, override: true);
        $this->assertSame('B', $registry->transform('@include(x)', 'blade'));
    }

    public function test_dich_khong_hop_le_bi_tu_choi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DirectiveRegistry::builtins()->transform('@x(1)', 'python');
    }

    public function test_khong_dung_vao_ten_dai_hon(): void
    {
        $registry = DirectiveRegistry::builtins();
        $registry->directive('money', static fn (string $e): array => ['blade' => 'M', 'js' => 'M']);

        // @moneyBag KHÔNG phải @money — bắt tiền tố là lỗi kinh điển
        $this->assertSame('@moneyBag(x)', $registry->transform('@moneyBag(x)', 'blade'));
    }

    /**
     * MỌI directive compiler xử lý đều phải nằm trong một tầng.
     *
     * 32 directive (@class, @style, @attr, @bind, mọi @click/...) từng KHÔNG
     * nằm trong tầng nào: đăng ký đè được mà không cần cờ, không cảnh báo. Đè
     * @class làm mất class điều kiện và chèn thêm `'X' => true`, im lặng.
     */
    public function test_moi_directive_compiler_xu_ly_deu_thuoc_mot_tang(): void
    {
        $rc = new \ReflectionClass(DirectiveRegistry::class);
        $tier = $rc->getMethod('elementTier');
        $tier->setAccessible(true);

        $covered = array_merge(
            array_keys($rc->getConstant('LOCKED')),
            array_keys($rc->getConstant('CORE')),
            array_keys($tier->invoke(null)),
        );

        $handled = array_unique(array_merge(
            ExpressionTransformer::EVENT_DIRECTIVES,
            ExpressionTransformer::BIND_DIRECTIVES,
            ExpressionTransformer::ELEMENT_DIRECTIVES,
            ['switch', 'case', 'section', 'block', 'yield'],
            array_keys(ExpressionTransformer::VIEW_PATH_DIRECTIVES),
            ['foreach', 'for', 'if', 'elseif', 'while', 'let', 'const'],
        ));

        $this->assertSame([], array_values(array_diff($handled, $covered)));
    }

    public function test_directive_element_can_override_tuong_minh(): void
    {
        foreach (['class', 'style', 'attr', 'bind', 'click', 'checked'] as $name) {
            try {
                DirectiveRegistry::builtins()->directive($name, static fn (): array => ['blade' => '', 'js' => '']);
                $this->fail("@{$name} phải bị chặn khi thiếu override");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('T1', $e->getMessage());
            }

            // Có cờ thì cho qua — người dùng đã nhận trách nhiệm
            DirectiveRegistry::builtins()->directive(
                $name,
                static fn (): array => ['blade' => '', 'js' => ''],
                override: true,
            );
        }
    }

    /**
     * `@verbatim` và comment Blade giữ NGUYÊN VĂN.
     *
     * Không che thì trang docs in ví dụ `@money(2)` bị chính directive @money
     * của người dùng viết lại — tài liệu hiện ra thứ khác thứ nó đang mô tả.
     */
    public function test_khong_thay_directive_trong_verbatim_va_comment(): void
    {
        $registry = DirectiveRegistry::builtins();
        $registry->directive('money', static fn (string $e): array => [
            'blade' => "TIEN({$e})",
            'js' => "tien({$e})",
        ]);

        $source = "<p>@money(1)</p>\n{{-- @money(3) --}}\n@verbatim @money(2) @endverbatim";
        $out = $registry->transform($source, 'blade');

        $this->assertStringContainsString('TIEN(1)', $out, 'ngoài vùng che thì phải đổi');
        $this->assertStringNotContainsString('TIEN(2)', $out, '@verbatim phải giữ nguyên');
        $this->assertStringNotContainsString('TIEN(3)', $out, 'comment Blade phải giữ nguyên');
    }

    public function test_directive_nguoi_dung_chay_qua_ca_pipeline(): void
    {
        $compiler = new SaolaCompiler();
        $compiler->directives()->directive('shout', static fn (string $e): array => [
            'blade' => "{{ strtoupper({$e}) }}",
            'js' => "String({$e}).toUpperCase()",
        ]);

        $result = $compiler->compile(
            "@states({ msg: 'a' })\n<template><div>@shout(msg)</div></template>\n",
            new CompileOptions(viewPath: 't.v', functionName: 'V', factoryName: 'VF'),
        );

        $this->assertStringContainsString('strtoupper', (string) $result->blade);
        $this->assertStringContainsString('toUpperCase', (string) $result->js);
    }
}
