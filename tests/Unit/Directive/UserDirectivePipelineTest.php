<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Directive;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Directive\DirectiveFiles;
use Saola\Compiler\Directive\DirectiveRegistry;
use Saola\Compiler\Directive\Sao;
use Saola\Compiler\Hydration\MarkerParity;
use Saola\Compiler\SaolaCompiler;

/**
 * Directive của ứng dụng/thư viện: "sao in, sao out" (docs/03-directives.md §4).
 *
 * Fixture là OUTPUT THẬT của compiler — chuỗi viết tay mù với khâu sinh code,
 * đúng lỗ đã để lọt `this.text('String(msg).toUpperCase()')` (chữ tĩnh, lệch
 * marker với Blade). Sinh lại: SAOC_UPDATE_FIXTURES=1 vendor/bin/phpunit --filter UserDirectivePipeline
 */
final class UserDirectivePipelineTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/user-directives';

    private function compiler(): SaolaCompiler
    {
        $registry = DirectiveRegistry::builtins();
        DirectiveFiles::load($registry, self::FIXTURE . '/sao.directives.php');

        return new SaolaCompiler($registry);
    }

    private function compile(string $source, ?SaolaCompiler $compiler = null): \Saola\Compiler\CompileResult
    {
        return ($compiler ?? $this->compiler())->compile(
            $source,
            new CompileOptions(viewPath: 'fixture.user-directives', functionName: 'V', factoryName: 'VF'),
        );
    }

    public function test_fixture_output_that_va_marker_hai_phia_khop(): void
    {
        $result = $this->compile((string) file_get_contents(self::FIXTURE . '/input.sao'));
        $blade = (string) $result->blade;
        $js = (string) $result->js;

        if (getenv('SAOC_UPDATE_FIXTURES')) {
            file_put_contents(self::FIXTURE . '/expected.blade', $blade);
            file_put_contents(self::FIXTURE . '/expected.js', $js);
        }
        $this->assertSame((string) file_get_contents(self::FIXTURE . '/expected.blade'), $blade);
        $this->assertSame((string) file_get_contents(self::FIXTURE . '/expected.js'), $js);

        $this->assertSame(['blade' => [], 'js' => []], MarkerParity::diff($blade, $js));
        // Directive phải ra marker động thật, không phải chữ tĩnh: @twice ở hai
        // chỗ là hai output, #when/@guard là reactive if.
        $outputs = array_filter(MarkerParity::jsIds($js), static fn (string $id): bool => (bool) preg_match('/o\d+$/', $id));
        $this->assertGreaterThanOrEqual(3, count($outputs));
        $this->assertSame(2, substr_count($blade, "'type' => 'if'"));
        $this->assertStringContainsString("@attr(['data-tip' => \$n, 'aria-busy' => 'true'])", $blade);
        $this->assertStringContainsString("@attr(['data-tip' => \$item])", $blade);
        $this->assertStringContainsString("'title' => 'xem #when'", $blade);
        $this->assertStringNotContainsString('#tip', $blade . $js);
    }

    public function test_hai_dich_khac_nhau_nhung_lech_marker_thi_bao_loi(): void
    {
        $compiler = $this->compiler();
        $compiler->directives()->directive('odd', static fn (string $e): array => [
            'blade' => "{{ {$e} }}",
            'js' => 'tĩnh',
        ]);

        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/lệch id hydrate/');
        $this->compile("@states({ n: 1 })\n<template><p>@odd(n)</p></template>\n", $compiler);
    }

    public function test_hai_dich_khac_nhau_cung_marker_thi_cho_qua(): void
    {
        $compiler = $this->compiler();
        $compiler->directives()->directive('env', static fn (): array => ['blade' => 'máy chủ', 'js' => 'trình duyệt']);

        $result = $this->compile("<template><p>@env(1)</p></template>\n", $compiler);
        $this->assertStringContainsString('máy chủ', (string) $result->blade);
        $this->assertStringContainsString('trình duyệt', (string) $result->js);
    }

    public function test_bo_directive_buoc_va_dung_sai_gia_tri(): void
    {
        foreach ([
            '<template><b #when>x</b></template>' => 'thiếu biểu thức',
            '<template><b #busy="1">x</b></template>' => 'không nhận giá trị',
            '<template><b #when="a" #if="b">x</b></template>' => 'cả `#when` lẫn `#if`',
            '<template><b #when="a">x</template>' => 'thiếu `</b>`',
            '<template><b #nope="a">x</b></template>' => '`#nope`',
        ] as $source => $message) {
            try {
                $this->compile($source);
                $this->fail("Phải báo lỗi: {$source}");
            } catch (CompileException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $source);
            }
        }

        $registry = DirectiveRegistry::builtins();
        $registry->tag('a', static fn (): array => ['', '']);
        $registry->tag('b', static fn (): array => ['', '']);
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('hai directive bọc');
        $registry->transform('<i #a="1" #b="2">x</i>', 'blade');
    }

    public function test_dang_ky_tag_theo_tang(): void
    {
        foreach (['if', 'foreach', 'key', 'else'] as $builtin) {
            try {
                DirectiveRegistry::builtins()->tag($builtin, static fn (): array => ['', ''], override: true);
                $this->fail("#{$builtin} phải bị khoá");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('T0', $e->getMessage());
            }
        }

        $registry = DirectiveRegistry::builtins()->attr('tip', static fn (): string => 'a');
        try {
            $registry->add(Sao::attr('tip', static fn (): string => 'b'));
            $this->fail('Hai thư viện trùng #tip phải báo lỗi');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('đã được khai báo', $e->getMessage());
        }
        $registry->add(Sao::attr('tip', static fn (): string => 'data-b', override: true));
        $this->assertSame('<i data-b>x</i>', $registry->transform('<i #tip="1">x</i>', 'js'));
    }

    public function test_vung_nguyen_van_khong_bi_ha(): void
    {
        $registry = DirectiveRegistry::builtins()->attr('tip', static fn (): string => 'HA');
        $source = "{{-- <i #tip=\"1\"> --}}@verbatim<i #tip=\"2\">@endverbatim<script>'<i #tip=\"3\">'</script>{{ '<i #tip=\"4\">' }}";

        $this->assertSame($source, $registry->transform($source, 'blade'));
    }

    public function test_dò_directives_cua_app_va_package(): void
    {
        $root = sys_get_temp_dir() . '/saoc-discover-' . bin2hex(random_bytes(4));
        mkdir($root . '/vendor/composer', 0777, true);
        mkdir($root . '/vendor/acme/ui', 0777, true);
        mkdir($root . '/vendor/acme/plain', 0777, true);
        file_put_contents($root . '/vendor/acme/ui/ui.directives.php', "<?php return [\\Saola\\Compiler\\Directive\\Sao::attr('ui', fn () => 'data-ui', value: false)];");
        file_put_contents($root . '/sao.directives.php', "<?php return [\\Saola\\Compiler\\Directive\\Sao::directive('app', fn () => 'APP')];");
        file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'acme/ui', 'install-path' => '../acme/ui', 'extra' => ['saola' => ['directives' => 'ui.directives.php']]],
            ['name' => 'acme/plain', 'install-path' => '../acme/plain'],
        ]]));

        try {
            $files = DirectiveFiles::resolve($root);
            $this->assertSame([realpath($root . '/vendor/acme/ui/ui.directives.php'), realpath($root . '/sao.directives.php')], $files);

            $registry = DirectiveRegistry::builtins();
            foreach ($files as $file) {
                DirectiveFiles::load($registry, $file);
            }
            $this->assertSame('<i data-ui>APP</i>', $registry->transform('<i #ui>@app(1)</i>', 'blade'));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
