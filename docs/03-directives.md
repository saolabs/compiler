# 03 — Cơ chế đăng ký directive

## 1. Vấn đề đang có

Thêm directive `@money` vào compiler hôm nay phải sửa **ba file lõi**:

| File | Sửa gì |
|---|---|
| `sao2js/template_ast.py` (1.601 dòng) | thêm một nhánh vào chuỗi `if re.match(r'@money\s*\(')` |
| `sao2js/render_generator.py` (1.273 dòng) | thêm một nhánh vào chuỗi `isinstance($node, ...)` |
| `sao2blade/hydrate_processor.py` (858 dòng) | thêm xử lý cho phía SSR |

Ba nơi, ba chuỗi điều kiện dài. Quên một chỗ = SSR và CSR lệch nhau, và triệu
chứng hiện ra ở tận trình duyệt dưới dạng DOM nhân đôi.

Hiện có ~70 directive, tất cả đều nằm trong ba chuỗi `if` đó.

## 2. Mô hình mới

`if/elif` → **bảng tra**. Directive là object tự mang đủ mọi thứ nó cần.

```php
use Saola\Compiler\SaolaCompiler;

SaolaCompiler::directive('money', function (string $expr) {
    return [
        'blade' => "{{ number_format({$expr}, 0, ',', '.') }} đ",
        'js'    => "`\${fmtMoney({$expr})} đ`",
    ];
});
```

Dùng trong `.sao`:

```blade
<span>@money(product.price)</span>
```

Giống tinh thần `Blade::directive()` của Laravel, khác một điểm cốt tử: **Saola
phải phát ra hai đích, không phải một.** Laravel chỉ sinh PHP. Saola sinh cả
Blade (SSR) lẫn JS (CSR), và hai cái phải khớp nhau.

Đó là lý do closure trả về `['blade' => ..., 'js' => ...]` chứ không phải một chuỗi.

## 3. Ba tầng — không phải directive nào cũng mở

Không nên cho phép ghi đè mọi thứ. Cấu trúc điều khiển quyết định hình dạng AST
và thứ tự cấp phát marker; cho ghi đè là mời bug hydration vào nhà.

| Tầng | Directive | Ghi đè? |
|---|---|---|
| **T0 — Khoá** | `@if @elseif @else @foreach @for @while @switch @case @default @break` `@startMarker @endMarker @startReactive @endReactive @pageStart @pageEnd @out` | ❌ Không bao giờ. Đây là hình dạng AST và hợp đồng marker |
| **T1 — Lõi** | `@useState @states @vars @props @let @const @computed @include @importInclude @children @extends @yield @section @block @wrapper @await @fetch @subscribe` | ⚠️ Chỉ ghi đè khi khai báo `override: true` tường minh. Có cảnh báo |
| **T2 — Mở** | Mọi directive người dùng tự tạo: inline, attribute, block | ✅ Đây là mục tiêu thật của registry |

Toàn bộ ~70 directive lõi được viết lại thành class ở tầng T0/T1, **dùng chung
đúng interface với directive người dùng**. Không có đường tắt riêng cho directive
lõi — nếu interface không đủ để biểu diễn `@foreach`, thì interface đó chưa đúng.

## 4. Interface đầy đủ

Closure là lối tắt cho trường hợp đơn giản. Directive phức tạp cài interface:

```php
namespace Saola\Compiler\Directive;

interface Directive
{
    /** 'money' → khớp @money */
    public function name(): string;

    /** true nếu là dạng khối: @repeat ... @endrepeat */
    public function isBlock(): bool;

    /** Nguồn → node AST. Trả null = không nhận, thử directive kế tiếp. */
    public function parse(ParseContext $ctx): ?Node;
}

/** Phát mã SSR. */
interface EmitsBlade
{
    public function toBlade(Node $node, BladeContext $ctx): string;
}

/** Phát mã CSR. */
interface EmitsJs
{
    public function toJs(Node $node, JsContext $ctx): string;
}

/** Chạy TRƯỚC khi parse — dịch cú pháp Saola sang PHP/Blade. */
interface Preprocesses
{
    public function transform(string $source, PreContext $ctx): string;
}
```

### Quy tắc phải có cả hai đích

