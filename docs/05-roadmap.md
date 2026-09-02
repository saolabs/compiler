# 05 — Lộ trình & bẫy khi port

> **Ghi chú vị trí (sau khi di trú thư viện):** các đường `compiler/src/*` bên dưới
> là layout của bản Python/JS **cũ**. Bản Python **đã gỡ hẳn** — các cổng trong
> `tests/Parity/` nay so với ảnh chụp golden thay vì với oracle Python, và dự án
> chỉ còn PHP và JS/TS. `compiler/src/index.js` đã thành `builder/src/index.js`;
> thư mục `compiler/` hiện là package PHP `saola/compiler`.

## 1. Nguyên tắc dẫn đường

**Parity byte-for-byte là cổng của mọi phase.** Không phase nào được coi là xong
nếu output PHP chưa khớp từng byte với output Python trên toàn bộ 56 view thật.

Compiler Python ở lại repo suốt cuộc port, đóng vai **oracle**. Nó không phải nợ
kỹ thuật trong giai đoạn này — nó là bộ test.

**Hệ quả về thứ tự công việc:** port trước, thiết kế lại sau. Cụ thể, directive
registry được port **vào đúng cấu trúc đích** (mỗi directive một class, dispatch
bằng bảng tra) nhưng **chưa mở API công khai** cho tới Phase 5. Viết 70 class nhỏ
không tốn hơn viết một chuỗi `if` 1.600 dòng, mà lại không phải viết hai lần.

## 2. Bộ đối chiếu (dựng ở Phase 0, dùng suốt)

Cả 20 test hiện có trong `compiler/tests/` **đã là black-box** — ghi `.sao`, gọi
CLI, so chuỗi output. Chỉ cần đưa lệnh CLI ra biến môi trường là cùng bộ test
chạy được cho cả hai bản cài đặt.

```bash
# Oracle (hiện tại)
SAO_CLI="python3 compiler/src/sao2js/cli.py"  python3 compiler/tests/test_foreach_key.py

# Bản mới
SAO_CLI="php compiler/bin/saoc compile"   python3 compiler/tests/test_foreach_key.py
```

Cổng thật nằm ở kịch bản diff toàn bộ:

```bash
compiler/tests/Parity/diff-all.sh
# với mỗi .sao trong saola/resources/saola/**:
#   compile bằng Python  → /tmp/oracle/{view}.{blade.php,js}
#   compile bằng PHP     → /tmp/new/{view}.{blade.php,js}
#   diff -u; đếm số byte lệch
# In bảng: view | blade khớp? | js khớp? | dòng lệch đầu tiên
```

Chạy trong CI mỗi commit. Số byte lệch là thước đo tiến độ duy nhất đáng tin.

### Cổng nghiệm thu đầu-cuối

`tests/Parity/full-pipeline/` là cổng duy nhất đi qua **API công khai**
`SaolaCompiler::compile()`. Các cổng khác kiểm từng module; thứ tự RÁP các mảnh
có thể sai dù mọi mảnh đều đúng, và không cổng nào khác chạm tới đường đó.

Oracle là pipeline CŨ đầy đủ: Node ráp đầu vào (`parseSaoFile` → `preprocess` →
ráp → `injectSsrStylesheets`), Python sinh output (`sao2blade` + `sao2js`).

⚠️ `lang` phải được DÒ TỪ NGUỒN (`<script setup lang="ts">`), không ép từ ngoài:
`cli.py` chỉ nhận `(in, out, fn, view, factory)` và `main_compiler` tự dò TS.
Ép lang từ ngoài là so hai thứ khác nhau — đã mắc một lần khi dựng cổng này.

## 3. Các phase

> **Ưu tiên hiện tại: chạy được và cho kết quả GIỐNG HỆT bản cũ.**
> Compile lúc runtime và cài theme đẩy về cuối (P7) — thiết kế đã có sẵn ở
> [04-runtime-compile.md](04-runtime-compile.md) nhưng không chặn gì trước đó.

| Phase | Nội dung | Trạng thái |
|---|---|---|
| P0 | Chuẩn bị, harness đối chiếu | 🟡 còn việc dọn dẹp |
| P1 | Nền móng | ✅ xong |
| P2 | Preprocessor | ✅ xong |
| P3 | Blade emitter | ✅ xong |
| P4 | Parser + JS emitter | ✅ xong |
| P5 | Registry công khai + tích hợp Laravel | ✅ xong |
| P6 | Worker mode, gỡ đường Python | ✅ xong — Python KHÔNG còn trên đường build, giữ lại làm oracle cho 28/30 cổng |
| P7 | Runtime theme | ⬜ CHƯA triển khai (theo quyết định giai đoạn này) |

### Snapshot tiến độ — 2026-08-30

Đây là điểm tiếp tục chính thức cho phiên làm việc kế tiếp.

**Đã hoàn thành và kiểm chứng:**

- P4.1–P4.6 đã port sang PHP: AST/parser, directive processors, template
  processors, JS emitter, compiler support và `MainCompiler`.
- `compiler-support/` khớp **98/98** phép gọi.
- `main-compiler/` khớp byte-for-byte **95/95** view (10 ca tổng hợp + 85
  source thật/fixture).
- Template `view.js` và `wraper.js` đã được đóng gói trong
  `compiler/resources/templates/`; package không còn bắt buộc đọc template
  từ cây `compiler/` cũ.
- Public API nền tảng đã có: `SaolaCompiler`, `CompileOptions`, `CompileResult`,
  `BatchResult`, `Target`, `Lang`, `CompileException`.
- `SaolaCompiler::compile()` ghép pipeline đầy đủ
  `SourceSplitter → Preprocessor → BladeEmitter/MainCompiler`, sinh Blade và JS
  trong cùng một lần gọi. `idMode` được truyền tường minh cho cả hai emitter để
  tránh lệch marker SSR/CSR.
- CLI `bin/saoc` đã có ba lệnh `compile`, `compile-dir`, `serve`; worker
  `serve` dùng NDJSON và đã thử thành công nhiều request đồng thời.
- `compiler/src/index.js` đã chuyển sang một lần gọi PHP cho mỗi file; watch mode
  dùng PHP worker thường trú. Không còn đường spawn Python trong file này.
- `compileDirectory()` đã có giới hạn số file/dung lượng/timeout, ghi output
  nguyên tử theo file, và trả manifest `{revision, views}`.
- Sandbox nền tảng đã chặn `@php`, `@exec`, nhóm hàm nguy hiểm, import traversal
  và giới hạn include.

**Đã chạy gate gần nhất:** toàn bộ cổng P1–P4 trong `tests/Parity/run-all.sh`
đều xanh tới cổng main compiler. Việc đóng gói template từng làm dư một newline;
đã sửa và chạy lại `main-compiler/run.sh`, kết quả **95/95 byte parity**.
Vẫn phải chạy lại toàn bộ suite sau khi hoàn tất P5–P7.

**Phần còn lại:**

- P5: Laravel `ServiceProvider`, Artisan `sao:compile`, hoàn thiện hợp đồng
  directive phức tạp/attribute, và gate custom directive end-to-end.
- P6: thêm gate CLI/NDJSON/Node integration, kiểm thử build/watch thật, rồi xác
  nhận Python chỉ còn là oracle CI.
- P7: nối manifest runtime vào `client/ViewManager`, import map, staging/đổi chỗ
  nguyên tử cấp theme, cache invalidation và gate sandbox/runtime theme.
- Cuối cùng: chạy toàn bộ parity, PHPUnit, Node/client tests, cập nhật README và
  chỉ đánh dấu hoàn tất khi tất cả gate xanh.

### P0 — Chuẩn bị · ~1 tuần · 🟡

- [x] Khung composer package: PSR-4 `Saola\Compiler\` → `src/`, PHP ^8.3, zero dep runtime
- [x] Hạ tầng cổng parity: `tests/Parity/` — oracle Python / subject PHP / diff
- [ ] Xoá `compiler/src/python/_old_flat/` (13.395 dòng dead code). **Đã xác minh không file nào import nó.**
- [ ] `diff-all.sh` — so output cả `.blade.php` lẫn `.js` trên toàn bộ 56 view. Chỉ dựng được khi đã có emitter (P3)
- [ ] Tham số hoá 20 test hiện có qua `SAO_CLI`
- [ ] Chốt Q3 (`SAOLA_ID_MODE`) trong [00-overview.md](00-overview.md#5-câu-hỏi-còn-mở--cần-chốt-trước-phase-3). Q1/Q2/Q5 chỉ liên quan tới P7, chưa cần gấp

### P1 — Nền móng · ~2 tuần · ✅

- [x] `Support/Re` — bọc preg, lỗi thành exception thay vì null im lặng
- [x] `Hydration/IdMode` — enum 4 mode, giá trị lạ rơi về md5 (khớp Python)
- [x] `Hydration/HydrateId` — mã hoá thuần (`compact` / `terse` / `md5` / `raw`)
- [x] `Hydration/HydrateIdScope` + `HydrateIdGenerator` — bộ đếm theo scope
- [x] `Support/PyStr` — vị từ chuỗi kiểu Python (`isDigit`/`isAlnum` có hiểu unicode)
- [x] `Expr/KnownFunctions` — danh sách tên hàm (View / known / JS builtin)
- [x] `Expr/HelperResolver` — phân giải hàm về `App.Helper.*` / `App.View.*` / `this.view.*` + cảnh báo hàm lạ
- [x] `Expr/LegacyPhpSyntax` — mảng PHP, nối chuỗi, `->`, `(array)`
- [x] `Expr/ExpressionCompiler` — điều phối + đổi tên định danh `loop`
- [x] `Source/SourceSplitter` + `SourceParts` + `WrapperScanner` + `WrapperTag` (port từ `index.js:parseSaoFile`)
- [x] `Support/LiteralMask` — bất biến thứ tự khôi phục literal (xem §7)

> **Không port nguyên tên `php_to_js`.** Với cú pháp Saola hiện đại, phần
> "PHP → JS" gần như pass-through (`item.name` → `item.name`). Việc thật mà
> `php_converter.py` (469) + `php_js_converter.py` (782) còn làm là **phân giải
> hàm về helper runtime** (`count(items)` → `App.Helper.count(items)`,
> `route("home")` → `App.View.route("home")`) và cảnh báo hàm không khớp
> `<script setup>` — cả hai đều áp dụng cho cú pháp mới. Phần PHP thật sự chỉ là
> lớp tương thích cho file chưa chuyển cú pháp.
>
> Tách hai module để ranh giới đó nhìn thấy được: bỏ cú pháp PHP cũ về sau thì
> xoá được `LegacyPhpSyntax`, còn `HelperResolver` ở lại vĩnh viễn.
>
> **Đính chính (§11):** dòng trên SAI. Class này (đổi tên `PhpJsBridge`) là
> cầu nối PHP↔JS bắt buộc cho mọi file, không phải đường tương thích ngược —
> không thể xoá. Cái ĐÃ bỏ được là cơ chế phát hiện + thẻ `<blade>` (§11).

**Cổng — ĐÃ ĐẠT cho phần hydration:**

```
hydrate-id/    26.960/26.960 khớp   (6.740 base_id × 4 mode)
                                     — 3.750 id THẬT bóc từ cả 56 view production
                                     — 3.000 id tổng hợp nhắm ca biên
