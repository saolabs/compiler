<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileException;
use Saola\Compiler\Support\Html;

/**
 * Directive viết trên thẻ (`#if` `#elseif` `#else` `#switch` `#case` `#default`
 * `#foreach` `#for` `#while` `#key`), hạ về directive khối tương ứng.
 *
 * Hàm thuần nên test bằng bảng chuỗi vào/ra. Bất biến quan trọng nhất KHÔNG
 * nằm ở đây mà ở chỗ gọi: bước hạ phải chạy TRƯỚC `transformDirectives` để
 * biểu thức đi qua cùng phép dịch JS→PHP như `@if` viết tay — thiếu thì Blade
 * ra `@if(a)` thay vì `@if($a)` và PHP nổ vì hằng không tồn tại.
 *
 * Xem docs/SAO_ELEMENT_DIRECTIVES_RFC.md.
 */
final class HtmlExpandTagDirectivesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function truongHopHopLe(): iterable
    {
        yield 'thẻ đơn' => [
            '<a href="#" #if="cond">A</a>',
            '@if(cond)<a href="#">A</a>@endif',
        ];

        yield 'chuỗi ba thẻ khác tên' => [
            "<a #if=\"a\">A</a>\n<p #elseif=\"b\">B</p>\n<span #else>C</span>",
            "@if(a)<a>A</a>\n@elseif(b)<p>B</p>\n@else<span>C</span>@endif",
        ];

        // Cây con giữ nguyên từng byte — bước hạ chỉ chèn quanh biên thẻ.
        yield 'thẻ bao nhiều con' => [
            "<div class=\"card\" #if=\"c\">\n  <div class=\"head\"><h3>T</h3></div>\n</div>",
            "@if(c)<div class=\"card\">\n  <div class=\"head\"><h3>T</h3></div>\n</div>@endif",
        ];

        // Quét tiếp từ SAU thẻ mở (không nhảy qua cả element) nên `#` lồng
        // trong `#` tự đúng, không cần đệ quy riêng.
        yield 'lồng nhau' => [
            '<div #if="a"><p #if="b">x</p></div>',
            '@if(a)<div>@if(b)<p>x</p>@endif</div>@endif',
        ];

        yield 'thẻ rỗng không cần thẻ đóng' => [
            '<img src="x.png" #if="a">',
            '@if(a)<img src="x.png">@endif',
        ];

        yield 'thẻ tự đóng' => [
            '<br #if="a" />',
            '@if(a)<br />@endif',
        ];

        // Cùng bẫy §8② mà joinMultilineOpenTags và splitInlineDirectives đã vấp:
        // '>' trong ngoặc là toán tử so sánh, không phải dấu đóng thẻ.
        yield 'dấu lớn hơn trong ngoặc không đóng thẻ' => [
            '<div @if(x>0) #if="a">y</div>',
            '@if(a)<div @if(x>0)>y</div>@endif',
        ];

        // `{{ '</div>' }}` là thẻ đóng GIẢ. splitInlineDirectives không che
        // `{{ }}`, mà bước này chạy trước nó, nên phải tự che.
        yield 'thẻ đóng giả trong biểu thức' => [
            '<div #if="a">{{ "</div>" }}</div>',
            '@if(a)<div>{{ "</div>" }}</div>@endif',
        ];

        // Bước hạ chạy TRƯỚC joinMultilineOpenTags nên phải tự đi qua xuống
        // dòng trong thẻ mở. Đổi thứ tự mà quên ca này là hỏng câm.
        yield 'thẻ mở trải nhiều dòng' => [
            "<a\n    href=\"#\"\n    #if=\"a && n > 0\"\n    data-x=\"1\">A</a>",
            "@if(a && n > 0)<a\n    href=\"#\"\n    data-x=\"1\">A</a>@endif",
        ];

        yield 'không có dấu thăng là no-op' => [
            '<div class="x">y</div>',
            '<div class="x">y</div>',
        ];

        yield 'chuỗi sau thẻ rỗng' => [
            '<img #if="a"><p #elseif="b">B</p>',
            '@if(a)<img>@elseif(b)<p>B</p>@endif',
        ];

        yield 'chuỗi lồng trong nhánh else' => [
            '<div #if="a">x</div><div #else><p #if="c">y</p></div>',
            '@if(a)<div>x</div>@else<div>@if(c)<p>y</p>@endif</div>@endif',
        ];

        yield 'thẻ cùng tên lồng nhau' => [
            '<div #if="a"><div>trong</div>sau</div>',
            '@if(a)<div><div>trong</div>sau</div>@endif',
        ];

        yield 'nháy đơn trong điều kiện' => [
            '<div #if="a === \'x\'">y</div>',
            '@if(a === \'x\')<div>y</div>@endif',
        ];

        yield 'bình luận blade giữ nguyên' => [
            '{{-- <div #foo="a">x</div> --}}',
            '{{-- <div #foo="a">x</div> --}}',
        ];

        // @verbatim là văn bản nguyên văn: trang docs in ví dụ `#if` phải giữ
        // nguyên, kể cả khi tên directive không hợp lệ.
        yield 'verbatim giữ nguyên' => [
            '@verbatim <div #foo="a">y</div> @endverbatim',
            '@verbatim <div #foo="a">y</div> @endverbatim',
        ];

        yield 'ruột script không bị quét' => [
            '<script>var s = "<div #if=\'a\'>";</script>',
            '<script>var s = "<div #if=\'a\'>";</script>',
        ];

        // `#switch` là ca DUY NHẤT không bọc chính thẻ mang nó — thẻ cha vẫn
        // render, chỉ ruột được bọc. `@break` do compiler tự chèn.
        //
        // Không được chèn khoảng trắng nào giữa `@switch` và `@case` đầu tiên:
        // nội dung lạc ở đó bị Blade render còn JS bỏ hẳn, không cảnh báo.
        yield 'switch ba nhánh' => [
            "<div class=\"tabs\" #switch=\"sel\">\n    <p #case=\"'a'\">A</p>\n    <p #case=\"2\">Hai</p>\n    <span #default>Khác</span>\n</div>",
            '<div class="tabs">@switch(sel)@case(\'a\')<p>A</p>@break@case(2)<p>Hai</p>@break@default<span>Khác</span>@endswitch</div>',
        ];

        // Nhánh cuối không cần `@break` — khớp cách viết tay của `@switch`.
        yield 'switch một nhánh' => [
            '<div #switch="s"><p #case="1">A</p></div>',
            '<div>@switch(s)@case(1)<p>A</p>@endswitch</div>',
        ];

        yield 'case là thẻ rỗng' => [
            '<div #switch="s"><img #case="1" src="x"><p #default>d</p></div>',
            '<div>@switch(s)@case(1)<img src="x">@break@default<p>d</p>@endswitch</div>',
        ];

        yield 'if lồng trong case' => [
            '<div #switch="s"><div #case="1"><p #if="z">z</p></div></div>',
            '<div>@switch(s)@case(1)<div>@if(z)<p>z</p>@endif</div>@endswitch</div>',
        ];

        yield 'switch lồng switch' => [
            '<div #switch="s"><div #case="1"><div #switch="t"><p #case="2">x</p></div></div></div>',
            '<div>@switch(s)@case(1)<div><div>@switch(t)@case(2)<p>x</p>@endswitch</div></div>@endswitch</div>',
        ];

        // Nhóm lặp bọc thẻ y như `#if`, không có chuỗi nhánh.
        yield 'foreach' => [
            '<li class="row" #foreach="rows as row">x</li>',
            '@foreach(rows as row)<li class="row">x</li>@endforeach',
        ];

        // `@key` là directive RIÊNG đứng trong khối lặp, không phải tham số của
        // `@foreach` — nên phát thành một directive nữa ngay sau nó.
        yield 'foreach kèm key' => [
            '<li #foreach="rows as row" #key="row[\'id\']">x</li>',
            '@foreach(rows as row)@key(row[\'id\'])<li>x</li>@endforeach',
        ];

        // Thứ tự viết không quan trọng.
        yield 'key đứng trước foreach' => [
            '<li #key="row[\'id\']" #foreach="rows as row">x</li>',
            '@foreach(rows as row)@key(row[\'id\'])<li>x</li>@endforeach',
        ];

        yield 'for' => [
            '<p #for="i = 0; i < n; i++">x</p>',
            '@for(i = 0; i < n; i++)<p>x</p>@endfor',
        ];

        yield 'while' => [
            '<p #while="i < n">x</p>',
            '@while(i < n)<p>x</p>@endwhile',
        ];

        yield 'if lồng trong foreach' => [
            '<li #foreach="rows as row"><b #if="row[\'on\']">on</b></li>',
            '@foreach(rows as row)<li>@if(row[\'on\'])<b>on</b>@endif</li>@endforeach',
        ];

        // ── Nhiều directive trên một thẻ ──────────────────────────────────
        // Thứ tự bọc (ngoài → trong): #case/#default > #elseif/#else > loop > #if > #switch.
        // Thứ tự viết trên thẻ không quan trọng.

        // Loop bọc `#if`: `#if` lọc từng phần tử, dùng được biến lặp.
        yield 'foreach + key + if: loop bọc if' => [
            '<li #if="!p.archived" #foreach="rows as p" #key="p.id">x</li>',
            '@foreach(rows as p)@key(p.id)@if(!p.archived)<li>x</li>@endif@endforeach',
        ];

        yield 'if + switch: if bọc switch' => [
            '<div #switch="s" #if="a"><p #case="1">A</p></div>',
            '@if(a)<div>@switch(s)@case(1)<p>A</p>@endswitch</div>@endif',
        ];

        // `#if` không có loop vẫn là đầu chuỗi: thẻ anh em `#else` nối được.
        yield 'if + switch vẫn nối chuỗi else' => [
            '<div #if="a" #switch="s"><p #case="1">A</p></div><p #else>B</p>',
            '@if(a)<div>@switch(s)@case(1)<p>A</p>@endswitch</div>@else<p>B</p>@endif',
        ];

        yield 'foreach + switch: loop bọc switch' => [
            '<div #foreach="rows as r" #switch="r.kind"><p #case="1">A</p></div>',
            '@foreach(rows as r)<div>@switch(r.kind)@case(1)<p>A</p>@endswitch</div>@endforeach',
        ];

        yield 'foreach + if + switch' => [
            '<div #foreach="rows as r" #if="r.ok" #switch="r.kind"><p #case="1">A</p></div>',
            '@foreach(rows as r)@if(r.ok)<div>@switch(r.kind)@case(1)<p>A</p>@endswitch</div>@endif@endforeach',
        ];

        // `#elseif`/`#else` bọc loop để còn nối với thẻ anh em.
        yield 'else bọc foreach' => [
            '<p #if="empty">Trống</p><li #else #foreach="rows as r" #key="r.id">x</li>',
            '@if(empty)<p>Trống</p>@else@foreach(rows as r)@key(r.id)<li>x</li>@endforeach@endif',
        ];

        yield 'elseif bọc foreach + switch' => [
            '<p #if="a">A</p><div #elseif="b" #foreach="rows as r" #switch="r"><i #default>d</i></div>',
            '@if(a)<p>A</p>@elseif(b)@foreach(rows as r)<div>@switch(r)@default<i>d</i>@endswitch</div>@endforeach@endif',
        ];

        // `#case` bọc ngoài cùng.
        yield 'case + if' => [
            '<div #switch="s"><p #if="z" #case="1">A</p><p #default>D</p></div>',
            '<div>@switch(s)@case(1)@if(z)<p>A</p>@endif@break@default<p>D</p>@endswitch</div>',
        ];

        yield 'case + foreach + key' => [
            '<ul #switch="s"><li #case="1" #foreach="rows as r" #key="r.id">x</li></ul>',
            '<ul>@switch(s)@case(1)@foreach(rows as r)@key(r.id)<li>x</li>@endforeach@endswitch</ul>',
        ];

        yield 'case + for' => [
            '<ul #switch="s"><li #case="1" #for="i = 0; i < n; i++">x</li></ul>',
            '<ul>@switch(s)@case(1)@for(i = 0; i < n; i++)<li>x</li>@endfor@endswitch</ul>',
        ];

        yield 'case + switch lồng' => [
            '<div #switch="s"><div #case="1" #switch="t"><p #case="2">x</p></div></div>',
            '<div>@switch(s)@case(1)<div>@switch(t)@case(2)<p>x</p>@endswitch</div>@endswitch</div>',
        ];

        yield 'default + foreach + if + switch' => [
            '<div #switch="s"><div #default #foreach="rows as r" #if="r.ok" #switch="r.k"><b #case="1">x</b></div></div>',
            '<div>@switch(s)@default@foreach(rows as r)@if(r.ok)<div>@switch(r.k)@case(1)<b>x</b>@endswitch</div>@endif@endforeach@endswitch</div>',
        ];
    }

    /**
     * `#` chỉ là directive khi đứng ở VỊ TRÍ TÊN THUỘC TÍNH.
     *
     * Bản đầu dùng regex `\s+#([a-zA-Z][\w-]*)` quét cả thẻ mở, nên
     * `style="color: #fff"` — thứ có mặt khắp nơi — ném thẳng lỗi biên dịch.
     * Phải quét có nhận biết nháy và ngoặc.
     *
     * @return iterable<string, array{string}>
     */
    public static function dauThangKhongPhaiDirective(): iterable
    {
        yield 'màu trong style' => ['<div style="color: #fff">x</div>'];
        yield 'màu nháy đơn' => ["<div style='color: #fff'>x</div>"];
        yield 'neo trong href' => ['<a href="#section">x</a>'];
        yield 'href thăng trần' => ['<a href="#">x</a>'];
        yield 'thăng giữa class' => ['<div class="a #b">x</div>'];
        yield 'thăng trong title' => ['<a title="xem #important">x</a>'];
        yield 'thăng trong data' => ['<div data-x="tag #hot">x</div>'];
        yield 'thăng trong ngoặc directive' => ['<div @class({"a": "#fff"})>x</div>'];
        yield 'thăng trong nội dung' => ['<p>giá #1 là 100</p>'];
        yield 'thăng trong script' => ['<script>var a = "#id";</script>'];
        yield 'so sánh nhỏ hơn trong echo' => ['<p>{{ a < b }}</p>'];
    }

    #[DataProvider('dauThangKhongPhaiDirective')]
    public function test_khong_dung_vao_dau_thang_thuong(string $input): void
    {
        self::assertSame($input, Html::expandTagDirectives($input));
    }

    #[DataProvider('truongHopHopLe')]
    public function test_ha_dung(string $input, string $expected): void
    {
        self::assertSame($expected, Html::expandTagDirectives($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function truongHopLoi(): iterable
    {
        // Không chặn thì `#fi="x"` rơi lại thành thuộc tính `fi="x"` trong
        // HTML: parseElementAttributes nhảy qua dấu '#' rồi khớp phần còn lại
        // như attr thường. Hỏng câm — đúng thứ phải tránh.
        yield 'tên directive lạ' => ['<div #foo="a">y</div>', 'không phải directive hợp lệ'];
        yield 'gõ nhầm tên' => ['<div #fi="a">y</div>', 'không phải directive hợp lệ'];

        yield 'else mồ côi' => ['<div #else>y</div>', 'không có `#if` bao ngoài'];
        yield 'elseif mồ côi' => ['<p #elseif="b">y</p>', 'không có `#if` bao ngoài'];

        // Thẻ không đóng HỢP LỆ trong .sao (compiler coi là lồng nhau, khác
        // trình duyệt). Nhưng thẻ mang `#` mà thiếu thẻ đóng thì bộ dò chạy
        // tới hết file — phải báo lỗi thay vì lặng lẽ bọc tất.
        yield 'thiếu thẻ đóng' => ['<section><div #if="a">y</section>', 'thiếu `</div>`'];

        yield 'if rỗng' => ['<div #if="">y</div>', 'thiếu biểu thức'];
        yield 'else có giá trị' => ['<div #if="a">x</div><div #else="b">y</div>', 'không nhận giá trị'];
        yield 'hai directive một thẻ' => ['<div #if="a" #else>y</div>', 'nhiều directive cùng loại'];

        // Chuỗi phải là sibling LIỀN KỀ — chỉ được cách bởi khoảng trắng.
        yield 'có text thật giữa chuỗi' => [
            '<a #if="a">A</a> chữ <p #else>B</p>',
            'không có `#if` bao ngoài',
        ];

        // Nội dung lạc trong `@switch` nhưng ngoài mọi `@case` bị Blade render
        // còn JS bỏ hẳn — SSR ≠ CSR, không cảnh báo. `#switch` không thừa hưởng.
        yield 'text lạc trong switch' => [
            '<div #switch="s">LẠC<p #case="1">A</p></div>',
            'gặp nội dung lạc',
        ];
        yield 'echo lạc trong switch' => [
            '<div #switch="s">{{ x }}<p #case="1">A</p></div>',
            'gặp nội dung lạc',
        ];
        yield 'con không mang case' => [
            '<div #switch="s"><h4>T</h4><p #case="1">A</p></div>',
            'phải mang `#case` hoặc `#default`',
        ];
        yield 'switch rỗng' => ['<div #switch="s">   </div>', 'không có nhánh `#case` nào'];

        // `#switch` bọc RUỘT nên thẻ rỗng không có chỗ để bọc.
        yield 'switch trên thẻ rỗng' => ['<img #switch="s">', 'không đặt được trên thẻ rỗng'];

        yield 'case mồ côi' => ['<p #case="1">A</p>', 'không có `#switch` bao ngoài'];
        yield 'default mồ côi' => ['<p #default>A</p>', 'không có `#switch` bao ngoài'];
        yield 'case thiếu giá trị' => ['<div #switch="s"><p #case>A</p></div>', 'thiếu biểu thức'];
        yield 'default có giá trị' => ['<div #switch="s"><p #default="1">A</p></div>', 'không nhận giá trị'];

        // `#key` là bạn đồng hành của directive lặp, không đứng một mình.
        yield 'key một mình' => ['<li #key="x">y</li>', 'phải đi kèm'];
        yield 'key với if' => ['<li #if="a" #key="x">y</li>', 'phải đi kèm'];
        yield 'hai key một thẻ' => ['<li #foreach="r as x" #key="a" #key="b">y</li>', 'mang hai `#key`'];
        yield 'foreach cùng for' => ['<li #foreach="r as x" #for="i = 0; i < 2; i++">y</li>', 'nhiều directive cùng loại'];
        yield 'case cùng default' => ['<div #switch="s"><p #case="1" #default>A</p></div>', 'nhiều directive cùng loại'];

        // `#case`/`#default` bọc ngoài cùng → nhánh else bị nhốt, không nối được.
        yield 'case cùng else' => ['<div #switch="s"><p #case="1" #else>A</p></div>', 'không đi cùng `#else`'];
        yield 'default cùng elseif' => ['<div #switch="s"><p #default #elseif="b">A</p></div>', 'không đi cùng `#elseif`'];

        // `#if` cạnh loop nằm TRONG loop → chuỗi đóng trong vòng lặp, thẻ anh
        // em `#else` không có `#if` để nối.
        yield 'else sau if nằm trong loop' => [
            '<li #foreach="r as x" #if="x.a">a</li><p #else>b</p>',
            'không có `#if` bao ngoài',
        ];
        yield 'case cùng switch mà ngoài không có switch' => ['<p #case="1" #switch="t"><b #case="2">x</b></p>', 'không có `#switch` bao ngoài'];
    }

    #[DataProvider('truongHopLoi')]
    public function test_bao_loi(string $input, string $expectedMessage): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessage, '/') . '/');

        Html::expandTagDirectives($input);
    }
}