Một directive cài `EmitsBlade` mà không cài `EmitsJs` (hoặc ngược lại) sẽ
**dừng compile với lỗi**, không phải cảnh báo:

```
CompileException: Directive @money khai báo EmitsBlade nhưng thiếu EmitsJs.
  Directive sinh DOM phải phát cả hai đích, nếu không hydration sẽ lệch.
  Nếu cố ý chỉ dùng một phía, khai báo tường minh:
    - implements SsrOnly     (chỉ SSR, client bỏ qua)
    - implements ClientOnly  (chỉ CSR, SSR phát chuỗi rỗng)
```

Đây là bản dịch của lớp bug được ghi lại nhiều nhất trong repo này thành một
**lỗi lúc compile**. Nó là lý do chính đáng nhất để registry tồn tại — quan trọng
hơn cả sự tiện tay.

## 5. Marker id: lấy từ context, không tự bịa

Directive sinh nội dung reactive **phải** xin id qua context:

```php
public function toBlade(Node $node, BladeContext $ctx): string
{
    $id = $ctx->markerId($node);          // ✅ đúng
    // $id = md5($node->expr);            // ❌ SAI — sẽ lệch với phía JS
    return "@startMarker('text', '{$id}'){{ ... }}@endMarker('text', '{$id}')";
}

public function toJs(Node $node, JsContext $ctx): string
{
    $id = $ctx->markerId($node);          // cùng node → CÙNG id, đã bảo đảm
    return "this.reactive('{$id}', () => ...)";
}
```

`markerId()` tra vào `MarkerAllocator` dùng chung, khoá theo identity của node.
Hai emitter hỏi cùng một node thì nhận về cùng một id — theo cấu trúc, không phải
theo quy ước. Directive **không thể** làm lệch marker kể cả khi cố tình.

## 6. Các dạng lối tắt

### Inline (trường hợp 90%)

```php
SaolaCompiler::directive('money', fn(string $e) => [
    'blade' => "{{ number_format({$e}, 0, ',', '.') }}",
    'js'    => "fmtMoney({$e})",
]);
```

### Attribute — bám vào element

```php
SaolaCompiler::attributeDirective('tooltip', fn(string $e) => [
    'blade' => ['data-tooltip' => "{{ {$e} }}"],
    'js'    => ['data-tooltip' => $e],
]);
```

```blade
<button @tooltip('Lưu lại')>Lưu</button>
```

### Block

```php
SaolaCompiler::blockDirective('repeat', fn(string $e, string $body) => [
    'blade' => "@for(\$i = 0; \$i < {$e}; \$i++){$body}@endfor",
    'js'    => "this.__range({$e}, (i) => [{$body}])",
]);
```

## 7. Đăng ký ở đâu

