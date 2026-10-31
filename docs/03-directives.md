# 03 — Directive registry

Chỗ ứng dụng, package và theme thêm directive `.sao` của riêng mình — cả dạng
`@name(...)` lẫn dạng viết trên thẻ `#name="..."`.

## 1. Nguyên tắc: "sao in, sao out"

Handler của directive **trả về `.sao`**, không trả Blade hay JavaScript.
`SaolaCompiler::compile()` chạy registry trên nguồn trước khi parse:

```php
$bladeSource = $this->directiveRegistry->transform($source, 'blade');
$jsSource    = $this->directiveRegistry->transform($source, 'js');
```

Output của handler được dán lại vào nguồn rồi đi qua **cả hai** pipeline như
code người dùng tự viết. Nên mọi thứ khó — marker id, vùng reactive, stateKey,
escape, dịch biểu thức JS→PHP — compiler tự lo, giống hệt với code viết tay.
Tác giả directive không cần biết hydration là gì.

Vì sao không làm như `Blade::directive()` (trả PHP)? Laravel chỉ sinh một đích.
Saola sinh Blade lẫn JS cho cùng một nguồn và hai bên phải ra cùng một DOM. Hợp
đồng cũ (`['blade' => php, 'js' => js]`) cho phép mỗi bên một chuỗi thô: chuỗi
`js` bị dán vào template như văn bản, ra `this.text('String(msg)…')` tĩnh trong
khi Blade có marker output — hydration lệch, không báo lỗi.

## 2. Khai báo — `sao.directives.php`

```php
<?php // sao.directives.php ở gốc project (hoặc của một package, xem §5)

use Saola\Compiler\Directive\Sao;

return [
    // @money(price)
    Sao::directive('money', fn (string $e) => "{{ formatMoney({$e}) }}"),

    // @can('edit') … @endcan
    Sao::block('can', fn (string $e, string $body) => "@if(canDo({$e})){$body}@endif"),

    // <button #can="'edit'">…</button>  → bọc cả element
    Sao::tag('can', fn (string $e) => ["@if(canDo({$e}))", '@endif']),

    // <span #tooltip="hint">  → thay bằng thuộc tính
    Sao::attr('tooltip', fn (string $e) => 'data-tooltip="{{ ' . $e . ' }}"'),

    // <div #busy>  → không nhận giá trị
    Sao::attr('busy', fn () => 'aria-busy="true"', value: false),

    // formatMoney(x) trong biểu thức: có ở cả PHP lẫn App.Helper
    Sao::helper('formatMoney'),
];
```

| Khai báo | Dùng trong `.sao` | Handler nhận | Handler trả |
|---|---|---|---|
| `Sao::directive($name, $fn)` | `@name(expr)` | `expr` | chuỗi `.sao` |
| `Sao::block($name, $fn)` | `@name(expr) … @endname` | `expr`, `body` | chuỗi `.sao` |
| `Sao::tag($name, $fn, value: true)` | `<x #name="expr">…</x>` | `expr`, tên thẻ | `[.sao trước, .sao sau]` |
| `Sao::attr($name, $fn, value: true)` | `<x #name="expr">` | `expr`, tên thẻ | chuỗi thuộc tính `.sao` |
| `Sao::helper($name)` | `name(...)` trong biểu thức | — | — (chỉ khai báo) |

Mọi khai báo nhận thêm `override: true` (§3). Registry có cùng các cửa đó dưới
dạng method: `directive()`, `blockDirective()`, `tag()`, `attr()`, và `add()`
nhận thẳng một khai báo.

### Hàm runtime phải có ở cả hai phía

Output là `.sao`, nên hàm gọi trong đó (`formatMoney`, `canDo`) phải chạy được
ở **cả** Blade (PHP) lẫn client (JS), cùng tên. Lời gọi trần `name(x)` compile
thành hàm PHP toàn cục `name($x)` ở Blade và `App.Helper.name(x)` ở JS:

- PHP: `autoload.files` trong composer.json của package.
- JS: `helpers` của bundle client (`extra.saola.client`, xem
  docs/EXTENSION_ARCHITECTURE.md §8.0); dữ liệu theo request đi qua
  `Saola::share()` → `App.shared()`.
- `Sao::helper('name')` báo cho compiler rằng hai nửa đó tồn tại, để nó thôi
  cảnh báo `[sao2js] … không khớp … helper đã biết`.