id-generator/  100.000/100.000 khớp (5 seed × 20.000 thao tác ngẫu nhiên)
expression/    750/750 khớp         — 587 biểu thức THẬT (spy gài vào php_to_js
                                       rồi compile cả 56 view)
                                     — 163 ca tổng hợp nhắm đúng nhánh biến đổi
source-split/  72/72 khớp           — 56 file .sao thật + 16 fixture ca biên
                                     — oracle là JS (index.js), không phải Python
```

**Phase 1 XONG.** Cổng đã kiểm "có răng": phá `jsTrim()` thành `trim()` của PHP
thì fixture 14 đỏ ngay. Xem [tests/Parity/README.md](../tests/Parity/README.md).

Đây là bất biến I2, và nó đã được chứng minh trước khi bất kỳ emitter nào được
viết. Sai ở đây thì mọi thứ phía sau đều sai.

**Hai bug thật phát hiện khi dựng cổng biểu thức, đã sửa ở cả hai bản cài đặt**
— xem [§7](#7-hai-bug-đã-sửa).

### P2 — Preprocessor · ~1,5 tuần · ✅

Port `preprocessor/*.js` (~2.100 dòng). **Port từ JS, không phải từ Python.**

- [x] `Support/Balanced` — quét ngoặc, **hai biến thể không thay nhau được**
- [x] `Preprocessor/SymbolCollector` + `SymbolTable` + `Symbol` + `SymbolType`
- [x] `Preprocessor/Tokenizer` + `Token` + `TokenType`
- [x] `Preprocessor/ExpressionTransformer`
- [x] `Preprocessor/ImportAliases` + `JsMethodMap` + `PhpBuiltins`
- [x] `Preprocessor/Preprocessor` — `preprocess()` và `preprocessRaw()`

**Cổng — ĐÃ ĐẠT:**

```
balanced/          240/240   48 chuỗi méo × 5 phép quét
symbol-collector/   85/85    56 file thật + 29 fixture
preprocessor/       85/85    output khớp byte-for-byte với bản JS
```

Bản JS có HAI cài đặt khác nhau cho cùng ý tưởng quét ngoặc: SymbolCollector
đếm `()`, `[]`, `{}` bằng ba bộ đếm riêng, ExpressionTransformer gộp một. Với
`(a]` thì bản gộp coi là cân bằng còn bản tách thì không. Gộp lại là đổi hành
vi — `Support/Balanced` giữ cả hai và ghi rõ chỗ nào dùng bản nào.

Bốn thứ trong bản JS là mã chết, không port: `_processTemplateLiterals` và
`_isInsideObjectLiteral` không ai gọi; `token.transformedTo` và
`token._isProperty` chỉ được ghi, không nơi nào đọc.

### P3 — Blade emitter · ~2 tuần · ✅

`sao2blade/` (2.429 dòng) + phần `common/` liên quan → `Emit/BladeEmitter`.

Tiến độ module: **8/8 module đang chạy** — `ScopedStyle`, `ChildrenSlot`,
`ImportParser`, `ImportTagResolver`, `TemplateStructure`, `DeclarationTracker`,
`BladeHydrateProcessor`, `BladeEmitter`. `ReactiveWrapper` không port vì là mã
chết: chỉ được import, không có caller; pipeline ghi rõ `BladeHydrateProcessor`
đã thay thế nó.

**Cổng:** `diff-all.sh` cho **0 byte lệch trên toàn bộ `.blade.php`** của 56 view.

**Đã đạt:** `blade-emit/` khớp 85/85 (56 view thật + 29 fixture), cùng cổng
`hydrate-processor/` khớp 408/408 trên đủ bốn mode id.

Đây là phase chứng minh được điều quan trọng nhất: **marker khớp trong ngữ cảnh
thật**. Nếu Blade emitter cho đúng dãy marker mà Python sinh ra, phần rủi ro nhất
của cuộc port đã xong, trong khi mới đi 15% khối lượng.

Ship sau phase này bằng cờ `SAO_BLADE_ENGINE=php`, giữ Python cho JS. Bản PHP
chạy thật trên đường blade, có đường lùi một biến môi trường.

### P4 — Parser + JS emitter · ~4–5 tuần · ✅

Phần lớn nhất: `sao2js/` (16.094 dòng) → `Ast/` + `Emit/JsEmitter` +
`Directive/Builtin/` (~70 class).

Mỗi module một PR, `diff-all.sh` không được tăng số lệch. Thứ tự theo phụ thuộc:

1. `template_ast.py` (1.601) → `Ast/Parser` + các node class
2. `parsers.py` (1.558) → `Directive/Builtin/` (mỗi directive một class)
3. `template_processor.py` (1.556) + `template_processors.py` (1.504)
4. `render_generator.py` (1.273) → `Emit/JsEmitter`
5. `event_directive_processor.py` (1.242)
6. `main_compiler.py` (2.986) → điều phối, làm cuối

**Cổng mục tiêu ban đầu:** `diff-all.sh` 0 byte lệch trên **cả `.blade.php` lẫn
`.js`**, 56/56 view, cộng với 20 test hiện có chạy xanh khi trỏ `SAO_CLI` sang
bản PHP. Harness thực tế đã được tách thành các gate nhỏ trong
`tests/Parity/run-all.sh`; kết quả P4 hiện tại được ghi ở danh sách bên dưới.

> Directive được port **vào đúng cấu trúc đích** ngay từ đây — mỗi directive một
> class, dispatch bằng bảng tra — nhưng **chưa mở API công khai** cho tới P5.
> Viết 70 class nhỏ không tốn hơn viết chuỗi `if` 1.600 dòng, mà không phải làm
> hai lần.

**Kết quả thực tế:**

- [x] `Ast/Parser` và toàn bộ node cần cho corpus hiện tại
- [x] Directive parsers/processors, conditional/loop/section/event handlers
- [x] `TemplateProcessor`, `TemplateProcessors`, `TemplateAnalyzer`
- [x] `Emit/JsEmitter`
- [x] `CompilerUtils`, `WrapperParser`, `RegisterParser`, `FunctionGenerators`
- [x] `Compiler/MainCompiler`
- [x] Cổng compiler support: **98/98**
- [x] Cổng main compiler: **95/95 byte parity**

### P5 — Registry công khai + tích hợp Laravel · ~1,5 tuần

Mở `DirectiveRegistry` ra ngoài, `SaolaCompilerServiceProvider`,
`php artisan sao:compile`, đổi `index.js` sang gọi `php bin/saoc`
(112 spawn → 56, mỗi spawn cũng rẻ hơn).

**Cổng:** parity vẫn 0 lệch (registry là refactor, không được đổi output) + một
directive tự tạo chạy end-to-end trong app thật.

**Tiến độ:**

- [x] Public `SaolaCompiler` và các DTO/enum kết quả
- [x] `compile()`, `compileFile()`, `compileDirectory()`
- [x] Public `DirectiveRegistry` nền tảng và inline/block custom directive
- [x] CLI `bin/saoc compile` trả `CompileResult` dạng JSON
- [x] Node gọi PHP một lần và nhận đồng thời Blade + JS
- [ ] Hoàn thiện interface directive phức tạp + attribute directive theo
  [03-directives.md](03-directives.md)
- [ ] `SaolaCompilerServiceProvider`
- [ ] Artisan `php artisan sao:compile`
- [ ] Gate custom directive end-to-end trong app Laravel

### P6 — Worker mode + gỡ Python · ~1 tuần

`saoc serve` (NDJSON) cho watch mode, gỡ đường Python khỏi `index.js`, chuyển
compiler Python sang thư mục chỉ dùng cho CI.

**Đến đây là xong mục tiêu "chạy được, giống hệt bản cũ".**

**Tiến độ:**

- [x] `saoc serve` dùng NDJSON
- [x] Node watch mode giữ PHP worker thường trú
- [x] Gỡ mọi đường spawn Python khỏi `compiler/src/index.js`
- [ ] Gate protocol NDJSON: request thành công, lỗi, nhiều request liên tiếp và
  request song song
- [ ] Gate Node build/watch trên project fixture
- [ ] Chuyển compiler Python thành oracle CI được ghi rõ trong cấu trúc/test docs

### P7 — Runtime theme · ~2 tuần · 🟡 nền móng

Chỉ nghiệm thu khi P0–P6 xong và parity đã sạch qua một chu kỳ release. Một số
nền móng độc lập đã được chuẩn bị trước. Thiết kế đầy đủ ở
[04-runtime-compile.md](04-runtime-compile.md). Cần chốt Q1 (theme có được chứa
PHP không) và có công việc kèm theo bên `client/`.

**Tiến độ:**

- [x] Batch result + manifest JSON `{revision, views}`
- [x] Giới hạn `max_files`, `max_file_bytes`, `max_total_bytes`, timeout và
  include depth ở public options/pipeline
- [x] Sandbox nền tảng và cấm nạp directives file khi chạy CLI sandbox
- [x] Atomic write cho output file
- [ ] Staging toàn batch và đổi chỗ theme nguyên tử chỉ khi không có lỗi
- [ ] Client `ViewManager` đọc manifest động và lazy `import(url)`
- [ ] Import map cho `@saolabs/client`
- [ ] Laravel queue/install flow và cache invalidation (view, Octane, revision,
  browser)
- [ ] Gate runtime theme: compile lỗi không chạm theme hiện hành, sandbox từ
  chối input nguy hiểm, manifest hydrate đúng không cần Node build

## 4. Ước lượng

| | |
|---|---|
| Đạt parity, thay được bản cũ (P0–P6) | **~11–12 tuần** |
| Cộng runtime theme (P7) | **~14–15 tuần** |

Một người, làm liên tục. P4 dễ trượt nhất — chiếm 60% khối lượng và là nơi mọi
khác biệt ngữ nghĩa Python/PHP lộ ra.

P7 còn phụ thuộc công việc phía `client/` không nằm trong ước lượng này.

## 5. Bẫy khi dịch Python → PHP

Xếp theo mức nguy hiểm thật, rút ra từ khảo sát mã nguồn.

### ① Index theo codepoint (Python) vs theo byte (PHP) — nguy hiểm nhất

319 phép cắt chuỗi, 313 `len()`, 51 `.find()`. Template chứa tiếng Việt.

**Cách xử lý: đi thuần byte, nhất quán tuyệt đối.** `substr`/`strlen`/`strpos`,
`preg` **không** cờ `/u`. Mọi delimiter compiler đi tìm đều là ASCII, và UTF-8 tự
đồng bộ — nên offset byte luôn rơi đúng biên ký tự.

**Tuyệt đối không trộn `mb_*` với offset lấy từ `preg`.** Trộn là hỏng ngầm, và
chỉ hỏng khi gặp file có dấu tiếng Việt.

Ngoại lệ phải soát riêng: 115 chỗ `\w` + 129 chỗ `\b`. Khi subject có thể là text
người dùng (text node), Python nhận diện được `chào` là một từ, PCRE không `/u`
thì không. Đánh dấu từng chỗ, thêm `/u` nơi thật sự cần.

### ② Type juggling — nơi bug im lặng sinh ra

474 phép so với `None` → phải là `=== null`. `if not x` của Python **không** bằng
falsy của PHP: `"0"` là falsy trong PHP, truthy trong Python. Với compiler đầy
những chuỗi như `"0"`, `""`, `"false"`, đây là bẫy thật.

Bật `declare(strict_types=1)` mọi file. Dùng `===`/`!==` mặc định.

### ③ Sai lệch nhỏ giữa hàm chuỗi

| Python | PHP | Khác nhau ở đâu |
|---|---|---|
| `.strip()` (729 chỗ) | `trim()` | PHP không cắt `\f` và whitespace unicode |
| `re.escape()` (31) | `preg_quote()` | tập ký tự escape khác nhau |
| `re.sub(fn)` | `preg_replace_callback` | PHP truyền array, không phải match object |
| `s[a:b]` out-of-range | `substr` | cả hai đều dễ tính, nhưng `substr` trả `""` chỗ Python trả `""` — kiểm tra biên âm |
| `str.split(sep)` | `explode` | Python `.split()` không đối số cắt theo mọi whitespace; `explode` bắt buộc có sep |

`preg_*` trả `null` khi lỗi và **không ném exception**. Bọc lại một lớp:
`Support\Re::sub()` ném khi `preg_last_error() !== PREG_NO_ERROR`. Một lần bọc,
dùng cho cả ~740 chỗ.

### ④ Regex — tin tốt

740 chỗ gọi regex, và tất cả đều: group đánh số (không named group), không
`re.VERBOSE`, không `\A`/`\Z`, không ký tự non-ASCII trong pattern. Đây là kịch
bản dễ nhất có thể khi chuyển `re` sang PCRE. Việc chính chỉ là thêm delimiter và
escape.

### ⑤ Không có bẫy ở những chỗ tưởng có

Đã kiểm tra và loại trừ:

- **Không generator nào** — 163 chỗ `yield` đều là directive `@yield`, không phải Python
- **Zero dependency ngoài stdlib** — chỉ `re, os, sys, json, subprocess, string, random, html, hashlib`
- **Hash port chính xác** — `md5(s.encode())[:8]` → `substr(md5($s), 0, 8)`
- **Thứ tự dict được giữ** ở cả hai ngôn ngữ; `usort` của PHP ổn định từ 8.0
- Không dataclass, không metaclass, 6 lambda, 7 walrus

### ⑥ Trạng thái toàn cục — có tiền lệ thật

`php_js_converter.py` là singleton module-level và **đã từng rò method của view
trước sang view sau** (đọc comment `FIX(F3)` trong file đó). Dưới Octane một
worker sống hàng nghìn request, nên lỗi kiểu này sẽ nặng hơn nhiều.

Khi port: **không static, không singleton mang state.** Mọi state per-compile
nằm trong object context tạo mới mỗi lần `compile()`. Xem
[02 §5 quy tắc 4](02-public-api.md#5-quy-tắc-thiết-kế-bắt-buộc).

## 6. Rủi ro & đường lùi

| Rủi ro | Giảm thiểu |
|---|---|
| P4 trượt tiến độ | P3 đã ship được độc lập qua `SAO_BLADE_ENGINE`. Bản PHP có giá trị dù P4 chưa xong |
| Marker lệch phát hiện muộn | Cổng fuzz ở P1, cổng blade ở P3 — cả hai đều trước phase lớn |
| Client chưa kịp hỗ trợ manifest | Chặn P6, không chặn P0–P5. Lên lịch phía `client/` từ đầu P4 |
| Phát hiện khác biệt ngữ nghĩa không lường trước | `diff-all.sh` bắt được ngay ở commit gây ra, không phải ở production |
| Compiler Python bị sửa trong lúc port | Đóng băng `compiler/src/` — chỉ vá lỗi nghiêm trọng, và mỗi lần vá thì port ngay sang PHP cùng lúc |

Đường lùi ở mọi phase: biến môi trường trỏ về Python. Đường đó chỉ bị gỡ ở P7,
sau khi parity đã sạch qua một chu kỳ release.

## 7. Sáu bug đã sửa

Dựng cổng parity cho biểu thức làm lộ ra hai lỗi có thật trong compiler Python.
Cả hai **đã đi vào output production đã commit**
(`saola/resources/js/saola/web/views/modules/demo/index.ts`):

```
dòng 571:  "source": "&#64;if(status === __STR_LIT_0__)"
dòng 488:  "source": "&#64;App.Helper.bind(name)"
dòng 734:  "source": "&#64;App.Helper.states({ count: 3 })"
dòng 772:  "source": "&#64;App.Helper.click(App.Helper.setCount(3)) · ..."
```

Nguồn — `saola/resources/saola/web/views/modules/demo/index.sao:97`:

```blade
<featurecard number="06" title="If / elseif / else"
             source="&#64;if(status === 'ready')" tone="yellow">
```

Prop `source` là chuỗi chứa code mẫu **để hiển thị**, không phải biểu thức cần dịch.

### ① Rò placeholder `__STR_LIT_0__`

`_handle_string_concatenation` che string literal bằng placeholder rồi khôi phục
theo **thứ tự index tăng dần**:

```python
for i, literal in enumerate(string_literals):
    expr = expr.replace(f"__STR_LIT_{i}__", literal)
```

Nháy đơn được che TRƯỚC nháy kép, nên chuỗi bọc ngoài luôn mang index cao hơn
phần nằm trong nó. `'ready'` thành `__STR_LIT_0__`, rồi cả chuỗi ngoài thành
`__STR_LIT_1__`. Vòng i=0 chạy khi `__STR_LIT_0__` vẫn đang bị giấu bên trong
`__STR_LIT_1__`; tới i=1 nó mới lộ ra thì con trỏ đã đi qua.

**Sửa:** khôi phục theo thứ tự GIẢM DẦN — lớp ngoài trước lớp trong.

### ② Gắn tiền tố helper vào bên trong chuỗi hiển thị

`_add_function_prefixes` chạy regex trên toàn biểu thức, không phân biệt phần
nào nằm trong string literal.

**Sửa:** che string literal trước khi gắn tiền tố. CHỈ che nháy đơn và nháy kép
— template literal (backtick) phải để nguyên vì `${...}` bên trong nó là biểu
thức thật, vẫn cần gắn tiền tố.

### Đã sửa ở CẢ HAI bản cài đặt cùng lúc

Sửa một bên là cổng parity đỏ và mất khả năng bắt hồi quy. Nên cả hai đi cùng
một commit:

| | |
|---|---|
| Python | `compiler/src/common/php_js_converter.py` — 2 vòng khôi phục đảo chiều, tách `_prefix_bare_calls()` ra sau lớp che |
| PHP | `Support/LiteralMask` — **bất biến thứ tự chỉ viết một lần**, dùng ở cả 3 chỗ |

Bug sinh ra vì vòng khôi phục bị chép ở ba nơi và chỉ cần một chỗ quên đảo thứ
tự là lỗi quay lại. Bản PHP không chép nữa: `LiteralMask::unmask()` là nơi duy
nhất biết về thứ tự.

**Kiểm chứng:**

```
compile lại demo/index.sao   →  4 chỗ hỏng  →  0
cổng parity                  →  750/750 vẫn xanh
test Python                  →  13/7 và 6/3, y hệt baseline
```

---

## Bug ③ và ④ — nhánh `@children` không có `@vars`/`@props`

Cả hai nằm ở `MainCompiler` (PHP) / `main_compiler.py` (Python), trong đoạn gắn
`__ONE_CHILDREN_CONTENT__` vào khai báo vars. Cả hai đều **tiềm ẩn**: giá trị
hỏng chỉ đi vào phần phân tích và prerender, chưa view nào hiện có làm nó lộ ra
output — nên 27 cổng parity vẫn xanh trong lúc lỗi đã nằm sẵn đó.

### ③ Nội suy `$DATA` trong chuỗi nháy kép của PHP  *(chỉ có ở bản PHP)*

```php
: "let {__ONE_CHILDREN_CONTENT__ = ''} = $$$DATA$$$ || {};";   // SAI
```

PHP nội suy trong nháy kép. Trong `$$$DATA$$$`, hai `$` đầu là ký tự thường
(theo sau là `$`, không phải định danh), còn `$` thứ ba theo sau là `DATA` nên
PHP hiểu là **biến `$DATA`**. Kết quả:

```
Warning: Undefined variable $DATA
let {__ONE_CHILDREN_CONTENT__ = ''} = $$$$$ || {};      ← 5 dấu $, mất placeholder
```

Bản Python cũng dùng nháy kép nhưng `$` trong Python là ký tự thường, nên bản
gốc đúng. Đây là bẫy chỉ xuất hiện khi port sang PHP.

**Sửa:** đổi sang nháy đơn. Dòng 132 ngay phía trên vốn đã dùng nháy đơn — chỉ
nhánh `else` bị sót.

> **Quy tắc rút ra:** mọi chuỗi chứa placeholder `$$$...$$$` PHẢI dùng nháy đơn.
> Đã quét toàn bộ `src/`: không còn chỗ nào khác.

### ④ Thiếu cờ DOTALL ở bản Python  *(chỉ có ở bản Python)*

```python
re.sub(r'(let\s*\{)(.*?)(\}\s*=\s*\$\$\$DATA\$\$\$)', ..., vars_declaration)
```

Không có `re.DOTALL` nên `.*?` không vượt qua xuống dòng. Với `@vars` có giá trị
mặc định nhiều dòng:

```blade
@vars({
  list: [ 1, 2 ]
})
```

`vars_declaration` xuống dòng → regex KHÔNG khớp → `__ONE_CHILDREN_CONTENT__`
không được thêm vào → nó thành biến không tồn tại trong prerender.

Bản PHP dùng cờ `/s` nên vẫn khớp. Ở đây **PHP mới đúng**, nên sửa Python bằng
cách thêm `flags=re.DOTALL` — chứ không gỡ `/s` của PHP.

**Kiểm chứng:**

```
fixture 30 (children, không vars)          → cổng full-pipeline xanh
fixture 31 (children, không vars, @await)  → cổng full-pipeline xanh
fixture 32 (children, @vars nhiều dòng)    → cổng full-pipeline xanh
28 cổng parity                             → xanh hết
test Python                                → 13/7 và 6/3, y baseline
so với output đã commit                    → blade 56/56, js 55/56 (1 = bug ①②)
```

---

## Bug ⑤ và ⑥ — lộ ra khi build lại toàn bộ

28 cổng parity đều xanh mà build thật vẫn hỏng. Lý do: cổng gọi `bin/saoc` hoặc
`SaolaCompiler::compile()` trực tiếp, **không đi qua đường vận chuyển Node↔PHP**
của `index.js`. Đó là vùng không cổng nào phủ.

### ⑤ Vỡ UTF-8 ở ranh giới chunk  *(index.js — nghiêm trọng nhất tới giờ)*

```js
child.stdout.on('data', data => { stdout += data.toString(); });   // SAI
```

`data.toString()` giải mã **TỪNG CHUNK RIÊNG LẺ**. Ký tự UTF-8 nhiều byte nằm
vắt qua ranh giới chunk bị tách làm đôi, mỗi nửa thành một ký tự thay thế.

Hỏng thật trong output đã build:

```
mong đợi : ... có sẵn nhánh cho danh sách rỗng ...
thực tế  : ... có sẵn nh<?><?>nh cho danh sách rỗng ...
byte     : 6e 68  EF BF BD EF BF BD  6e 68        (á = C3 A1 bị vỡ)
```

Chỉ hiện ở file đủ lớn để stdout bị chia chunk (~58 KB trở đi) VÀ có ký tự
tiếng Việt đúng chỗ ranh giới. Gọi `saoc compile` trực tiếp thì đúng — nên mọi
cổng đều xanh.

**Sửa:** `stream.setEncoding('utf8')` thay cho `data.toString()`. Node dùng
`StringDecoder`, nó giữ lại byte dở dang chờ chunk sau. Sửa ở CẢ HAI chỗ:
`compileWithPhp` (một lần) và `startPhpWorker` (NDJSON).

> **Quy tắc rút ra:** không bao giờ `.toString()` trên chunk stream rời rạc.
> Hoặc `setEncoding`, hoặc gom `Buffer` rồi `Buffer.concat().toString()`.

### ⑥ Registry sinh ra không tái lập được  *(index.js)*

`processSaoFile` chạy qua `Promise.all`, và registry được dựng theo thứ tự
**HOÀN THÀNH**. Hai lần build cùng một nguồn ra `registry.ts` khác thứ tự import
→ `git diff` đầy nhiễu, bundler đổi hash chunk vô cớ.

**Sửa:** sắp xếp theo `namingPath` trước khi sinh registry. Đã kiểm: hai lần
build liên tiếp ra registry giống hệt nhau (bỏ dòng timestamp).

### Vùng chưa có cổng — ĐÃ BỊT

Bug ⑤ chỉ ra một lỗ hổng: **không cổng nào chạy `index.js` thật**. Mọi cổng đều
gọi thẳng vào PHP, nên vùng Node spawn → giải mã stdout → ráp registry → ghi
file không được ai kiểm.

Đã dựng `tests/Parity/node-transport/` — chạy build thật trên project sandbox
tạm, ba phép kiểm: TOÀN VẸN (không có U+FFFD), TRUNG THỰC (file ghi ra == output
`saoc` trực tiếp), TÁI LẬP (hai lần build giống nhau).

Corpus không dựa vào may rủi: ngoài 56 view thật còn 8 file stress sinh tự
động, nội dung gần như toàn ký tự nhiều byte và kích thước lệch nhau. Bỏ hết
view thật, chỉ 8 file stress vẫn bắt được 5 file hỏng.

Kiểm răng:

```
hoàn tác setEncoding      → TOÀN VẸN ❌ (14 file) + TRUNG THỰC ❌ (14/128)
hoàn tác sắp xếp registry → TÁI LẬP  ❌
```

Mỗi phép bắt đúng loại lỗi của nó — hỏng ở đâu là biết ngay ở đó.

> ⚠️ `saola/resources/js/saola/web/views/modules/demo/index.ts` đã commit vẫn
> còn output cũ. Cần build lại để lấy bản đúng.

---

## 8. Thuộc tính, directive và dấu `>`

Điều tra bắt đầu từ một báo cáo: `:attr` không hoạt động khi đứng sau
`@attr(...)`. Truy ra thì gốc rễ chung là **dấu `>`** — cả hai bộ quét thẻ đều
coi `>` đầu tiên là kết thúc thẻ, kể cả khi nó nằm trong đối số directive.

### ✅ ① `:attr` bị chặn bởi directive đứng trước

```blade
<div @style({ width: w }) :title="label">x</div>
```

`@style({ width: w })` được preprocessor đổi thành `@style([ 'width'=> $w ])`.
Dấu `>` trong `=>` làm `_transformAttributeBindings` tưởng thẻ đã đóng, nên
`:title` rơi ra ngoài thẻ và không được xử lý. `@class({'box'})` không sinh `>`
nên KHÔNG chặn — đúng với hiện tượng quan sát được.

**Sửa:** đếm độ sâu ngoặc tròn trong thẻ; `>` chỉ đóng thẻ khi depth = 0. Che
luôn `@click(a > b)`.

### ✅ ② `>` trong đối số directive làm VỠ output

Nghiêm trọng hơn ①, và là code đời thường:

```blade
<div @class({'hot': n > 10})>x</div>
```

trước khi sửa:

```blade
<div @class([$__VIEW_ID__ . '-e1']) @attr(['hot' => true, 'n' => true])> 10])>x</div>
```

HTML vỡ, điều kiện mất, `10])>x` lọt ra thành text.

Regex cũ đã vá riêng `=>` và `->` bằng nhánh alternation, nhưng `>` trần thì
không vá được bằng regex — và `re` của Python **không có đệ quy** nên hai bản
không thể dùng chung một pattern. Đã thay bằng **scanner quét tay** (đếm ngoặc,
tôn trọng nháy) ở cả hai bản: đó là cách duy nhất giống hệt nhau ở hai ngôn ngữ.

### ✅ ③ Nhiều `@attr` không được gom, khoá trùng mất dữ liệu im lặng

`@class` vốn đã gom mọi nguồn (id hydrate + scope + `class="..."` + `:class` +
`@class({...})`) vào MỘT directive. `@attr` thì không:

```blade
<div @attr({k: 1}) :title="x">   →   @attr(['title' => $x]) @attr(['k'=> 1])
```

Tệ hơn khi trùng khoá — `['style'=>'margin:0', 'style'=>$s]` trong PHP thành
`['style'=>$s]`: giá trị đầu **biến mất mà output không hề cho thấy**.

**Sửa:** gom `@attr` như `@class`, và khử khoá trùng ngay lúc biên dịch (giữ vị
trí lần đầu, lấy giá trị lần cuối — đúng ngữ nghĩa PHP). Hành vi y hệt, nhưng
nhìn vào blade là biết cái nào thắng.

---

### ✅ ④ Thẻ mở trải nhiều dòng KHÔNG được cấp hydrate id

```blade
<div
    @class({'hot': n > 10})
    :title="label"
>x</div>
```

`hydrate_processor` chạy **theo từng dòng** (`split('\n')` rồi `foreach`), nên
thẻ trải nhiều dòng không khớp được — element đó **không có hydrate id, tức
không hydrate được**. SSR render ra, CSR không tìm thấy.

**Sửa:** ghép các dòng của thẻ MỞ về một dòng trước vòng lặp (chỉ đụng phần
bên trong thẻ; nội dung không bị chạm). Có guard chống nhận nhầm: `{{ n<m }}`
có `<m` trông như thẻ mở, nên yêu cầu sau tên thẻ phải là `>`, `/`, hoặc khoảng
trắng RỒI tới ký tự mở đầu thuộc tính hợp lệ.

### ✅ ⑤ `@verbatim` bị chèn hydrate id và marker

`@verbatim` nghĩa là "giữ nguyên văn", nhưng `hydrate_processor` vẫn gắn
`@class([$__VIEW_ID__ ...])` vào `<pre>` bên trong và bọc `{{ x }}` bằng
`@startMarker`. Trong `@verbatim` Blade KHÔNG thực thi directive, nên
`@startMarker(...)` sẽ **hiện nguyên chữ ra trang docs**.

**Sửa:** che `@verbatim ... @endverbatim` bằng placeholder trước khi xử lý,
khôi phục sau — đúng cách preprocessor đã làm từ trước.

### ✅ ⑥ `@style` render sai — LỖI LIVE trong app

`@style` là directive **dựng sẵn của Laravel**; `core/` không đăng ký nó. Hợp
đồng của Laravel NGƯỢC LẠI cú pháp Saola:

| | khoá | giá trị |
|---|---|---|
| Laravel | chuỗi CSS đầy đủ | điều kiện |
| Saola | tên thuộc tính | giá trị CSS |

Compiler dịch `@style({width: expr})` thành `@style(['width'=> $expr])`, Laravel
đọc thành "áp chuỗi CSS `width` nếu `$expr` truthy":

```
Laravel render : style="width;"
đáng lẽ        : style="width: 50%;"
```

Đã xảy ra thật: thanh progress ở `demo/index.sao:151`.

**Hướng sửa SAI đã thử rồi bỏ:** đổi compiler để phát
`@style(['width: ' . (expr)])`. SSR đúng ngay, nhưng **phía JS mất hẳn style
binding** — JsEmitter đọc `@style` theo shape `'key'=> value`. Kết quả là SSR
đúng còn CSR không áp style: đúng lớp lệch SSR/CSR mà cả dự án đang chống. Đã
hoàn tác.

**Sửa đúng:** giữ nguyên output compiler (hai emitter vốn đã đồng ý về shape),
thêm `StyleDirectiveService` vào `core/` — đăng ký `@style` với ngữ nghĩa Saola,
và vẫn nhận dạng Laravel để không phá code cũ:

```
khoá số          → phần tử là chuỗi CSS nguyên vẹn
khoá CÓ ':'      → dạng Laravel, giá trị là điều kiện
khoá KHÔNG ':'   → dạng Saola, giá trị là giá trị CSS
```

15 unit test ở `core/tests/Unit/StyleDirectiveServiceTest.php`.

> **Bài học:** khi hai emitter đã đồng ý về một shape, đừng đổi shape đó để vá
> một đầu. Sửa ở tầng tiêu thụ (runtime), không sửa ở tầng sinh mã.

---

## 9. Ghi chú về `examples/src/13-syntax.sao`

File này trộn cú pháp **Vue** mà Saola không cài đặt — hữu ích để thấy compiler
xử lý cú pháp lạ thế nào, nhưng **không phải mẫu code chạy được**:

| viết | Saola hiểu thành | Saola tương đương |
|---|---|---|
| `v-model="value"` | thuộc tính tĩnh `'v-model' => 'value'` | `@bind(value)` |
| `v-for="item in items"` | thuộc tính tĩnh | `@foreach(items as item)` |
| `:key="item"` | `key="{{ $item }}"` | — |

Hệ quả: `$item` được **dùng 6 lần, khai báo 0 lần**. SSR sẽ cảnh báo "Undefined
variable $item"; CSR ném `ReferenceError: item is not defined` và giết cả view.

Compiler không sai — nó truyền thuộc tính lạ qua nguyên vẹn, đúng như thiết kế.
Nhưng đây là chỗ một **cảnh báo lúc biên dịch** sẽ đáng giá: "`v-for` không phải
directive của Saola — có phải bạn định dùng `@foreach`?". Kênh
`CompileResult::$warnings` đã có sẵn nhưng hiện luôn rỗng — `HelperResolver` thu
thập cảnh báo rồi bị `SaolaCompiler::compile()` vứt đi.

---

## 10. Rà soát: file `.sao` thật dùng cú pháp gì

Đối chiếu trên toàn bộ 56 view production (trước khi thêm view mới ở §11):

```
JS object { key: value }         : 24/56 file
PHP array ['key' => value]       : 0/56 — mọi chỗ khớp regex đều nằm trong
                                    @verbatim hoặc text minh hoạ trong <code>
$variable / -> làm expression     : 0/56 ngoài @verbatim — toàn bộ là text/
                                    comment/HTML minh hoạ trong trang docs
```

Corpus biểu thức dùng để rà soát khớp đúng tỷ lệ: 573/587 (97.6%) biểu thức
thật là cú pháp Saola hiện đại. Hướng rà soát từ đầu tới giờ đã đúng trọng tâm.

**Duy nhất 1 file** (`posts/list.sao`) dùng thẻ `<blade>` — chế độ full-legacy.
Việc rà soát nó dẫn tới phát hiện và quyết định ở §11.

## 11. Bỏ nhánh tương thích cú pháp cũ — quyết định sản phẩm

Sau khi xác nhận 0/56 view thật dùng PHP array/`<blade>` một cách có ý nghĩa,
quyết định: **bỏ hẳn cơ chế "phát hiện cú pháp cũ rồi bỏ qua transform"**. Mọi
file `.sao` từ nay LUÔN đi qua preprocessor như Saola Syntax.

### Suýt hiểu nhầm phạm vi — phải dừng lại xác minh trước khi làm

Câu hỏi ban đầu ngỡ đơn giản ("bỏ code path xử lý cú pháp PHP cũ") thực ra gộp
**hai thứ khác bản chất**:

| | Là gì | Bỏ được không |
|---|---|---|
| `PhpJsBridge` (từng tên `LegacyPhpSyntax`) | Nửa dịch-ngược PHP→JS **bắt buộc cho mọi file** | ❌ Không |
| Thẻ `<blade>` + phát hiện cú pháp | Chế độ bỏ qua preprocessor hoàn toàn | ✅ An toàn |

Kiểm thực nghiệm trước khi động vào bất cứ thứ gì:

```
input Saola Syntax hiện đại : item.name
sau Preprocessor             : $item->name   ← LUÔN dịch sang PHP, mọi file
sau ExpressionCompiler       : item.name     ← PhpJsBridge dịch NGƯỢC về JS
```

Kiến trúc thật: preprocessor xuất PHP syntax cho **mọi file** vì Blade (SSR)
cần PHP hợp lệ để nhúng thẳng; JS emitter thì dịch ngược PHP đó về JS. Đây là
tầng kiến trúc lõi khiến SSR và CSR đồng bộ ngữ nghĩa — không phải nhánh chỉ
chạy khi ai đó viết `.sao` kiểu cũ.

**`LegacyPhpSyntax` là tên sai do chính lần rà soát trước đặt** — đã đổi thành
`PhpJsBridge` kèm docblock giải thích lại đúng vai trò, để lần sau không ai
(kể cả tôi) hiểu nhầm lần nữa.

### Đã bỏ

- `Preprocessor::usesSaolaSyntax()` / `isSaolaSyntaxRaw()` / `scoreSyntax()` — ba
  hàm quyết định "transform hay pass-through", xoá hẳn. `preprocess()` và
  `preprocessRaw()` giờ luôn transform.
- Cùng thay đổi ở `preprocessor/index.js` (`_detectSyntax`, `_detectSyntaxFromRaw`,
  `_isSaolaSyntax`) — preprocessor là code JS, phải sửa cả hai để giữ oracle.
- Thẻ `<blade>` **không bị xoá khỏi ngôn ngữ** — `WrapperScanner`/`SourceSplitter`
  vẫn nhận diện nó như một wrapper hợp lệ. Chỉ khác: giờ nó xử lý **y hệt**
  `<template>`, không còn ý nghĩa "giữ nguyên PHP thô" nữa.

### Hệ quả tốt ngoài dự kiến: bug §7①② tự nhiên biến mất

`posts/list.sao` viết `@props({posts: [], title: null,})` (JS-object) bên
trong `<blade>`. Khi `<blade>` còn nghĩa là "bỏ qua transform", declaration đó
giữ nguyên cú pháp JS-object — parser Blade (chỉ hiểu `[k=>v]` hoặc `$a=0`)
không nhận ra, `@props` **biến mất khỏi Blade hoàn toàn**, `$posts`/`$title`
dùng mà không khai báo. Sau khi bỏ detection, `@props({...})` được transform
đúng như mọi file khác → Blade giờ có `<?php if(!isset($posts))...`.

### File `.sao` phải viết lại — vì viết PHP thô trong template

`posts/list.sao` viết PHP thuần bên trong `<blade>` (`{{ $title }}`,
`@foreach($posts as $post)`, `{{ $post->title }}`). Sau khi `<blade>` không
còn bỏ qua transform, các biểu thức đó bị coi là Saola Syntax và transform
sai (`$title` → `$$title`, double-dollar). Đã viết lại theo Saola Syntax chuẩn:

```diff
-<blade>
-    <h1>{{ $title }}</h1>
-    @foreach($posts as $post)
-        <li>{{ $post->title }}</li>
-    @endforeach
-</blade>
+<template>
+    <h1>{{ title }}</h1>
+    @foreach(posts as post)
+        <li>{{ post.title }}</li>
+    @endforeach
+</template>
```

File này không route/không ai dùng, nhưng viết lại để không để lại 1 view hỏng
trong repo mà nguyên nhân đã biết rõ. Đã xác minh PHP khớp Python từng byte sau
khi viết lại, và marker-sync (blade↔js) sạch.

Fixture `tests/Parity/source-split/fixtures/29-legacy-blade.sao` gặp đúng vấn
đề tương tự (viết `$count` PHP thuần để test hành vi pass-through cũ) — đã viết
lại thành ca hồi quy dương: chứng minh `<blade>` giờ hoạt động y hệt
`<template>`, thay vì test hành vi "giữ nguyên" đã không còn tồn tại.

### Kiểm chứng

```
31/31 cổng parity                    xanh (sửa Python + PHP đồng thời)
42 unit test (compiler)          xanh, không đổi
marker-sync                          105/108, không ca mới, posts/list.sao sạch
Python test suite                    13/7 và 6/3 — y baseline, không hồi quy
build lại app thật                   không ký tự hỏng, không `$$`
                                      chỉ 2 file blade đổi: posts/list, head
                                      (cả hai đều là sửa đúng, không phải vỡ)
```

---

## 12. Bug: tên có sẵn của JS rơi vào Blade (đã sửa)

Phát hiện khi mở rộng `examples/src/14-demo-full.sao`: viết `{{ String(status) }}`
compile trót lọt, CSR chạy đúng, nhưng SSR **Fatal error lúc render**.

### Gốc rễ

`KnownFunctions::JS_BUILTINS` tồn tại để JsEmitter KHÔNG gắn tiền tố
`App.Helper.` — đúng cho phía JS. Nhưng **cùng chuỗi biểu thức đó cũng đi thẳng
vào Blade**, nơi PHP không có `String`, `Math`, `JSON`. Không có tầng nào đối
chiếu "tên này JS có, PHP không".

Đo thực nghiệm trên toàn bộ `JS_BUILTINS` (chạy `php -r` từng tên):

```
38 tên  → Fatal: Call to undefined function String()/Number()/parseInt()...
 3 tên  → KHÔNG Fatal nhưng SAI ÂM THẦM (nguy hiểm hơn):
          PHP không phân biệt hoa thường ở tên hàm, nên
          Array(x) → array(x),  Date(x) → date(x),  eval → construct của PHP
mọi tên → dạng `Name.method(` đều Fatal: '.' trong PHP là NỐI CHUỖI,
          nên `Math.max($x,1)` = hằng `Math` (chưa định nghĩa) . `max($x,1)`
```

### Vì sao KHÔNG tự dịch sang hàm PHP tương đương

Cách "sửa cho chạy" là map `Math.round`→`round`, `JSON.stringify`→`json_encode`,
`Date.now()`→`time()`. Đã cân nhắc và **bỏ**: ngữ nghĩa lệch nhau ở biên (làm
tròn .5, escape unicode/slash, ms so với giây), nên dịch sai sẽ biến một lỗi
Fatal ồn ào thành **sai số im lặng** — đúng thứ tệ hơn. Thêm nữa 0/56 view thật
dùng tới, nên xây bảng map là đầu tư cho nhu cầu chưa tồn tại.

Chọn: **báo lúc compile**, để tác giả view quyết định.

### Đã làm

- `src/Emit/BladeBuiltinCheck.php` — soát Blade ĐÃ SINH, không soát biểu thức
  lúc transform. Lý do: `Math.max` trong `@click(...)` là **hợp lệ** (CSR-only,
  không vào Blade). Soát trên output cuối thì vị trí nào vào Blade là biết chắc,
  không cần đoán theo ngữ cảnh.
- Chỉ soát vùng **chắc chắn là PHP thô**: `{{ }}`, `{!! !!}`, `<?php ?>`, và
  directive điều khiển Blade dịch thẳng ra PHP (`@if`, `@foreach`, `@switch`...).
  CỐ Ý bỏ directive khai báo của Saola (`@const`, `@let`, `@vars`, `@useState`) —
  `useState(` nằm hợp lệ trong đó. Báo thiếu tốt hơn báo sai: cảnh báo hay báo
  nhầm sẽ bị lập trình viên học cách phớt lờ, và thành vô dụng.
- Che `{{-- --}}` + `@verbatim` + chuỗi ký tự trước khi soát — trang docs in ví
  dụ `Math.max` là văn xuôi, không phải mã.

### Kênh cảnh báo trước đó CHẾT từ đầu đến cuối

`CompileResult::$warnings` có sẵn nhưng `SaolaCompiler` hardcode `warnings: []`;
`bin/saoc` không in; `index.js` **nuốt stderr khi compile thành công** (chỉ dùng
nó lúc `code !== 0`). Nhánh NDJSON worker của watch mode thì đã chuyển tiếp sẵn.
Đã nối: `SaolaCompiler` → `bin/saoc` in ra **STDERR** (STDOUT là JSON mà node
transport và cổng parity đọc, chen chữ vào là hỏng parse) → `index.js` chuyển
tiếp stderr ở cả hai nhánh.

`HelperResolver::warnings()` vẫn CHƯA nối — `ExpressionCompiler` được `new` mặc
định ở 10+ chỗ nên không có instance chung để gom; nối được phải DI xuyên cây.
Vấn đề riêng, không thuộc bug này.

### Kiểm chứng

```
56 view thật + 14 example        0 báo nhầm
ca thật (String/Math/JSON/Number) bắt đủ 4
văn xuôi, chuỗi, comment, verbatim, @click(Math.max) — đúng: KHÔNG báo
7 unit test                      xanh, đã thử phá check → test đỏ đúng chỗ
31/31 cổng parity                xanh (49 test, từ 42)
Python 13/7, JS 13/13            y baseline
build lại app                    0 cảnh báo, output KHÔNG đổi byte nào
```

> **Lưu ý parity:** cảnh báo là chẩn đoán PHP-only, không phải output. Cổng chỉ
> so `blade`+`js`, và bản Python không có kênh warnings — tiền lệ `[sao2js]` của
> `HelperResolver` đã vậy từ trước.

---

## 13. Nối nốt kênh cảnh báo `[sao2js]` (đã xong)

### Đính chính §12: "phải DI xuyên cây" là SAI

§12 ghi rằng nối `HelperResolver::warnings()` cần DI xuyên cây vì
`ExpressionCompiler` được `new` mặc định ở 10+ chỗ. Kiểm lại thì **DI đã có sẵn
từ trước**: `MainCompiler` tạo MỘT `$this->expressions` rồi inject xuống
`DirectiveParsers`, `DeclarationTracker`, `TemplateProcessor`,
`StyleDirectiveHandler`, `ShowDirectiveHandler`, `AstParser`. Những
`new ExpressionCompiler()` ở chữ ký hàm chỉ là default cho lúc gọi trực tiếp
(test), không phải đường đi thật.

Kiểm bằng thực nghiệm chứ không đọc suông — compile một view có
`<script setup>` rồi đếm trong JS sinh ra:

```
knownMethod (khai trong <script setup>) → this.view.knownMethod   ✓ mọi vị trí
hàm lạ trong @if / @foreach / @class    → App.Helper.<tên>        ✓ mọi vị trí
```

Nếu DI đứt ở tầng nào thì `setUserMethods` không tới nơi, và `knownMethod` sẽ
ra `App.Helper.knownMethod` — tức là bug thật, không chỉ mất cảnh báo. Không có
chuyện đó. Nên việc nối chỉ còn là thêm getter.

### Đã làm

- `MainCompiler::warnings()` → `$this->expressions->helpers()->warnings()`
- `SaolaCompiler` giữ instance `MainCompiler` lại và `array_merge` hai nguồn:
  `[sao2js]` (tên hàm lạ, gom lúc sinh JS) + `[sao2blade]` (§12, soát Blade).
- 4 test ở `tests/Unit/SaolaCompilerTest.php`, gồm cả ca âm: method khai trong
  `<script setup>` KHÔNG được cảnh báo. Đã thử phá đường nối → đúng 2 test đỏ.

Đo độ ồn trên 56 view thật: **0 cảnh báo**. Danh sách helper đã biết cộng với
`userMethods` phủ hết cách dùng thực tế, nên cảnh báo này không nhiễu.

## 14. Bug: nội dung nằm cùng dòng với `@if`/`@foreach` mất khỏi JS (đã sửa)

Phát hiện khi dựng probe kiểm DI ở §13.

```
@if(x > 0)<span>a</span>@endif     → Blade: CÓ <span>   JS: MẤT
@foreach(...)<span>a</span>@end... → Blade: CÓ <span>   JS: MẤT
@if(x > 0)
    <span>a</span>                 → Blade: CÓ <span>   JS: CÓ    (đúng)
@endif
```

Bất kỳ HTML nào nằm **cùng dòng** với `@if(...)` / `@foreach(...)` đều vào được
Blade nhưng biến mất khỏi JS. Tệ hơn: phần tử đó không được cấp hydrate id, và
phần tử ở dòng KẾ TIẾP bị hút nhầm vào làm nội dung của nhánh — nên id lệch dây
chuyền. Đây là vi phạm bất biến I2 (SSR ≠ CSR): server render `<span>`, client
không, hydration lệch DOM.

Nguyên nhân: xử lý theo DÒNG — handler nuốt trọn dòng chứa directive và chỉ gom
nội dung từ các dòng sau.

**Trạng thái:** CÓ SẴN ở cả bản Python lẫn PHP (cổng parity vẫn xanh vì hai bên
sai giống hệt nhau — đây đúng là điểm mù của parity gate: nó chỉ chứng minh hai
bản GIỐNG nhau, không chứng minh bản nào ĐÚNG). 0/56 view thật dùng cú pháp này.

### Đã sửa: chuẩn hoá ở MỘT chỗ dùng chung

Sửa ở tầng chuẩn hoá thay vì dạy từng handler cách xử lý phần đuôi — một lần
sửa, cả `@if`/`@foreach`/`@for`/`@while`/`@switch` cùng đúng, và hai emitter
không thể lệch nhau:

- `Html::splitInlineDirectives()` (PHP) + `split_inline_directives()`
  (`common/utils.py`) — tách directive điều khiển ra dòng riêng.
- Áp dụng ở ĐÚNG 4 điểm, ngay sau `joinMultilineOpenTags` đã có sẵn — khuôn
  y hệt tiền lệ đó: `BladeHydrateProcessor` + `Ast/Parser` (PHP),
  `hydrate_processor.py` + `template_ast.py` (Python).

Điểm quan trọng khi thiết kế:

```
trong thẻ HTML   → KHÔNG tách: <div @class(...) @click(...)> là directive
                   THUỘC TÍNH, tách ra dòng riêng sẽ xé nát thẻ
comment/verbatim → KHÔNG tách: trang docs in ví dụ @if(...) là văn bản
tên dài trước    → @endforeach phải thử trước @endfor, @elseif trước @else
đã đúng định dạng→ NO-OP tuyệt đối, không đổi một byte (kể cả thụt lề)
```

### Kiểm chứng

```
56 view thật             output KHÔNG đổi một byte (normalizer là no-op)
6 unit test              xanh; phá chèn xuống dòng → 3 test đỏ
fixture 30-inline-directive.sao vào corpus dùng chung
marker-sync              106/109 (từ 105/108) — ca mới khớp, không sinh lệch mới
31/31 cổng parity        xanh (59 test, từ 53)
Python 13/7 và 6/3, JS 13/13   y baseline
```

> Cách kiểm sai suýt làm tôi tưởng fix chưa xong: grep `<i` trong JS luôn trượt,
> vì JS sinh ra `this.html(..., "i", ...)` chứ không có dấu `<`. Phải so DANH
> SÁCH TÊN THẺ hai bên, không so văn bản thô.

---

## 15. Săn bug có hệ thống bằng sweep (5 bug, đã sửa hết)

§14 sửa xong `@if`/`@foreach` nhưng chỉ dựa vào probe thủ công. Probe thủ công
KHÔNG đủ: nó từng báo `@section` "OK" do lỗi escaping bash, che mất một bug
thật. Nên dựng harness sweep tự động — sinh 86 tổ hợp cú pháp rồi soát:

```
TAG     : danh sách thẻ có hydrate id ở Blade == danh sách this.html(...) ở JS
NO-ID   : thẻ nằm trong wrapper mà KHÔNG có hydrate id (nội dung bị bỏ quên)
OUTPUT  : marker output reactive khớp hai phía
ĐÃ BÁO  : compiler tự cảnh báo ⇒ không còn là lỗi im lặng
```

Kết quả: **5 bug**, đều CÓ SẴN ở cả Python lẫn PHP (parity xanh suốt vì hai bên
sai giống hệt nhau), 0/56 view thật dính.

### ① `@key`, `@wrapper`, `@section`, `@block` cũng nuốt trọn dòng

Cùng lớp §14 nhưng §14 mới xử `@if`/`@foreach`. Mỗi cái hỏng RIÊNG:
`@key(i.id)<li>` làm `__foreach` sinh ra thân RỖNG, `@section('s')<b>` làm
`sections: {}`. Đã thêm cả bốn (kèm `@endwrapper`/`@endsection`/`@endblock`)
vào `CONTROL_DIRECTIVES` của cả hai bản.

### ② Bug §8② LẶP LẠI trong chính splitter tôi vừa viết

`<div @if(x>0) data-a='1' @endif>` — scanner coi `>` trong `x>0` là dấu đóng
thẻ, nên tách `@endif` ra dòng riêng và **xé nát thẻ**, mất luôn hydrate id.
Đúng bug đã sửa cho `matchOpenTag` ở §8②, tôi lặp lại y hệt ở hàm mới. Đã thêm
đếm độ sâu ngoặc vào scanner. Bài học: mọi chỗ quét thẻ HTML đều phải đếm ngoặc,
không có ngoại lệ.

### ③ Biến sau chuỗi ký tự mất dấu `$` — bug nặng nhất

```
Saola:  a + '-' + b
Blade:  $a . '-' . b     ← `b` mất '$' ⇒ PHP 8 Fatal "Undefined constant b"
JS:     a+'-'+b          ← đúng
```

Gốc rễ: `handlePlusOperator()` đổi `+` thành `Token(PhpConcat, '.')` — value
CŨNG là `'.'`. `renderIdentifier()` nhận diện truy cập thuộc tính bằng
`$prev->value === '.'`, nên tưởng `b` là tên thuộc tính và bỏ `$`.

Sửa: so **KIỂU** token, không so value (`$prev->is(TokenType::Operator)`).
Ảnh hưởng MỌI biểu thức nối chuỗi — `{{ 'Xin chào ' + name }}` trước đây luôn
Fatal ở SSR. Sửa ở cả `ExpressionTransformer.php` và
`preprocessor/expression-transformer.js`.

### ④ `@key` phức hợp sinh Blade không parse được → BÁO, không tự dịch

`@key(i.id + '-' + i.n)` sinh `"-l11-{$i->id + '-' + i->n}"`. Nội suy `{$...}`
của PHP **chỉ nhận biểu thức đơn giản** — có toán tử là Parse error, và hỏng
**CẢ FILE** .blade.php chứ không riêng phần tử đó.

Không "sửa cho chạy": muốn đỡ phải đổi cách sinh id sang nối chuỗi ngoài
literal — đụng đúng phần an toàn nhất của hệ thống (bất biến I2) vì một cú pháp
0/56 view dùng. Chọn BÁO lúc compile, cùng lý lẽ §12.

`BladeInterpolationCheck` — không đoán bằng heuristic mà hỏi thẳng PHP qua
`token_get_all($code, TOKEN_PARSE)`, nó ném `\ParseError` khi cú pháp sai. Phân
biệt chính xác: `{$i->id}`, `{$i['id']}`, `{$i->m()}` qua được; mọi dạng có
toán tử bị bắt.

### ⑤ Điểm mù của chính harness

Ca `@block` thoát lưới lần đầu: Blade không cấp id VÀ JS không sinh thẻ, nên so
hai danh sách rỗng vẫn "khớp". Đã thêm luật NO-ID (thẻ trong wrapper mà thiếu
hydrate id). Kiểm răng: tắt splitter → 14/86 ca đỏ; bật lại → 0.

### Kiểm chứng cuối

```
86 ca sweep              0 lỗi im lặng, 2 ca compiler tự báo (đúng ý đồ)
86 ca sweep, Python↔PHP  0 lệch — hai bản sửa đồng bộ tuyệt đối
31/31 cổng parity        xanh (74 test, từ 42 lúc bắt đầu đợt này)
marker-sync              107/110 (từ 105/108), 3 lệch đã biết không đổi
56 view thật             output KHÔNG đổi một byte; 0 cảnh báo
Python 13/7 và 6/3, JS 13/13   y baseline
```

Fixture mới vào corpus dùng chung: `30-inline-directive.sao` (mở rộng đủ 6
directive), `31-string-concat.sao`.

> **Điều đáng nhớ nhất:** cả 5 bug đều nằm ngoài tầm cổng parity — hai bản sai
> giống hệt nhau nên gate xanh suốt. Parity chứng minh "port giống gốc", KHÔNG
> chứng minh "gốc đúng". Phải có cổng độc lập (marker-sync) và sweep chủ động
> thì mới thấy.

---

## 16. Mã trong comment bị coi là mã thật (compiler + extension)

Người dùng phát hiện khi xem blade sinh ra từ `examples/src/14-demo-full.sao`.
Đúng — và bug LIVE ngay trong chính file đó.

### Bằng chứng trên file thật

Comment của file có nhắc `<script setup>` (không kèm thẻ đóng). Regex bóc script
`/<script[\s\S]*?<\/script>/i` khớp từ TRONG comment rồi chạy tới `</script>`
THẬT ở cuối file — nuốt trọn cả template làm "nội dung script":

```
. Trộn method của view (setStatus, handleFormSubmit)
     với helper toàn cục (String) trong CÙNG một biểu thức. --}}
    <form @submit(handleFormSubmit($event))>
        ... TOÀN BỘ TEMPLATE ...
    </form>
</template>
<script setup>
export default { handleFormSubmit(event) { ... } }
```

Lần này output cuối may mắn không đổi (template lấy từ thẻ bọc `<template>`
chứ không qua `stripToTemplate`), nhưng khai báo thì lọt thật — xem fixture
`32-comment-shadows-code.sao`.

### Phạm vi: 5 chỗ quét thô, mọi thứ trong comment đều lọt

```
@props / @vars / @const / @states / @import  → đăng ký thành khai báo thật
<script> / <style>                            → bóc thành khối thật
<style scoped>                                → CSS lọt vào stylesheet, VÀ vì
                                                scope class suy từ CHÍNH nội
                                                dung CSS nên class của MỌI
                                                element trong view đổi theo
```

Trang tài liệu dính nặng nhất — nó tồn tại để in ra cú pháp Saola.

### Sửa: một helper dùng chung, ba ngôn ngữ

Không vá từng chỗ. `blank()` làm trắng vùng `{{-- --}}` và `@verbatim` bằng
khoảng trắng, **GIỮ NGUYÊN độ dài và số dòng** — nhờ đó offset tính trên bản
làm trắng vẫn trỏ đúng vào bản gốc, chỗ nào cần giữ comment trong output chỉ
việc cắt bản gốc theo offset.

| | |
|---|---|
| PHP | `Saola\Compiler\Support\BladeComment::blank()` |
| JS | `blankBladeComments()` — `compiler/src/index.js` |
| Python | `blank_blade_comments()` — `compiler/src/common/utils.py` |

Áp dụng: `SourceSplitter` (khai báo, `extractTagBody`, `stripToTemplate`),
`SaolaCompiler::scopedStyles`, `ScopedStyle::extract`, cùng bản song sinh
trong `index.js` và `scoped_style.py`.

### Cổng không bắt được vì corpus không có ca này

`source-split` và `common-utils` vẫn xanh sau khi sửa PHP — 96/96 và 245/245 —
đơn giản vì **không fixture nào có mã trong comment**. Phải thêm ca trước, thấy
cổng ĐỎ, rồi mới sửa phía oracle. Cổng xanh mà chưa có ca thì không chứng minh
được gì.

### Extension cũng dính — và nặng hơn

`saola-language-support` quét theo dòng, không biết comment:

```
_detectSaoMode()      <template> trong comment LẬT chế độ modern/legacy của cả
                      file → mọi chẩn đoán chạy sai đường (đã kiểm: trả
                      'modern' cho file thật ra là <blade>)
_collectDeclaredVars() @states(/@props( trong comment thành biến thật →
                      autocomplete gợi ý biến không tồn tại
đếm wrapper           thẻ bọc trong comment → báo "nhiều wrapper" oan
```

Sửa cùng luật, cùng lý do phải giữ số dòng: `_extractDirectiveContent(lines, i)`
dùng CHỈ SỐ DÒNG, lệch một dòng là đọc nhầm khai báo.

Grammar TextMate thì KHÔNG dính: `injectionSelector` đã có sẵn
`-comment.block.blade`, và rule comment không có `patterns` con nên nội dung
bên trong là comment thuần. Đã kiểm chứ không đoán.

### Kiểm chứng

```
31/31 cổng parity        xanh (79 test, từ 74)
source-split             97/97   (+ fixture 32-comment-shadows-code)
common-utils             249/249 (+ 4 ca comment/@verbatim)
marker-sync              108/111, 3 lệch đã biết không đổi
86 ca sweep              0 lỗi im lặng
extension                npm test — viewPath + comment-blind đều xanh
                         (đã thử phá: trước khi sửa trả 'modern' — sai)
56 view thật             output KHÔNG đổi một byte
Python 13/7 và 6/3, JS 13/13   y baseline
```

---

## 17. Xử nốt 3 lệch marker-sync "đã biết" → 111/111

Danh sách KNOWN trong `tests/Parity/marker-sync/check.py` giờ RỖNG.

### ⓑ `<script>`/`<style>` trong thẻ bọc — nặng hơn ghi chú cũ nhiều

Ghi chú cũ chỉ nói "blade cấp id thừa". Thực tế còn **viết đè lên mã JavaScript**:

```
<script>var t = "<span>giả</span>";</script>
   ↓ sao2blade
<script @class([...'-e2'])>var t = "<span @class([...'-e21'])>giả</span>";</script>
```

Directive Blade bị nhét vào TRONG chuỗi JS — hỏng mã lúc chạy. Và id thì lệch
dây chuyền: `div=e1, script=e2, p=e3` ở blade nhưng `p=e2` ở js.

Sửa: thêm `<script>`/`<style>` vào bước CHE sẵn có ở đầu `BladeHydrateProcessor`
(chỗ đang che `{{-- --}}` và `@verbatim`) — khớp đúng `RAW_CONTENT_ELEMENTS`
của `Ast/Parser`. 0/56 view thật dính.

### ⓐ `<template>` lồng — hai bug chồng nhau

**Bug 1 — regex non-greedy.** `BladeEmitter::extractTemplate` dùng
`/<template>([\s\S]*?)<\/template>/`, dừng ở `</template>` ĐẦU TIÊN (của thẻ
TRONG). Nội dung bị cắt cụt và thẻ đóng ngoài rơi mất — blade sinh ra thiếu
`</template>`, tức HTML hỏng. Sửa bằng `Html::innerOfFirstTag()` /
`inner_of_first_tag()` khớp theo ĐỘ SÂU.

**Bug 2 — strip toàn cục.** `MainCompiler` xoá thẻ bọc bằng
`preg_replace('/<\/?template\b[^>]*>/i', '')` nên xoá LUÔN thẻ lồng. Sửa bằng
`stripOutermostWrapperTags()` / `strip_outermost_wrapper_tags()`.

> **Bug tôi tự gây khi sửa bug 2:** bản đầu xoá từng cặp rồi QUÉT LẠI — sau khi
> xoá cặp ngoài, thẻ lồng trở thành "ngoài cùng" và bị xoá theo, tức là tái tạo
> đúng bug đang sửa. Phải quét MỘT LƯỢT, nhảy qua trọn cặp đã khớp
> (`offset = closeEnd`), gom hết rồi mới xoá từ cuối về đầu.

`<template>` lồng là element HTML thật; chỉ thẻ NGOÀI CÙNG mới là thẻ bọc Saola.

### ⓒ Thẻ bọc không đóng — tự khỏi, và quay lại được vào cổng

Ca này TỪNG bị `cases.js` lọc khỏi corpus vì "hành vi không định nghĩa". Sau
khi strip biết đếm độ sâu, thẻ mở không có thẻ đóng khớp được **để nguyên**, và
CẢ HAI emitter coi nó là element HTML thường — hành vi đã xác định và khớp
nhau. Đã bỏ bộ lọc, corpus 110 → 111.

### Kiểm chứng

```
marker-sync              111/111, KNOWN rỗng (trước: 105/108, 3 lệch)
31/31 cổng parity        xanh (80 test, từ 42 lúc bắt đầu đợt săn bug)
86 ca sweep              0 lỗi im lặng
56 view thật             output KHÔNG đổi một byte
Python 13/7 và 6/3, JS 13/13   y baseline
```

> Cả ba đều là lỗi thật, không phải "đặc thù chấp nhận được". Ghi chú KNOWN đã
> mô tả ⓑ nhẹ hơn thực tế (id thừa, thay vì hỏng mã JS) — danh sách known
> issue cần được đọc lại định kỳ, không chỉ được nối dài.

---

## 18. Thuộc tính binding: mất kiểu, nối chuỗi sai, và §8② lần thứ BA

### ① `:attr` mất kiểu ở phía JS

```
:disabled="n < 1"
  → blade: 'disabled' => $n < 1     (boolean thật)
  → js   : value: `${n < 1}`        (CHUỖI "false")
```

`disabled="false"` trong DOM **vẫn là disabled**. SSR bật nút, CSR tắt nút.

Gốc: preprocessor chuẩn hoá `:attr="e"` thành `attr="{{ e }}"`, tới JsEmitter
thì `js` là `${n < 1}` và bị bọc template literal. Blade thì gỡ `{{ }}` ra
biểu thức thô nên giữ được kiểu.

Sửa: `JsEmitter::wholeInterpolation()` / `_whole_interpolation()` — nội suy phủ
TRỌN chuỗi thì trả biểu thức thô. Trộn text (`tr${n}sau`) vẫn là template
literal, vì đó **thật sự** là chuỗi.

Hệ quả phụ tốt: `:title="x"` giờ khớp đúng `@attr({title: x})` — trước đó hai
cách viết cho ra hai dạng khác nhau.

### ② Blade nối chuỗi sai trong `@attr`

```
data-m="tr{{ n }}sau"  →  'data-m' => tr$nsau
```

PHP đọc `tr` là hằng chưa định nghĩa (Fatal ở PHP 8) và `$nsau` là biến không
tồn tại. Bản cũ chỉ BÓC cặp `{{ }}` rồi ghép thẳng vào văn bản xung quanh.
`class` vốn đã làm đúng (`'language-'.($lang)`) — `@attr` thì chưa.

Sửa: `bindingAttrPhp()` / `_binding_attr_php()` sinh nối chuỗi thật, và giữ
biểu thức thô khi nội suy phủ trọn (cùng bất biến với ①).

4/56 view có dạng trộn này nhưng đều là `class=` nên thoát; `@attr` thì 0/56.

### ③ `@vars(x = 1)` không vào bảng ký hiệu

```
@vars(sukien  = 'X')  →  {{ $sukien }}   ✓ (nhánh mặc định cứu)
@vars(event   = 'X')  →  {{ event }}     ✗ Fatal "Undefined constant event"
@vars(event)          →  {{ $event }}    ✓ dạng trần vốn đã đúng
@props({event: 'X'})  →  {{ $event }}    ✓
```

`event`, `console`, `window`, `document`, `Math`, `JSON`, `Date`… nằm trong
`NO_PREFIX`. Bảng ký hiệu được tra TRƯỚC nên khai báo hợp lệ vẫn thắng — nhưng
`SymbolCollector` bỏ qua dạng `=` với chú thích **ghi ngược sự thật**: "Có '='
nghĩa là cú pháp @let/@const". Thực tế `@vars(users = [...])` là dạng đang dùng.

Sửa: lấy vế trái của `=` làm tên. Thuần tuý cho dạng `=` **theo kịp dạng trần**,
không tạo hành vi mới.

### ④ §8② LẦN THỨ BA — `>` trong giá trị prop của component

```
<UItem :v="a" />            → @include(..., ['v' => $a])          ✓
<UItem :v="a > b" />        → <uitem @attr([':v' => '$a > $b']) /> ✗
<UItem :v="{x: a}" />       → ✗ (preprocessor dịch thành ['x'=> $a], có '>')
```

`ImportTagResolver` khớp thẻ bằng `[^>]*?`, cắt ở `>` đầu tiên. Thẻ không khớp
⇒ component KHÔNG thành `@include`: nó ở lại làm element thường, bị hạ chữ
thường, được cấp hydrate id, prop thành chuỗi lồng nháy PHP không parse nổi.

Sửa 4 pattern (PHP) + 4 (Python) sang `(?:[^>'"]|'[^']*'|"[^"]*")*?`.

> Đây là lần thứ BA cùng một gốc: `matchOpenTag` (§8②), `splitInlineDirectives`
> (§15②), và giờ `ImportTagResolver`. **Mọi chỗ quét thẻ HTML đều phải tính tới
> `>` nằm trong chuỗi hoặc ngoặc.** Đáng rà soát chủ động thay vì đợi gặp lần thứ tư.

### ⑤ Fix ④ làm lộ một bug PHP-only có sẵn

`parseAttributes()` dùng lại biến `$value`: nhánh nháy gán chuỗi, nhánh không
nháy truyền chính nó làm tham số by-ref `?array` ⇒ TypeError. Chỉ lộ khi một thẻ
có CẢ thuộc tính nháy lẫn không nháy — mà thẻ đó trước nay không khớp nổi vì ④.
Python không dính (kiểu động). Đã tách biến riêng.

### Hai chuyện về quy trình

**Golden bị formatter sửa.** `examples/expected/14-demo-full.js` bị format thành
`let { name = ... }` thay vì `let {name = ...}` → cổng đỏ, diff trông như
regression. Golden PHẢI là output nguyên byte. Đã thêm `.prettierignore` và
`.editorconfig` che `examples/expected/` và `tests/Parity/**/fixtures/`.

**Repo có HAI cây JS.** `compiler/` (`@saolabs/compiler`, còn Python) và
`builder/` (`@saolabs/builder`, JS-only, đang phát triển tiếp — có
`resolvePhpCompilerPath` mà `compiler/` chưa có). Oracle của cổng đang trỏ
**cả hai**: 10 tới `builder`, 11 tới `compiler`. Fix JS phải vào ĐÚNG cây mà
cổng đọc, nếu không cổng xanh giả. Lần này `symbol-collector` đọc `builder/`
nên phải đồng bộ tay. Việc di trú còn dở — nên thống nhất một cây.

### Kiểm chứng

```
31/31 cổng parity        xanh (81 test)
marker-sync              111/111, KNOWN rỗng
86 ca sweep              0 lỗi im lặng
Python 13/7 và 6/3, JS 13/13   y baseline
build qua builder/       OK
```

---

## 19. `event` không được truyền vào param của handler (bug production)

Người dùng phát hiện khi đọc JS sinh ra:

```js
events: { input: [{"handler":"setStatus","params":[() => Number(event.target.value)]}] }
```

Closure `() =>` **không nhận tham số**, nên `event` bên trong là biến TỰ DO.

### Runtime đúng — compiler sai

`ViewController.ts` đã truyền event vào từ đầu:

```ts
handlerDef.params.map(p => {
    if (typeof p === 'function') return p(event);   // ← truyền vào đây
    return p === '@EVENT' ? event : p;
})
```

Compiler chỉ quên đặt tên tham số. Trong module, `event` trần phân giải ra
`window.event` (đã deprecated nhưng mọi trình duyệt lớn còn hỗ trợ) nên nó
**chạy được do may mắn** — và sẽ hỏng ngay khi param được gọi ngoài lúc dispatch.

### Ba dạng, hai kiểu hỏng

```
doThing(event)                      → () => event                  free var
doThing(Number(event.target.value)) → () => Number(event.target...) free var
doThing(event.target.value)         → event.target.value           KHÔNG bọc
                                       ⇒ chạy LÚC RENDER, event chưa tồn tại