### Trong app Laravel

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    SaolaCompiler::directive('money', ...);
}
```

### Trong package

```php
// SaolaCompilerServiceProvider tự quét
$compiler->directives()->registerFrom(MyPackageDirectives::class);
```

### Trong theme — `sao.directives.php` ở gốc theme

```php
// themes/dark/sao.directives.php
return [
    new \Theme\Dark\Directives\HeroDirective(),
];
```

> ⚠️ File này là **code PHP do theme cung cấp**. Nạp nó = chạy code của theme.
> Xem [04 §5](04-runtime-compile.md#5-bảo-mật-đọc-kỹ-mục-này) và câu hỏi mở Q5.
> Ở chế độ `sandbox: true`, file này **không được nạp**.

### Cho Node CLI

```bash
php bin/saoc compile in.sao --directives=./sao.directives.php --json
```

## 8. Thứ tự phân giải

1. Directive T0 (khoá) — khớp trước, không thể chặn
2. Directive người dùng đăng ký (sau nhất thắng — cho phép ghi đè T1 có chủ ý)
3. Directive lõi T1
4. Không khớp → giữ nguyên văn bản gốc, kèm cảnh báo trong `CompileResult::$warnings`

`parse()` trả `null` nghĩa là "không phải của tôi" → thử tiếp. Cho phép một
directive nhận có điều kiện tuỳ ngữ cảnh.

## 9. Lợi ích phụ: kiểm thử

Registry biến mỗi directive thành một đơn vị test độc lập, không cần compile cả view:

```php
it('@money khớp giữa blade và js', function () {
    $d = new MoneyDirective();
    expect($d)->toEmitMatchingMarkers('@money(price)');
});
```

Hôm nay muốn test một directive phải compile trọn một file `.sao` rồi so chuỗi
output — chính là cách 20 test hiện tại đang làm.

---

## 10. Rà soát registry — kết quả

Rà lại sau khi registry đã đi vào dùng. Bốn phát hiện, hai đã sửa.

### ✅ Đã sửa — 32 directive nằm ngoài mọi tầng

`@class`, `@style`, `@attr`, `@bind`, `@checked`, `@val`, và **toàn bộ** directive
sự kiện (`@click`, `@change`, `@submit`, …) trước đây **không thuộc T0 lẫn T1**.
Đăng ký đè chúng được chấp nhận mà **không cần cờ, không cảnh báo**.

Hậu quả im lặng — đè `@class` không cờ:

```blade
trước : <div @class([$__VIEW_ID__ . '-e1', 'a'=> $on])>x</div>
sau   : <div @class([$__VIEW_ID__ . '-e1']) @attr(['X' => true])>x</div>
```

Class điều kiện biến mất, lại còn chèn thêm một thuộc tính rác. Không lỗi gì.

**Sửa:** tầng T1 lấy danh sách từ chính hằng của `ExpressionTransformer`
(`EVENT_DIRECTIVES`, `BIND_DIRECTIVES`, `ELEMENT_DIRECTIVES`) thay vì chép tay
— hai bản chép tay chắc chắn lệch nhau theo thời gian, mà lệch ở đây nghĩa là
lại hở. Có unit test khẳng định **mọi** directive compiler xử lý đều thuộc một
tầng; thêm directive mới mà quên khai tầng thì test đỏ.

### ✅ Đã sửa — `transform()` viết lại cả trong `@verbatim` và comment

Directive người dùng bị thay cả bên trong `@verbatim` và `{{-- --}}`. Trang docs
in ví dụ `@money(2)` sẽ bị chính `@money` của người dùng viết lại — tài liệu
hiện ra thứ khác thứ nó đang mô tả.

**Sửa:** che hai vùng đó trước khi thay, khôi phục sau. Cùng cách preprocessor
và hydrate processor đã làm.

### ⚠️ Chưa sửa — `parse()` KHÔNG nằm trên đường compile

`DirectiveRegistry::parse()` và 14 directive dựng sẵn trong `builtins()` **chỉ
được gọi từ cổng parity**, không nơi nào trong pipeline. `MainCompiler` dùng
thẳng `DirectiveParsers`.

Tức registry hiện là **mặt tiền song song**: nó có danh sách directive lõi, có
phân tầng, có test — nhưng việc phân tích thật diễn ra ở chỗ khác. Không sai,
nhưng cần biết để khỏi hiểu nhầm về khả năng của nó.

### ⚠️ Chưa sửa — `override: true` là THAY VĂN BẢN, không phải thay parser

Hệ quả trực tiếp của mục trên. Ghi đè một directive T1 chỉ chèn một bước thay
thế văn bản **trước** khi parser chạy; parser lõi vẫn nguyên. Nên:

```php
$registry->directive('states', fn () => ['blade' => '/*x*/', 'js' => '/*x*/'], override: true);
// → @states({n:1}) bị xoá trước khi tới parser ⇒ view MẤT SẠCH state, không lỗi
```

Đó là quyền của người dùng khi họ đã bật cờ, nhưng tài liệu trước đây gợi ý
rằng ghi đè là *thay thế cách xử lý* — không phải. Dùng `override` để **viết
lại cú pháp trước khi compile**, không phải để đổi ngữ nghĩa directive.

### Ghi chú Octane

`SaolaCompilerServiceProvider` đăng ký `DirectiveRegistry` là **singleton** — cố
ý, để directive khai trong `AppServiceProvider::boot()` còn hiệu lực cho mọi lần
compile. Nhưng dưới Octane một worker sống hàng nghìn request: đăng ký directive
**trong request** sẽ rò sang các request sau. Chỉ đăng ký lúc boot.