Builtin JS như `String(x)` không có ở PHP — compiler đã cảnh báo `[sao2blade]`
cho trường hợp đó. Ví dụ đủ bốn mảnh: `saola/permission` (`canRoute`, `#permission`).

### Khi hai đầu buộc phải khác

`Sao::directive`/`Sao::block` vẫn nhận `['blade' => sao, 'js' => sao]`. Cả hai
giá trị phải là `.sao`, và compiler so id hydrate của hai bản
(`Hydration\MarkerParity`): lệch là `CompileException` ngay lúc build. Khác văn
bản tĩnh thì được; khác cấu trúc (bên có `{{ }}`, bên không) thì không.

## 3. Ba tầng

Không phải directive nào cũng cho ghi đè. Cấu trúc điều khiển quyết định hình
dạng AST và thứ tự cấp phát marker.

| Tầng | Directive | Ghi đè? |
|---|---|---|
| **T0 — Khoá** | `@if @elseif @else @foreach @for @while @switch @case @default @break` `@startMarker @endMarker @startReactive @endReactive @pageStart @pageEnd @hydrate @out`, và mọi `#` dựng sẵn (§3.1) | ❌ Không bao giờ, kể cả có cờ |
| **T1 — Lõi** | `@useState @states @vars @props @let @const @computed @include @importInclude @children @extends @yield @section @block @wrapper @await @fetch @subscribe`, cộng toàn bộ directive sự kiện, binding và element | ⚠️ Cần `override: true` |
| **T2 — Mở** | Mọi tên còn lại | ✅ Tự do |

Tên T2 trùng giữa hai khai báo (hai thư viện cùng có `#tooltip`) cũng là lỗi,
trừ khi khai báo sau có `override: true`.

T1 lấy thẳng từ hằng của `ExpressionTransformer` (`EVENT_DIRECTIVES`,
`BIND_DIRECTIVES`, `ELEMENT_DIRECTIVES`) thay vì chép tay; có unit test khẳng
định mọi directive compiler xử lý đều thuộc một tầng.

### 3.1. Namespace `#`

`#` dựng sẵn hạ về directive khối ở `Support\Html::expandTagDirectives()`.
Chi tiết: [SAO_ELEMENT_DIRECTIVES_RFC.md](../../docs/SAO_ELEMENT_DIRECTIVES_RFC.md).

| `#` | hạ thành | ghi chú |
|---|---|---|
| `#if` `#elseif` `#else` | `@if` `@elseif` `@else` | chuỗi nhánh phải là sibling liền kề |
| `#switch` | `@switch` | bọc **ruột** thẻ; con phải toàn `#case`/`#default` |
| `#case` `#default` | `@case` `@default` | `@break` do compiler tự chèn |
| `#foreach` `#for` `#while` | `@foreach` `@for` `@while` | bọc thẻ như `#if` |
| `#key` | `@key` | đi kèm directive lặp |

Một thẻ có thể kết hợp nhiều `#` khác loại, thứ tự bọc ngoài → trong:
`#case`/`#default` > `#elseif`/`#else` > loop (`#foreach`/`#for`/`#while` + `#key`) > `#if` > `#switch`.
Ví dụ: `<li #foreach="r as x" #key="x.id" #if="!x.hidden">` sẽ hạ thành `@foreach(r as x)@key(x.id)@if(!x.hidden)<li>...</li>@endif@endforeach`.
Cùng loại trên một thẻ (ví dụ 2 loop hoặc `#case` + `#default`) là lỗi biên dịch.

`#` của người dùng (`Sao::tag`/`Sao::attr`) hạ trước, ở
`Html::expandUserTagDirectives()` trong `DirectiveRegistry::transform()`. Luật
kiểm tra **ngay trên thẻ**:

- Chỉ nhận ở **vị trí tên thuộc tính**: ngoài nháy, ngoài ngoặc, ngay sau
  khoảng trắng. `style="color: #fff"`, `href="#a"`, `title="xem #x"`, chữ trong
  văn bản, `{{ }}`, `@verbatim`, comment và ruột `<script>`/`<style>` không bị đụng.
- Tên lạ (không dựng sẵn, không đăng ký) là **lỗi biên dịch** — gõ nhầm
  `#fi="x"` không được lặng lẽ thành thuộc tính `fi`.
