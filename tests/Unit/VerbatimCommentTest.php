<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `@verbatim` nghĩa là "in nguyên văn" — kể cả `{{-- … --}}` nằm bên trong.
 *
 * Bản cũ che comment TRƯỚC verbatim trong `ExpressionTransformer::transformTemplate`,
 * nên comment trong khối verbatim thành `__BLADE_COMMENT_n__` rồi bị cả khối
 * verbatim cất vào mảng lưu; vòng khôi phục quét `$result` không còn placeholder
 * để thay, và chuỗi `__BLADE_COMMENT_0__` rò thẳng ra output.
 *
 * Đo trên /demo/setup ngày 06/09/2026: dòng comment trong khối code minh hoạ
 * biến mất ở CẢ Blade lẫn JS. Trang tài liệu là nơi dính nặng nhất — nó tồn tại
 * để in ra cú pháp Saola, mà cú pháp đó có comment.
 */
final class VerbatimCommentTest extends TestCase
{
    private const SOURCE = <<<'SAO'
    <template>
        <pre><code>
    @verbatim
    GIU_NGUYEN
    {{-- COMMENT_TRONG_VERBATIM --}}
    @endverbatim
        </code></pre>
    </template>
    SAO;

    private function compile(string $source): object
    {
        return (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.verbatim',
            functionName: 'VerbatimView',
            factoryName: 'VerbatimViewFactory',
        ));
    }

    public function test_comment_trong_verbatim_ra_nguyen_van_o_ca_hai_dau_ra(): void
    {
        $result = $this->compile(self::SOURCE);

        foreach (['blade' => (string) $result->blade, 'js' => (string) $result->js] as $target => $output) {
            self::assertStringContainsString('GIU_NGUYEN', $output, "{$target}: mất nội dung verbatim");
            self::assertStringContainsString(
                'COMMENT_TRONG_VERBATIM',
                $output,
                "{$target}: comment trong @verbatim bị nuốt",
            );
            self::assertStringNotContainsString(
                '__BLADE_COMMENT',
                $output,
                "{$target}: placeholder nội bộ rò ra output",
            );
        }
    }

    public function test_verbatim_trong_comment_khong_ro_placeholder(): void
    {
        // Chiều ngược lại của cùng một lỗi lồng nhau. Che hai lượt riêng thì
        // chiều nào che sau cũng nuốt placeholder của chiều kia; quét xen kẽ một
        // lượt thì khối NGOÀI luôn khớp trước.
        $result = $this->compile(<<<'SAO'
        <template>
            <p>truoc</p>
            {{-- @verbatim VI_DU_BI_COMMENT @endverbatim --}}
            <p>sau</p>
        </template>
        SAO);

        foreach (['blade' => (string) $result->blade, 'js' => (string) $result->js] as $target => $output) {
            self::assertStringNotContainsString('__SAO_MASKED', $output, "{$target}: placeholder rò ra output");
            self::assertStringNotContainsString('__VERBATIM_RAW', $output, "{$target}: placeholder cũ rò ra output");
        }
    }

    public function test_comment_ngoai_verbatim_van_bi_bo_nhu_cu(): void
    {
        // Ngoài verbatim thì `{{-- --}}` vẫn là comment Blade thật: không được
        // ra HTML. Đảo thứ tự che không được làm hỏng hành vi này.
        $result = $this->compile(<<<'SAO'
        <template>
            <p>truoc</p>
            {{-- COMMENT_NGOAI_VERBATIM --}}
            <p>sau</p>
        </template>
        SAO);

        self::assertStringContainsString('truoc', (string) $result->js);
        self::assertStringNotContainsString('COMMENT_NGOAI_VERBATIM', (string) $result->js);
    }
}
