# 03 — Directive registry

Chỗ ứng dụng, package và theme thêm directive `.sao` của riêng mình.

Tài liệu này mô tả **thứ đang chạy**. Phần thiết kế chưa xây nằm riêng ở §7 và
được đánh dấu rõ — đừng viết code dựa vào nó.

## 1. Registry làm gì

`SaolaCompiler::compile()` chạy registry **hai lần trên cùng một nguồn**, mỗi
lần cho một đích:

```php
$bladeSource = $this->directiveRegistry->transform($source, 'blade');
$jsSource    = $this->directiveRegistry->transform($source, 'js');
```

Hai chuỗi kết quả đi tiếp vào pipeline bình thường. Nghĩa là directive người
dùng là một bước **thay thế văn bản trước khi parse**, không phải một node trong
AST. Hệ quả của điều đó nằm ở §6.

Vì sao phải hai đích chứ không một, như `Blade::directive()` của Laravel:
Laravel chỉ sinh PHP. Saola sinh cả Blade lẫn JavaScript cho cùng một nguồn, và
hai bên phải ra cùng một DOM. Một directive chỉ khai một đích là mở đường cho
đúng lớp bug tốn nhiều thời gian nhất của dự án này — SSR và CSR lệch nhau.

## 2. API

```php
use Saola\Compiler\Directive\DirectiveRegistry;

$registry->directive('money', fn (string $expr) => [
    'blade' => "{{ number_format({$expr}, 0, ',', '.') }} đ",
    'js'    => "`\${fmtMoney({$expr})} đ`",
]);
```

Dùng trong `.sao`:

```blade
<span>@money(product.price)</span>
```

Dạng khối nhận thêm phần thân:

```php
$registry->blockDirective('repeat', fn (string $expr, string $body) => [
    'blade' => "@for(\$i = 0; \$i < {$expr}; \$i++){$body}@endfor",
    'js'    => "this.__range({$expr}, (i) => [{$body}])",
]);
```

Ba entrypoint, tất cả trả `$this` để nối chuỗi:

| Method | Dùng khi |
|---|---|
| `directive(string $name, callable $handler, bool $override = false)` | dạng inline `@money(...)` |
| `blockDirective(string $name, callable $handler, bool $override = false)` | dạng khối `@repeat(...) … @endrepeat` |
| `registerUser(UserDirective $directive, bool $override = false)` | khi tự dựng `UserDirective` |

### Thiếu một đích là lỗi compile

Handler phải trả mảng có **cả** `blade` lẫn `js`, cả hai là chuỗi. Thiếu thì
`UserDirective::emit()` ném ngay:

```
CompileException: Directive @money phải phát đủ hai đích 'blade' và 'js'.
```

Đây là chỗ duy nhất trong compiler biến "quên một phía" từ một bug im lặng ở
trình duyệt thành một lỗi lúc build. Không có cờ nào tắt được nó — muốn một
directive chỉ có tác dụng ở một phía thì trả chuỗi rỗng cho phía kia, và bọc
nội dung trong `@ssr`/`@csr` nếu cần khối chỉ thuộc một bên.

## 3. Ba tầng

Không phải directive nào cũng cho ghi đè. Cấu trúc điều khiển quyết định hình
dạng AST và thứ tự cấp phát marker; cho ghi đè là mời bug hydration vào nhà.

| Tầng | Directive | Ghi đè? |
|---|---|---|
| **T0 — Khoá** | `@if @elseif @else @foreach @for @while @switch @case @default @break` `@startMarker @endMarker @startReactive @endReactive @pageStart @pageEnd @hydrate @out` | ❌ Không bao giờ. `registerUser()` ném `InvalidArgumentException` kể cả khi có cờ |
| **T1 — Lõi** | `@useState @states @vars @props @let @const @computed @include @importInclude @children @extends @yield @section @block @wrapper @await @fetch @subscribe`, cộng toàn bộ directive sự kiện, binding và element | ⚠️ Cần `override: true` tường minh, nếu không ném exception |
| **T2 — Mở** | Mọi tên còn lại | ✅ Đăng ký tự do |

T0 là hằng `LOCKED`, T1 là hằng `CORE` **cộng** ba hằng lấy thẳng từ
`ExpressionTransformer`: `EVENT_DIRECTIVES`, `BIND_DIRECTIVES`,
`ELEMENT_DIRECTIVES`. Lấy từ nguồn thay vì chép tay là có lý do: trước đây 32
directive (`@class`, `@style`, `@attr`, `@bind`, `@checked`, `@val` và mọi
directive sự kiện) **không thuộc tầng nào**, nên đè được mà không cần cờ và
không có cảnh báo nào. Hậu quả im lặng khi đè `@class`:

