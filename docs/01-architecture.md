# 01 — Kiến trúc

> **Ghi chú vị trí (sau khi di trú thư viện):** các đường `compiler/src/*` bên dưới
> là layout của bản Python/JS **cũ**. Bản Python nay chỉ còn làm oracle tại
> `builder/.reference/python/src/`, còn `compiler/src/index.js` đã thành
> `builder/src/index.js`. Thư mục `compiler/` hiện là package PHP `saola/compiler`.

## 1. Pipeline hôm nay (Node + Python)

```
                  .sao
                    │
   [JS]  Compiler.parseSaoFile()          index.js:515
                    │   tách declarations / template / script / style
                    ▼
   [JS]  SaolaPreprocessor.preprocess()   preprocessor/  (~2.100 dòng)
                    │   Saola Syntax → PHP/Blade Syntax
                    ▼
            ┌───────┴────────┐
            │                │
   spawn python3        spawn python3
   sao2blade/cli.py     sao2js/cli.py
            │                │
       .blade.php          .js
       (marker id)      (marker id)   ← hai lần duyệt độc lập, phải tự khớp nhau
```

Mỗi file `.sao` = **2 lần spawn process**. 56 view = 112 spawn mỗi lần build.

### Và compiler Python đã tự spawn PHP sẵn rồi

`common/php_converter.py:11` có `convert_php_array_with_php_r()` — nó chạy
`php -r 'echo json_encode(...)'` để convert mảng PHP sang JSON. Khoảng 10 chỗ
gọi trong `php_converter.py` và `sao2js/parsers.py`, mỗi lần một subprocess với
timeout 5 giây.

Nghĩa là compiler "Python" hôm nay **đã bắt buộc phải có PHP trên máy**. Port
sang PHP thì toàn bộ chỗ đó thành native — mất hẳn một lớp subprocess lồng trong
subprocess, và bớt được một phụ thuộc ngầm mà không ai ghi vào tài liệu cài đặt.

## 2. Pipeline mục tiêu (PHP thuần)

```
                  .sao
                    │
        SourceSplitter                     ← port từ index.js:515
                    │
        Preprocessor                       ← port từ preprocessor/*.js
                    │   Saola Syntax → PHP/Blade Syntax
                    ▼
        Parser  ──> AST                    ← port từ sao2js/template_ast.py
                    │   (DirectiveRegistry cắm vào ở đây)
                    ▼
        MarkerAllocator gán id MỘT LẦN
                    │
            ┌───────┴────────┐
            ▼                ▼
      BladeEmitter      JsEmitter          ← port từ sao2blade/ + render_generator.py
            │                │
       .blade.php          .js
```

Một tiến trình. Một cây. Một allocator. Không spawn.

## 3. Phát hiện quan trọng: preprocessor cũng phải port

Trong nghiên cứu khả thi ban đầu, preprocessor được xếp vào diện "giữ nguyên, là
JS, không đụng tới". **Với yêu cầu runtime compile thì kết luận đó sai.**

`preprocessor/index.js` là bước **bắt buộc** cho mọi file dùng Saola Syntax mới —
nó dịch cú pháp Saola sang PHP/Blade trước khi compiler Python nhìn thấy. Không
có nó, file `.sao` hiện đại không compile được.

Runtime không có Node → preprocessor phải là PHP.

Cùng lý do đó, `Compiler.parseSaoFile()` (index.js:515, ~280 dòng — tách
declarations/template/script/style/wrapper) cũng phải port.

### Phạm vi port cập nhật

| Nguồn | Ngôn ngữ | LOC | Port? |
|---|---|---|---|
| `compiler/src/sao2js/` | Python | 16.094 | ✅ bắt buộc |
| `compiler/src/common/` | Python | 5.009 | ✅ bắt buộc |
| `compiler/src/sao2blade/` | Python | 2.429 | ✅ bắt buộc |
| `compiler/src/preprocessor/` | **JS** | **~2.100** | ✅ **bắt buộc** (phát hiện mới) |
| `index.js: parseSaoFile` + helper sinh tên | **JS** | **~450** | ✅ **bắt buộc** (phát hiện mới) |
| `compiler/src/python/_old_flat/` | Python | 13.395 | ❌ **dead code — xoá, đừng port** |
| `index.js` phần điều phối, watcher, vite plugin, registry-generator | JS | ~2.500 | ❌ Node giữ |
| **Tổng phải viết bằng PHP** | | **~26.100** | |