```

Dạng thứ ba tệ nhất: `looksLikeExpression()` không coi truy cập thuộc tính là
biểu thức (nó chỉ tìm `()`, toán tử, hoặc dấu cách — dấu `.` không có trong
danh sách) nên biểu thức bị nhúng thẳng.

Thêm nữa: các nhánh sentinel chỉ nhận `$event`/`@event` — **cú pháp PHP cũ**.
Với Saola Syntax hiện đại (`event`, dạng cả 5 view thật đang dùng) không nhánh
nào khớp.

### Sửa

Một luật thống nhất cho cả 4 chỗ sinh param: `referencesEvent()` /
`_references_event()` → biểu thức tham chiếu `event` thì bọc `(event) => `.

Ranh giới TỪ chứ không phải substring — `preventDefault` **có chứa** "event"
(p-r-"event"-Default) nhưng không tham chiếu biến nào. Luật cũ ở một nhánh dùng
`str_contains(strtolower($js), 'event')` nên gắn tham số thừa cho nó.

### Ảnh hưởng production: 4 view thật

`home/todo`, `home/contact`, `roster/index`, `roster/item` — tất cả đều
`@submit(handler(event))`. Output đổi đúng một chỗ: `() => event` →
`(event) => event`.

## 20. `<script>` trong comment nuốt cả khối script thật (§16 còn sót 2 chỗ)

Lộ ra khi xem `scripts:` của `14-demo-full`:

```js
scripts: [{"type":"code","content":"— SourceSplitter chỉ lấy match ĐẦU TIÊN...
    --}}\n     \n<script setup>"}]
