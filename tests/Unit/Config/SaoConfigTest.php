<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Saola\Compiler\Config\SaoConfig;
use Saola\Compiler\Lang;

/**
 * Luật đặt tên và đường dẫn PHẢI khớp `compiler/src/index.js::processSaoFile`.
 *
 * Lệch một chữ là Node và `artisan sao:compile` ghi ra hai file khác nhau cho
 * cùng một view — và không cổng parity nào bắt được, vì bản Python không có
 * khái niệm config.
 */
final class SaoConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/saoconfig-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/resources/saola/web/views/pages', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, string> $views tên file → nội dung */
    private function project(array $views, bool $twoNamespaces = false): SaoConfig
    {
        $context = [
            'views' => ['web' => 'web/views'],
            'blade' => ['web' => 'web'],
            'compiled' => ['views' => 'web/views', 'registry' => 'web/registry.js'],
        ];

        if ($twoNamespaces) {
            mkdir($this->root . '/resources/saola/shop/views', 0o775, true);
            file_put_contents($this->root . '/resources/saola/shop/views/cart.sao', '<template><div></div></template>');
            $context['views']['shop'] = 'shop/views';
            $context['blade']['shop'] = 'shop';
        }

        file_put_contents($this->root . '/sao.config.json', json_encode([
            'paths' => [
                'saoView' => 'resources/saola',
                'bladeView' => 'resources/views',
                'compiled' => 'resources/js/saola',
            ],
            'contexts' => ['web' => $context, 'default' => $context],
        ]));

        foreach ($views as $name => $content) {
            $path = $this->root . '/resources/saola/web/views/' . $name;
            @mkdir(dirname($path), 0o775, true);
            file_put_contents($path, $content);
        }

        return SaoConfig::load($this->root);
    }

    public function test_suy_ten_giong_index_js(): void
    {
        $config = $this->project(['pages/hero-section.sao' => '<template><div></div></template>']);
        $view = $config->views('web')[0];

        $this->assertSame('web.pages.hero-section', $view->viewPath);
        $this->assertSame('HeroSection', $view->functionName, 'fn = PascalCase của đoạn CUỐI');
        $this->assertSame('WebPagesHeroSection', $view->factoryName, 'factory = PascalCase TOÀN BỘ đường dẫn');
    }

    public function test_pascal_giu_chu_hoa_ben_trong(): void
    {
        // `useState` → `UseState`, không phải `Usestate` (ucwords sẽ hạ chữ)
        $config = $this->project(['useState.sao' => '<template><div></div></template>']);

        $this->assertSame('UseState', $config->views('web')[0]->functionName);
    }

    public function test_duong_dan_dau_ra(): void
    {
        $config = $this->project(['pages/home.sao' => '<template><div></div></template>']);
        $view = $config->views('web')[0];

        // Dùng projectRoot chứ không phải $this->root: load() gọi realpath(),
        // mà trên macOS /var là symlink tới /private/var.
        $root = $config->projectRoot;

        $this->assertSame($root . '/resources/views/web/pages/home.blade.php', $view->bladeOutput);
        $this->assertSame($root . '/resources/js/saola/web/views/pages/home.js', $view->jsOutput);
    }

    public function test_nhieu_namespace_thi_chen_namespace_vao_duong_dan_js(): void
    {
        // index.js chỉ chèn namespace khi context có NHIỀU namespace — nếu
        // không sẽ đè file khi hai namespace trùng tên view
        $config = $this->project(['home.sao' => '<template><div></div></template>'], twoNamespaces: true);

        $root = $config->projectRoot;
        $paths = array_map(static fn ($v): string => $v->jsOutput, $config->views('web'));
        $this->assertContains($root . '/resources/js/saola/web/views/web/home.js', $paths);
        $this->assertContains($root . '/resources/js/saola/web/views/shop/cart.js', $paths);
    }

    public function test_lang_suy_tu_script_setup(): void
    {
        $config = $this->project([
            'plain.sao' => '<template><div></div></template>',
            'typed.sao' => '<script setup lang="ts">const a: number = 1;</script><template><div></div></template>',
            'plain-script.sao' => '<script setup>const a = 1;</script><template><div></div></template>',
        ]);

        $byName = [];
        foreach ($config->views('web') as $v) {
            $byName[$v->functionName] = $v;
        }

        $this->assertSame(Lang::Js, $byName['Plain']->lang);
        $this->assertSame(Lang::Ts, $byName['Typed']->lang);
        $this->assertSame(Lang::Js, $byName['PlainScript']->lang, '<script setup> không có lang = JS');
        $this->assertStringEndsWith('.ts', $byName['Typed']->jsOutput);
    }

    public function test_bo_qua_context_default(): void
    {
        // `default` không phải context thật — index.js cũng loại nó khi build all
        $config = $this->project(['a.sao' => '<template><div></div></template>']);

        $this->assertSame(['web'], $config->contextNames());
    }

    public function test_thu_tu_view_on_dinh(): void
    {
        $config = $this->project([
            'z.sao' => '<template><div></div></template>',
            'a.sao' => '<template><div></div></template>',
            'm.sao' => '<template><div></div></template>',
        ]);

        $names = array_map(static fn ($v): string => $v->functionName, $config->views('web'));
        $this->assertSame(['A', 'M', 'Z'], $names, 'Phải sắp xếp để build tái lập được');
    }

    public function test_khong_tim_thay_config_thi_nem(): void
    {
        $this->expectException(RuntimeException::class);
        SaoConfig::load('/');
    }

    public function test_context_khong_ton_tai_thi_nem(): void
    {
        $config = $this->project(['a.sao' => '<template><div></div></template>']);

        $this->expectException(RuntimeException::class);
        $config->views('khong-co');
    }
}