```blade
trước : <div @class([$__VIEW_ID__ . '-e1', 'a'=> $on])>x</div>
sau   : <div @class([$__VIEW_ID__ . '-e1']) @attr(['X' => true])>x</div>
```

Class điều kiện biến mất, lại chèn thêm một thuộc tính rác, không lỗi gì. Có
unit test khẳng định mọi directive compiler xử lý đều thuộc một tầng — thêm
directive mới mà quên khai tầng thì test đỏ.

### 3.1. Namespace `#` là tập ĐÓNG

`#if="cond"` viết trên thẻ là cách viết khác của `@if(cond)` bọc quanh thẻ đó.
Compiler hạ nó về directive khối tương ứng ở
`Support\Html::expandTagDirectives()`, gọi từ
`ExpressionTransformer::transformTemplate()` **trước** `transformDirectives()` —
tức trước cả registry. Chi tiết: [SAO_ELEMENT_DIRECTIVES_RFC.md](../../docs/SAO_ELEMENT_DIRECTIVES_RFC.md).

| `#` | hạ thành | ghi chú |
|---|---|---|
| `#if` `#elseif` `#else` | `@if` `@elseif` `@else` | chuỗi nhánh phải là sibling liền kề, chỉ cách nhau bởi khoảng trắng |
| `#switch` | `@switch` | ca DUY NHẤT bọc **ruột** thẻ, không bọc thẻ; con phải toàn `#case`/`#default` |
| `#case` `#default` | `@case` `@default` | `@break` do compiler tự chèn |
| `#foreach` `#for` `#while` | `@foreach` `@for` `@while` | bọc thẻ như `#if` |
| `#key` | `@key` | bạn đồng hành của directive lặp; ngoại lệ duy nhất của quy tắc một-directive-mỗi-thẻ |

**Không đăng ký được `#` mới.** Registry chỉ nhận tên `@`. Bảng trên là tập
đóng nằm trong `Html::TAG_DIRECTIVES`; đây là quyết định E-07 của RFC, không
phải thiếu sót.

Khác `@` một điểm quan trọng: **`#` lạ là LỖI biên dịch**, không im lặng đi qua
như `@chua_dang_ky` (§6). Bắt buộc phải thế, vì `parseElementAttributes` nhảy
qua dấu `#` rồi khớp phần còn lại như thuộc tính thường — gõ nhầm `#fi="x"` sẽ
lặng lẽ thành `fi="x"` trong HTML nếu không chặn.

Dấu `#` chỉ được coi là directive khi đứng ở **vị trí tên thuộc tính**: ngoài
nháy, ngoài ngoặc của `@class(...)`, và ngay sau khoảng trắng. Nên
`style="color: #fff"`, `href="#section"` và `title="xem #quan-trọng"` không bị
đụng tới.

## 4. `transform()` không đụng vào `@verbatim` và comment

Trước khi thay, registry che `{{-- … --}}` và `@verbatim … @endverbatim`, thay
xong mới khôi phục. Không có bước này thì trang tài liệu in ví dụ `@money(2)`
sẽ bị chính `@money` của người dùng viết lại — tài liệu hiện ra thứ khác thứ nó
đang mô tả. Cùng cách preprocessor và hydrate processor đang làm.

## 5. Đăng ký ở đâu

### Trong app Laravel