```

Comment `{{-- CHỈ MỘT khối <script> — ... --}}` có thẻ mở `<script>`; regex
`<script...>(.*?)</script>` khớp từ TRONG comment tới `</script>` THẬT, nên
"nội dung script" là phần đuôi comment cộng thẻ mở thật.

§16 đã vá `SourceSplitter`, `ScopedStyle`, `scopedStyles` — nhưng còn sót:

- `SaolaCompiler::buildJsInput()` — gom asset (`<script>`/`<style>`/`<link>`)
- `RegisterParser::parseScripts()` — bóc method của `<script setup>`
- `stylesheetLinks()`

Đã vá cả ba bằng cùng cách: khớp trên bản làm trắng, cắt từ GỐC theo offset.

> **Điểm yếu của cổng lộ ra ở đây:** phần gom asset trong
> `full-pipeline/oracle.js` là bản CHÉP TAY logic của `buildJsInput()`. Oracle
> chép lại subject thì chỉ chứng minh hai bản sao giống nhau — không thể bắt
> bug chung, và đúng là nó đã không bắt. Cùng bài học §15: parity chứng minh
> "giống", không chứng minh "đúng".

### Kiểm chứng

```
31/31 cổng parity        xanh (81 test)
marker-sync              111/111, KNOWN rỗng
86 ca sweep              0 lỗi im lặng
Python 13/7 và 6/3, JS 13/13   y baseline
build lại app            4 view đổi — đúng fix §19, không có đổi ngoài dự kiến
```

---

## 21. Rà soát THEO HỌ LỖI — hai bug nữa, và bộ soát tự động

Thay vì thử ngẫu nhiên, rà theo ba họ đã lặp lại nhiều lần trong phiên này.

### Họ `>` trong quét thẻ — sạch

Bơm `>` vào mọi vị trí thuộc tính (`@class`, `@style`, `@attr`, `:attr`,
`@click`, `@show`, `@bind`, `@wrap`, attr tĩnh): 9/9 sạch. Ba lần vá trước
(§8②, §15②, §18④) đã phủ hết.

### Họ "mã trong chú thích" — thêm 2 chỗ nữa, tổng SÁU

`{{-- <template><p>x</p></template> --}}` → **`WrapperScanner` lấy nhầm thẻ bọc
trong chú thích**: template THẬT bị bỏ qua, view render nội dung chú thích.
Đây là bug nặng nhất của họ này — sai toàn bộ nội dung trang.

`{{-- @fetch(u = '/api') --}}` → `@fetch` được bóc và **chèn thành directive
thật**: view đi gọi API chỉ vì tài liệu có nhắc tới nó. Cùng lỗi với `@await`.

Cộng dồn cả phiên, họ này đã xuất hiện ở **sáu** khâu quét:

```
§16  SourceSplitter (khai báo, extractTagBody, stripToTemplate)
§16  ScopedStyle::extract + SaolaCompiler::scopedStyles
§20  SaolaCompiler::buildJsInput + stylesheetLinks
§20  RegisterParser::parseScripts
§21  WrapperScanner::scan
§21  SourceSplitter — cờ @await/@fetch
```

Mỗi lần vá một chỗ lại lộ chỗ kế. Đó là dấu hiệu **thiếu bộ soát tự động**,
không phải thiếu cẩn thận.

### Họ event param — sạch sau §19

9 dạng (`event` trần, thuộc tính, gọi lồng, nhiều tham số, biểu thức, mảng,
object literal, ternary, không dùng event): tất cả đều bọc `(event) =>` đúng,
object literal còn được bọc `({...})` để tránh nhầm thân arrow thành block.

### Công cụ mới: `tests/Tools/sweep/leak.py`

48 ca = 24 cấu trúc × 2 kiểu bọc (`{{-- --}}` và `@verbatim`). Tự sinh, không
cần `gen.py`. Đã kiểm răng: tắt che comment ở `WrapperScanner` → 6/48 đỏ; bật
lại → 0.

Đây là thứ đáng lẽ phải có từ §16. Danh sách `CASES` cần được nối dài mỗi khi
compiler thêm một khâu quét mới.

### Kiểm chứng

```
31/31 cổng parity        xanh (90 test, +9 EventParamTest)
marker-sync              112/112, KNOWN rỗng
86 ca sweep SSR↔CSR      0 lỗi im lặng
48 ca leak.py            0 rò
9/9 ca họ '>'            sạch
Python 13/7 và 6/3, JS 13/13   y baseline
build lại app            không phát sinh thay đổi ngoài dự kiến
```

Fixture mới: `33-wrapper-in-comment.sao` (thẻ bọc + `@await`/`@fetch` trong
chú thích) — vào cả cổng parity lẫn marker-sync.

---

## 22. Kiểm chứng trên VIEW THẬT — đầu-cuối, không chỉ so byte

Từ §15 tới §21 đều là kiểm tĩnh (so output, soát bất biến). Lần này chạy thật.

### Năm tầng kiểm

```
1. view:cache + php -l    163 file Blade đã compile → 0 lỗi CÚ PHÁP
2. render qua HTTP kernel  49 route → 37 render OK, 12 lỗi ĐỀU của app
                           (6× thiếu route [login], 3× thiếu method
                            controller/model, 1× thiếu hint path [pwa])