- `value: true` mà thiếu `="..."`, hoặc `value: false` mà có giá trị: lỗi.
- Mỗi thẻ một directive bọc: hai `Sao::tag`, hoặc `Sao::tag` cùng `#if`/`#foreach`…
  trên một thẻ là lỗi — bọc thêm một thẻ ngoài. `Sao::attr` xếp chồng thoải mái,
  kể cả cạnh `#foreach`.
- Thiếu thẻ đóng của thẻ mang `Sao::tag`: lỗi.

Thứ tự hạ: `#` người dùng → `@` người dùng → `#` người dùng lần nữa, nên output
của `Sao::tag` dùng được `@directive` người dùng và ngược lại.

## 4. `transform()` không đụng vào `@verbatim` và comment

Registry che `{{-- … --}}` và `@verbatim … @endverbatim` trước khi thay, xong
mới khôi phục. Không có bước này trang tài liệu in ví dụ `@money(2)` sẽ bị
chính `@money` viết lại.

## 5. Nạp ở đâu

Một nguồn duy nhất: `Directive\DirectiveFiles`.

- **App:** `<root>/sao.directives.php`.
- **Thư viện:** khai trong `composer.json` của package —

  ```json
  "extra": { "saola": { "directives": "sao.directives.php" } }
  ```

  (`directives` nhận một chuỗi hoặc mảng đường dẫn, tính từ gốc package).

Thứ tự nạp: các package theo tên, rồi app.

| Đường compile | Nạp thế nào |
|---|---|
| Builder (`npm run build:views`, watch) | builder truyền `--directives=<projectRoot>` cho `saoc` (cả worker), **chỉ khi** project có app file hoặc package khai `extra.saola.directives` |
| CLI | `php bin/saoc compile in.sao --directives=<file\|thư-mục-project>[:...]` |
| Laravel (`SaolaCompilerServiceProvider`) | singleton `DirectiveRegistry` tự dò `base_path()` |

File phải `return` một iterable gồm `Sao::…`, `UserDirective`, `TagDirective`,
`BuiltinDirective`, hoặc cặp `'tên' => callable` (dạng cũ của `@`).

> ⚠️ File này là **code PHP**. Nạp nó = chạy code của app/thư viện/theme. Ở chế
> độ `sandbox: true`, `bin/saoc` **từ chối nạp**. Xem [04 §5](04-runtime-compile.md#5-bảo-mật-đọc-kỹ-mục-này).

## 6. Giới hạn

### `parse()` và `builtins()` KHÔNG nằm trên đường compile

`DirectiveRegistry::parse()` cùng 14 directive trong `builtins()` chỉ được gọi
từ cổng parity. Pipeline thật dùng thẳng `DirectiveParsers`. Chỉ nhánh người
dùng (`transform()`) thực sự chạy khi compile.

### `override: true` là thay VĂN BẢN, không phải thay parser

```php
$registry->directive('states', fn () => '', override: true);
// → @states({n:1}) bị xoá trước khi tới parser ⇒ view MẤT SẠCH state, không lỗi
```

Dùng `override` để viết lại cú pháp trước khi compile, đừng dùng để đổi ngữ
nghĩa directive lõi.

### `@` lạ không có cảnh báo

`@chua_dang_ky(x)` đi qua nguyên vẹn như văn bản. Namespace `#` thì ngược lại:
tên lạ là lỗi (§3.1).

### Parity chỉ so id tĩnh

`MarkerParity` bỏ qua id trong vòng lặp ở cả hai phía (chúng mang
`$loop->index`/`${…}`); id của khối lặp bao ngoài vẫn được so.

## 7. Kiểm thử

- `tests/Unit/Directive/UserDirectivePipelineTest.php` — fixture
  `fixtures/user-directives/` là **output thật** của compiler (`expected.blade`,
  `expected.js`), kèm `MarkerParity::diff` rỗng và soát có marker động thật.
  Sinh lại: `SAOC_UPDATE_FIXTURES=1 vendor/bin/phpunit --filter UserDirectivePipeline`
  rồi đọc diff trước khi commit.
- `builder/tests/directives.test.js` — `sao.directives.php` tới được `saoc`
  qua cả compile một lần lẫn worker.

Test directive mới của bạn bằng cách compile thật một `.sao` rồi so output —
chuỗi viết tay mù với khâu sinh code.