`SaolaCompilerServiceProvider` đăng ký `DirectiveRegistry` và `SaolaCompiler`
làm singleton, compiler dùng chính registry đó.

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->app->make(DirectiveRegistry::class)->directive('money', fn (string $e) => [
        'blade' => "{{ number_format({$e}, 0, ',', '.') }}",
        'js'    => "fmtMoney({$e})",
    ]);
}
```

> **Octane:** registry là singleton **có chủ ý**, để directive khai lúc boot còn
> hiệu lực cho mọi lần compile. Nhưng một worker Octane sống hàng nghìn request,
> nên đăng ký directive **trong request** sẽ rò sang các request sau. Chỉ đăng
> ký lúc boot.

### Cho CLI

```bash
php bin/saoc compile in.sao --directives=./sao.directives.php --json
```

File phải `return` một iterable. Mỗi phần tử là `UserDirective`,
`BuiltinDirective`, hoặc cặp `'tên' => callable`; thứ khác thì `bin/saoc` ném
`RuntimeException`.

### Trong theme — `sao.directives.php` ở gốc theme

> ⚠️ File này là **code PHP do theme cung cấp**. Nạp nó = chạy code của theme.
> Ở chế độ `sandbox: true`, `bin/saoc` **từ chối nạp** và ném
> `RuntimeException`. Xem [04 §5](04-runtime-compile.md#5-bảo-mật-đọc-kỹ-mục-này).

## 6. Giới hạn của thiết kế hiện tại

Hai điều dưới đây không phải bug, nhưng hiểu sai chúng sẽ dẫn tới kỳ vọng sai.

### `parse()` và `builtins()` KHÔNG nằm trên đường compile

`DirectiveRegistry::parse()` cùng 14 directive dựng sẵn trong `builtins()`
(`ExtendsDirective`, `VarsDirective`, `PropsDirective`, …) **chỉ được gọi từ
cổng parity**. Pipeline thật dùng thẳng `DirectiveParsers`: `MainCompiler` dựng
`new DirectiveParsers($this->expressions)` và không hỏi registry.

Tức phần `BuiltinDirective` của registry hiện là **mặt tiền song song**: nó có
danh sách directive lõi, có phân tầng, có test — nhưng việc phân tích thật diễn
ra ở chỗ khác. Chỉ nhánh `UserDirective` (`transform()`) là thực sự chạy khi
compile.

### `override: true` là thay VĂN BẢN, không phải thay parser

Hệ quả trực tiếp của mục trên. Ghi đè một directive T1 chỉ chèn một bước thay
thế văn bản **trước** khi parser chạy; parser lõi vẫn nguyên:

```php
$registry->directive('states', fn () => ['blade' => '/*x*/', 'js' => '/*x*/'], override: true);
// → @states({n:1}) bị xoá trước khi tới parser ⇒ view MẤT SẠCH state, không lỗi
```

Đó là quyền của người dùng khi họ đã bật cờ. Nhưng dùng `override` để **viết lại
cú pháp trước khi compile**, đừng dùng nó để đổi ngữ nghĩa một directive lõi.

### Directive lạ không có cảnh báo

`transform()` chỉ đụng tới tên đã đăng ký. `@chua_dang_ky(x)` đi qua nguyên vẹn
và rơi xuống parser lõi như văn bản thường — không có cảnh báo nào trong
`CompileResult::$warnings`.

Namespace `#` thì ngược lại: tên lạ ném `CompileException` ngay (§3.1).

## 7. Chưa xây — parse hai đích với marker id dùng chung

Phần này là **hướng đi, không phải API**. Không có class nào dưới đây tồn tại
trong `src/`.

Mô hình hiện tại đủ cho directive sinh ra biểu thức hoặc đoạn markup tĩnh. Nó
**không** đủ cho directive sinh vùng reactive, vì vùng reactive cần một marker
id mà hai đích phải nhất trí. Hôm nay directive người dùng không có cách nào xin
id đó, nên tự bịa id là con đường duy nhất — và tự bịa thì Blade với JS sẽ lệch.

Hướng giải là cho directive nói chuyện với bộ cấp phát marker thay vì với chuỗi:

```php
// CHƯA CÓ — minh hoạ hướng đi
public function toBlade(Node $node, BladeContext $ctx): string
{
    $id = $ctx->markerId($node);          // ✅ xin từ context
    // $id = md5($node->expr);            // ❌ sẽ lệch với phía JS
    return "@startMarker('text', '{$id}'){{ … }}@endMarker('text', '{$id}')";
}
```

Điều kiện đúng đắn là: hai emitter hỏi **cùng một node** thì nhận **cùng một
id**, theo cấu trúc chứ không theo quy ước. Chừng nào chưa có nó thì directive
người dùng nên tránh sinh vùng reactive; sinh markup tĩnh hoặc biểu thức thì an
toàn.

## 8. Kiểm thử

`tests/Unit/Directive/` — 18 test cho registry và tham số sự kiện. Directive
người dùng test được mà không cần compile trọn một file `.sao`: dựng
`UserDirective`, gọi `emit('blade', …)` và `emit('js', …)`, so hai chuỗi.

Với directive lõi thì vẫn phải compile thật rồi so output, vì chúng nằm ở
`DirectiveParsers` chứ không ở registry (§6).
