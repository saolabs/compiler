<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * `<style lang="...">` — compiler không biên dịch preprocessor nào.
 *
 * Trước đây bỏ qua lặng lẽ: SCSS thô vào styles[].content, runtime nhét vào thẻ
 * <style lang="scss">, trình duyệt bỏ qua — không lỗi, không cảnh báo. Mà
 * ScopedStyle::apply() lại đã kịp viết lại selector top-level nên đầu ra không
 * còn là SCSS hợp lệ. Thà chết lúc biên dịch.
 */
final class StyleLangTest extends TestCase
{
    private function compile(string $source, string $viewPath = 'web.stylelang'): \Saola\Compiler\CompileResult
    {
        return (new SaolaCompiler())->compile($source, new CompileOptions(viewPath: $viewPath));
    }

    public static function preprocessors(): iterable
    {
        yield 'scss' => ['scss'];
        yield 'sass' => ['sass'];
        yield 'less' => ['less'];
        yield 'stylus' => ['stylus'];
        yield 'hoa chữ' => ['SCSS'];
    }

    #[DataProvider('preprocessors')]
    public function test_preprocessor_language_is_rejected_at_compile_time(string $lang): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('không được hỗ trợ');
        $this->compile(
            '<template><div class="card">x</div></template>'
            ."\n<style lang=\"{$lang}\" scoped>.card { color: red; }</style>"
        );
    }

    public function test_error_names_the_view(): void
    {
        try {
            $this->compile(
                '<template><div>x</div></template>'."\n".'<style lang="scss">.a { color: red; }</style>',
                'web.thecard'
            );
            self::fail('phải ném CompileException');
        } catch (CompileException $e) {
            self::assertStringContainsString('web.thecard', $e->getMessage());
            self::assertStringContainsString('lang="scss"', $e->getMessage());
        }
    }

    public function test_plain_css_still_compiles_and_lang_never_reaches_the_dom(): void
    {
        foreach (['<style scoped>', '<style lang="css" scoped>', '<style>'] as $open) {
            $r = $this->compile(
                '<template><div class="card">x</div></template>'
                ."\n{$open}.card { color: red; }</style>"
            );
            self::assertStringContainsString('color: red', (string) $r->js, $open);
            // `lang` là chỉ thị compiler, không phải attribute DOM — như `scoped`.
            self::assertStringNotContainsString('"lang"', (string) $r->js, $open);
        }
    }

    public function test_example_inside_a_comment_is_not_a_real_style_block(): void
    {
        // Trang docs in `<style lang="scss">` ra làm ví dụ vẫn phải compile được.
        $r = $this->compile(
            '<template><div>x</div></template>'
            ."\n{{-- <style lang=\"scss\">.a { color: red; }</style> --}}"
            ."\n<style scoped>.card { color: blue; }</style>"
        );
        self::assertStringContainsString('color: blue', (string) $r->js);
    }

    public function test_native_css_nesting_survives_scoping(): void
    {
        // Lý do bảo "không cần preprocessor": nesting native đã chạy, và
        // ScopedStyle chỉ scope selector top-level — selector lồng đã bị nó bao.
        $r = $this->compile(
            '<template><div class="card"><span class="title">x</span></div></template>'
            ."\n<style scoped>.card { color: red; & .title { font-weight: 700; } }</style>"
        );
        $js = (string) $r->js;
        self::assertMatchesRegularExpression('/\.card\.s[0-9a-f]+/', $js);
        self::assertStringContainsString('& .title', $js);
    }
}