3. bắt warning PHP TỪ view 0 cảnh báo — không có bug kiểu thiếu '$' đang sống
4. soát mã hoá             0/49 trang có ký tự hỏng (mb_check_encoding + U+FFFD)
5. Chromium thật           e2e 7/7; crawl 32 trang → 0 lỗi console;
                           60 lượt bấm/gõ → 0 lỗi (1 mã 422 là validation
                           của server, đúng hành vi app)
```

Tầng 1 và 3 bổ sung cho nhau: `php -l` bắt lỗi cú pháp (`tr$nsau` của §18②),
còn warning-từ-view bắt lỗi CHẠY (thiếu `$` của §15③) — loại thứ hai không
làm sập trang, nó render ra rỗng và im lặng.

### Xác nhận fix §19 chạy đúng trên view thật

`/todo-list` có `@submit(addTodo(event))`, handler gọi `event.preventDefault()`
— nếu `event` là `undefined` thì ném TypeError ngay.

```
task được thêm      CÓ   (handler chạy trọn, preventDefault không ném)
không reload trang  CÓ   (preventDefault nhận Event THẬT)
lỗi JS              không
```

### Hai cái bẫy môi trường mất thời gian

**Cổng 8686 bị container Docker chiếm** (từ 27/8). `curl` trúng container chứ
không phải server vừa chạy, và container báo `Class SaolaCompilerServiceProvider
not found` với đường dẫn `/workspace/...`. Suýt đi truy một bug không tồn tại.
Dấu hiệu nhận ra: đường dẫn trong stack trace không thuộc máy này.

**`public/hot` stale** (từ hôm trước, trỏ tới `localhost:5174` đã chết) khiến
Vite trả JS qua dev server không tồn tại. Phải dời đi trước khi build tĩnh —
đúng như ghi chú `local-dev-build-gotchas`.

### Kết luận

Không tìm thêm được bug nào của compiler ở tầng runtime. Điều này KHÔNG có
nghĩa là hết lỗi — nó có nghĩa là các họ đã biết (§16/§18/§19/§21) không còn
biểu hiện trên 56 view thật, ở cả SSR lẫn CSR lẫn tương tác.
