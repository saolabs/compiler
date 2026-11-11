<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Emit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\SaolaCompiler;

/**
 * Dòng gộp của `@foreach` — `this.__fused(khuôn, đuôi id, parent, get)`.
 *
 * Client dựng cả dòng từ khuôn tĩnh và KHÔNG chạy lại gì ngoài các `case`. Thân
 * nào compiler không chắc mà vẫn gộp thì phần đó câm lặng biến mất (không
 * @include, không @if, không @bind) — nên mọi ca "không chắc" PHẢI giữ output cũ.
 */
final class ForeachFusedRowTest extends TestCase
{
    private function js(string $body, string $script = '', string $loop = "@foreach(rows as row)\n@key(row['id'])"): string
    {
        $source = "<template>\n<ul>\n{$loop}\n{$body}\n@endforeach\n</ul>\n</template>\n"
            ."<script setup>\n@state(rows = [], selected = 0)\n{$script}\n</script>\n";

        return (string) (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.fused', functionName: 'V', factoryName: 'VFactory',
        ))->js;
    }

    private function assertFused(string $js): void { $this->assertStringContainsString('this.__fused(', $js); }
    private function assertNotFused(string $js): void { $this->assertStringNotContainsString('__fused', $js); $this->assertStringNotContainsString('__fr', $js); }

    public function test_than_don_gian_duoc_gop_va_giu_nguyen_tham_so_memo(): void
    {
        $js = $this->js("<li data-id=\"{{ row['id'] }}\" @class({'on': selected === row['id']}) @click(pick(row['id']))><b class=\"x\">{{ row['label'] }}</b> tĩnh</li>");
        $this->assertFused($js);
        // Thân của dòng không còn lời gọi dựng cây nào.
        $this->assertSame(0, preg_match('/this\.__foreach\(.*this\.(html|output)\(/s', $js));
        // Khuôn: tag, hash id, binding attr/class kèm stateKeys, event, {{ }} và chữ tĩnh.
        $this->assertMatchesRegularExpression('/const __fr\d+ = \{ t: "li", i: "[^"]+", b: \[\["data-id", 0, \[\]\]\], kb: \[\["on", 1, \["selected"\]\]\], e: \[\["click", 2\]\], c: \[\{ t: "b", i: "[^"]+", k: "x", c: \[\{ o: "[^"]+", v: 3, s: \[\] \}\] \}, \' tĩnh\'\] \};/u', $js);
        $this->assertStringContainsString("case 1: return selected === row['id'];", $js);
        $this->assertStringContainsString("case 3: return row['label'];", $js);
        // Đuôi id theo @key; memo vẫn mức 1, state trong binding tự cập nhật không là phụ thuộc.
        $this->assertMatchesRegularExpression('/this\.__fused\(__fr\d+, `\$\{row\[\'id\'\]\}`, parentElement, \(__i\) =>/', $js);
        $this->assertMatchesRegularExpression('/true, "[^"]+", 1, \[\]\)/', $js);
    }

    public function test_id_trong_khuon_trung_voi_id_duong_thuong(): void
    {
        $body = "<li><span>{{ row['a'] }}</span><br><i>{{ row['b'] }}</i></li>";
        $fused = $this->js($body);
        putenv('SAOC_NO_FUSE=1');
        try { $plain = $this->js($body); } finally { putenv('SAOC_NO_FUSE'); }
        $this->assertFused($fused);
        $this->assertNotFused($plain);
        preg_match_all('/this\.(?:html|output)\(`([^-`]+)-\$\{row/', $plain, $regular);
        preg_match_all('/\b[io]: "([^"]+)"/', $fused, $inKhuon);
        $this->assertNotSame([], $regular[1]);
        $this->assertSame($regular[1], $inKhuon[1]);
        // Blade không đổi theo công tắc — hydrate dùng chung một DOM server.
        $this->assertSame(1, preg_match('/const __fr/', $fused));
    }

    public function test_state_bi_chup_trong_event_van_la_phu_thuoc_memo(): void
    {
        $js = $this->js("<li @click(pick(row['id'], selected))>{{ row['label'] }}</li>");
        $this->assertFused($js);
        $this->assertMatchesRegularExpression('/true, "[^"]+", 1, \["selected"\]\)/', $js);
    }

    public function test_modifier_cua_event_di_theo_khuon(): void
    {
        $js = $this->js("<li><a @click.prevent(pick(row['id']))>{{ row['label'] }}</a></li>");
        $this->assertFused($js);
        $this->assertStringContainsString('e: [["click", 0, ["prevent"]]]', $js);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function khongGop(): iterable
    {
        $loop = "@foreach(rows as row)\n@key(row['id'])";
        yield '@if trong dòng' => ["<li>@if(row['a'])<b>x</b>@endif</li>", '', $loop];
        yield '@include trong dòng' => ["<li>@include('x.y', ['row' => row])</li>", '', $loop];
        yield 'vòng lặp lồng' => ["<li>@foreach(row['items'] as it)<i>{{ it }}</i>@endforeach</li>", '', $loop];
        yield '@bind' => ["<li><input @bind(selected)></li>", '', $loop];
        yield 'ô nhập' => ["<li><input value=\"{{ row['a'] }}\"></li>", '', $loop];
        yield 'echo thô' => ["<li>{!! row['html'] !!}</li>", '', $loop];
        yield 'class nội suy' => ["<li class=\"a-{{ row['kind'] }}\">x</li>", '', $loop];
        yield '{{ }} ở gốc dòng' => ["{{ row['label'] }}<li>x</li>", '', $loop];
        yield 'biến ngoài danh sách trắng (memo 0)' => ["<li>{{ row['a'] + offset }}</li>", 'let offset = 1;', $loop];
        yield 'danh sách không phải state' => ["<li>{{ row }}</li>", '', "@foreach([1, 2, 3] as row)"];
    }

    /** @dataProvider khongGop */
    public function test_than_khong_chac_giu_nguyen_output_cu(string $body, string $script, string $loop): void
    {
        $js = $this->js($body, $script, $loop);
        $this->assertNotFused($js);
        putenv('SAOC_NO_FUSE=1');
        try { $plain = $this->js($body, $script, $loop); } finally { putenv('SAOC_NO_FUSE'); }
        $this->assertSame($plain, $js);
    }

    public function test_vong_ngoai_van_tinh_memo_dung_khi_vong_trong_duoc_gop(): void
    {
        // Vòng trong gộp; state `selected` chỉ nằm trong binding tự cập nhật của nó
        // nên KHÔNG được thành phụ thuộc của vòng ngoài.
        $body = "<li>@foreach(rows as inner)\n@key(inner['id'])\n<i @class({'on': selected === inner['id']})>{{ inner['label'] }}</i>\n@endforeach</li>";
        $js = $this->js($body, '', "@foreach(groups as row)\n@key(row['id'])");
        putenv('SAOC_NO_FUSE=1');
        try { $plain = $this->js($body, '', "@foreach(groups as row)\n@key(row['id'])"); } finally { putenv('SAOC_NO_FUSE'); }
        preg_match_all('/true, "[^"]+", (\d(?:, \[[^\]]*\])?)\)/', $js, $a);
        preg_match_all('/true, "[^"]+", (\d(?:, \[[^\]]*\])?)\)/', $plain, $b);
        $this->assertSame($b[1], $a[1]);
    }
}
