# saola/compiler

Trình biên dịch `.sao` viết bằng PHP thuần. Cài qua Composer.

> **Trạng thái: P0–P6 xong. Byte parity đầu-cuối, đã chạy thật trong app.**
> `builder/src/index.js` gọi `vendor/bin/saoc` (có cả worker `serve`).
> `php artisan sao:compile` chạy được không cần Node.
> Bản Python **không còn trên đường build**. Bộ oracle/parity chỉ giữ cục bộ
> trong `tests/Parity/`, đã được `.gitignore` và không phân phối cùng package.
> Theme runtime (P7) chưa triển khai.



## Bằng chứng mạnh nhất

Compile lại **toàn bộ 56 view production** bằng `bin/saoc` rồi so với output đã
commit (do pipeline Python cũ sinh ra):

```
.blade.php   56 / 56   khớp từng byte
.js / .ts    55 / 56   khớp từng byte
```

Một file lệch là `web.modules.demo.index` — **đúng 4 hunk, tất cả là bug đã sửa**
(`__STR_LIT_0__` và `App.Helper.*` rò vào chuỗi hiển thị, xem §7 của roadmap).
Cùng số dòng, không có khác biệt nào khác. Output đã commit là bản CŨ còn lỗi.

```bash
composer test                 # PHPUnit, không cần Python
./tests/Parity/run-all.sh     # parity cục bộ, cần thư mục oracle bị ignore
```

## Trạng thái parity

Bản PHP phải khớp **từng byte** với compiler Python. Đã chứng minh:

```
hydrate-id/    ✅  26.960 / 26.960     mã hoá id, 4 mode
                                       6.740 base_id — trong đó 3.750 id THẬT
                                       bóc từ cả 56 view production
id-generator/  ✅ 100.000 / 100.000    bộ đếm theo scope
                                       5 seed × 20.000 thao tác ngẫu nhiên
expression/    ✅     750 / 750        chuyển biểu thức
                                       587 biểu thức THẬT bóc bằng spy gài vào
                                       php_to_js() + 163 ca tổng hợp nhắm nhánh
source-split/  ✅      85 / 85         tách file .sao         (oracle: JS)
balanced/      ✅     240 / 240        quét ngoặc              (oracle: JS)
symbol-collector/ ✅   85 / 85         bảng ký hiệu            (oracle: JS)
preprocessor/  ✅      85 / 85         Saola Syntax → Blade    (oracle: JS)
common-utils/  ✅     245 / 245        ScopedStyle, ChildrenSlot,
                                       ImportParser, TemplateStructure (oracle: PY)
declarations/  ✅      85 / 85         DeclarationTracker      (oracle: PY)
                                       56 file .sao thật + 29 fixture ca biên
import-tags/   ✅      62 / 62         ImportTagResolver       (oracle: PY)
                                       26 ca tổng hợp + 36 lượt trên view thật
hydrate-proc/ ✅     408 / 408        marker/class × 4 id mode (oracle: PY)
blade-emit/   ✅      85 / 85         56 view thật + 29 fixture (oracle: PY)
```

```bash
./tests/Parity/run-all.sh     # chỉ có trong workspace chuyển đổi cục bộ
```

## Đã có gì

```
src/
├── Support/
│   ├── Re.php                  bọc preg — lỗi thành exception, không null im lặng
│   ├── RegexException.php
│   └── PyStr.php               vị từ chuỗi kiểu Python (isDigit/isAlnum unicode)
├── Hydration/
│   ├── IdMode.php              enum: terse | compact | md5 | raw
│   ├── HydrateId.php           mã hoá id (hàm thuần — bất biến I2)
│   ├── HydrateIdScope.php      bộ đếm cho một cấp trong cây
│   ├── HydrateIdGenerator.php  stack scope, cấp id theo vị trí node
│   └── BladeHydrateProcessor.php hydrate class + marker SSR
├── Emit/
│   └── BladeEmitter.php        `.sao` đã ráp → output `.blade.php`
├── Expr/
│   ├── ExpressionCompiler.php  cửa vào: compile() và compileStatement()
│   ├── HelperResolver.php      hàm trần → App.Helper.* / App.View.* / this.view.*
│   ├── PhpJsBridge.php         mảng PHP, nối chuỗi, ->  (cầu nối bắt buộc, mọi file)
│   └── KnownFunctions.php      danh sách tên hàm — dữ liệu thuần
├── Source/
│   ├── SourceSplitter.php      .sao → khai báo / template / script / style
│   ├── SourceParts.php         value object kết quả
│   ├── WrapperScanner.php      tìm <template>/<blade>/<sao:blade> cấp ngoài cùng
│   └── WrapperTag.php          value object một thẻ bọc
├── Declaration/
│   ├── DeclarationTracker.php  quét @vars/@let/@const/@useState/@states/@computed
│   └── Declaration.php         value object một khai báo
├── Style/
│   └── ScopedStyle.php         scope CSS lúc biên dịch (hash djb2 theo codepoint)
├── Template/
│   ├── ImportParser.php        @import → bảng thẻ→đường dẫn
│   ├── ImportTagResolver.php   thẻ import → @include / @importInclude
│   ├── ChildrenSlot.php        hợp đồng @children / {{ $children }}
│   ├── TemplateStructure.php   kiểm cân bằng thẻ component
│   └── ChildrenSlotError.php / TemplateStructureError.php
└── Preprocessor/
    ├── Preprocessor.php        cửa vào 2 lượt + nhận dạng cú pháp
    ├── SymbolCollector.php     lượt 1 — quét khai báo
    ├── SymbolTable.php         bảng ký hiệu có phân tầng scope
    ├── Symbol.php / SymbolType.php
    ├── ExpressionTransformer.php  lượt 2 — dịch biểu thức + template
    ├── Tokenizer.php / Token.php / TokenType.php
    ├── ImportAliases.php       gỡ alias @import cho đường dẫn view
    ├── JsMethodMap.php         .length → count(), .join() → implode()
    └── PhpBuiltins.php         hàm PHP có sẵn — không thêm '$'
```