Tăng ~2.550 dòng so với ước lượng ban đầu (23.5k). Ước lượng thời gian cập nhật
ở [05-roadmap.md](05-roadmap.md).

## 4. Bản đồ module đề xuất

```
compiler/
├── composer.json
├── bin/
│   └── saoc                        # CLI — Node spawn cái này
├── src/
│   ├── SaolaCompiler.php           # ★ class công khai duy nhất
│   ├── CompileOptions.php
│   ├── CompileResult.php
│   │
│   ├── Source/
│   │   ├── SourceSplitter.php      # ← index.js:parseSaoFile
│   │   └── SourceParts.php
│   │
│   ├── Preprocessor/               # ← preprocessor/*.js
│   │   ├── Preprocessor.php
│   │   ├── SymbolCollector.php
│   │   ├── ExpressionTransformer.php
│   │   └── PhpBuiltins.php
│   │
│   ├── Ast/                        # ← sao2js/template_ast.py
│   │   ├── Node.php + các node class
│   │   ├── Parser.php
│   │   └── TagScanner.php
│   │
│   ├── Directive/                  # ★ MỚI — xem 03-directives.md
│   │   ├── DirectiveRegistry.php
│   │   ├── Directive.php           (interface)
│   │   ├── ClosureDirective.php
│   │   ├── ParseContext.php / EmitContext.php
│   │   └── Builtin/                # ~70 directive lõi, mỗi cái một class
│   │
│   ├── Emit/
│   │   ├── BladeEmitter.php        # ← sao2blade/
│   │   ├── JsEmitter.php           # ← sao2js/render_generator.py
│   │   └── ViewWrapper.php         # ← templates/view.js
│   │
│   ├── Hydration/
│   │   ├── MarkerAllocator.php     # ★ id dùng chung cho cả hai emitter
│   │   └── HydrateId.php           # ← common/hydrate_id.py (khớp byte-for-byte)
│   │
│   ├── Expr/
│   │   ├── HelperResolver.php      # hàm → App.Helper.* / App.View.* + cảnh báo
│   │   └── PhpJsBridge.php         # cầu nối PHP↔JS bắt buộc, không phải tương thích cú pháp cũ
│   │                               # ← common/php_js_converter.py + php_converter.py
│   │
│   ├── Support/
│   │   ├── Str.php                 # substr/strpos an toàn — xem 05 §4
│   │   ├── Balanced.php            # ← common/utils.py
│   │   └── DeclarationTracker.php
│   │
│   └── Laravel/
│       ├── SaolaCompilerServiceProvider.php
│       ├── Commands/SaoCompileCommand.php
│       └── ThemeCompiler.php       # xem 04-runtime-compile.md
└── tests/
    ├── Parity/                     # ★ đối chiếu với compiler Python
    └── Unit/
```

## 5. Ranh giới với Node

Node **không** biến mất. Nó giữ đúng phần nó làm tốt:

| Việc | Ai làm |
|---|---|
| Đọc `sao.config.json`, resolve path/context/namespace | Node |
| Quét file, watch, debounce rebuild | Node |
| Sinh `registry.ts` cho bundler | Node |
| Vite / webpack plugin | Node |
| **Compile `.sao` → blade + js** | **PHP** |
| Compile lúc runtime, cài theme | **PHP** |

Node gọi PHP **một lần cho mỗi file**, nhận về cả hai output:

```bash
php compiler/bin/saoc compile <in.sao> --json
```

→ 112 spawn giảm còn 56, và mỗi spawn rẻ hơn (php 32ms vs python3 84ms).

Watch mode dùng chế độ worker thường trú (`saoc serve`, NDJSON qua stdin/stdout)
→ zero spawn cho hot reload. Xem [02-public-api.md §4](02-public-api.md#4-chế-độ-worker-thường-trú).

## 6. Hai bất biến không được phá

**I1 — Parity byte-for-byte.** Với cùng một `.sao`, output PHP phải giống output
Python **từng byte**, cho cả `.blade.php` và `.js`. Không "gần giống", không "về
mặt ngữ nghĩa là tương đương". Đây là cổng nghiệm thu của mọi phase.

**I2 — Marker id là hợp đồng ba bên.** `.blade.php` (SSR), `.js` (CSR) và runtime
`client/` phải đồng ý về id. `HydrateId.php` phải tái tạo chính xác
`common/hydrate_id.py`, kể cả các mode `terse` / `compact` / `md5` / `raw` và
biến môi trường `SAOLA_ID_MODE` (mặc định `terse`).