`Support/LiteralMask.php` giữ bất biến "khôi phục lớp ngoài trước lớp trong" —
nơi duy nhất biết về thứ tự, sau khi bug rò `__STR_LIT_` được sửa.

**Phase 1, 2 và 3 đã xong.** Phase 3 đạt 8/8 module đang chạy.

Xong: `ScopedStyle`, `ChildrenSlot`, `ImportParser`, `ImportTagResolver`,
`TemplateStructure`, `DeclarationTracker`, `BladeHydrateProcessor`,
`BladeEmitter`. `ReactiveWrapper` (709 dòng) không port: chỉ còn một import chết,
không được khởi tạo/gọi; `BladeHydrateProcessor` đã thay thế nó trong pipeline.

Hạ tầng cổng `blade-emit/` đã nối vào suite và đạt parity. Phạm vi thật của
Phase 3 rộng hơn con số 2.429 dòng của `sao2blade/`:
nó kéo theo `common/declaration_tracker` (718), `import_tag_resolver` (414),
`import_parser` (287), `scoped_style`, `children_slot`, `template_structure` —
tổng khoảng 4.500–5.000 dòng.

## Vì sao có package này

Compiler hiện tại là Python, được Node spawn ra — nên chỉ chạy được lúc build.
Bản PHP mở ra: compile in-process, không cần Python3/Node ở production, và về sau
là cài theme từ trang admin mà không cần redeploy.

Phần thưởng ngoài dự kiến: bản Python duyệt cây **hai lần độc lập** (một cho
Blade, một cho JS) rồi hai bên phải tự khớp marker id với nhau. Bản PHP duyệt
**một lần, hai bộ phát mã**, dùng chung một allocator — marker desync trở thành
trạng thái không biểu diễn được.

## Chuẩn code

PSR-4 (`Saola\Compiler\` → `src/`), PSR-12, `declare(strict_types=1)` mọi file,
`final` mặc định, không static mang trạng thái. Chi tiết:
[docs/06-coding-standards.md](docs/06-coding-standards.md).

```bash
php -l $(find src -name '*.php')      # cú pháp
composer dump-autoload -o --strict-psr   # PSR-4
./tests/Parity/run-all.sh             # parity cục bộ (bị Git ignore)
```

## Tài liệu

| Doc | Nội dung |
|---|---|
| [00-overview.md](docs/00-overview.md) | Mục tiêu, quyết định đã chốt, câu hỏi mở |
| [01-architecture.md](docs/01-architecture.md) | Pipeline, bản đồ module, phạm vi port |
| [02-public-api.md](docs/02-public-api.md) | `SaolaCompiler` — API dùng chung cho Node + web |
| [03-directives.md](docs/03-directives.md) | Cơ chế đăng ký directive |
| [04-runtime-compile.md](docs/04-runtime-compile.md) | Compile runtime, cài theme *(Phase 7)* |
| [05-roadmap.md](docs/05-roadmap.md) | Lộ trình, cổng parity, bẫy khi port |
| [06-coding-standards.md](docs/06-coding-standards.md) | Chuẩn PSR + quy ước riêng |
| `tests/Parity/README.md` *(local, ignored)* | Cách hoạt động của cổng đối chiếu chuyển đổi |
