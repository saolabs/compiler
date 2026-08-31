# 02 — API công khai: `SaolaCompiler`

Một class. Node dùng được, web dùng được, artisan dùng được.

## 1. Hợp đồng

```php
namespace Saola\Compiler;

final class SaolaCompiler
{
    public function __construct(?DirectiveRegistry $directives = null) {}

    /** Compile từ chuỗi — KHÔNG chạm filesystem. Đây là API gốc. */
    public function compile(string $source, CompileOptions $options): CompileResult;

    /** Tiện ích: đọc file, compile, ghi ra. Bọc mỏng quanh compile(). */
    public function compileFile(string $path, CompileOptions $options): CompileResult;

    /** Compile cả thư mục. Dùng cho cài theme. */
    public function compileDirectory(string $dir, CompileOptions $options): BatchResult;

    /** Đăng ký directive — xem 03-directives.md */
    public function directives(): DirectiveRegistry;
}
```

**`compile(string): CompileResult` là hàm cốt lõi, mọi thứ khác bọc quanh nó.**
Đây là điều khiến cùng một class phục vụ được cả CLI lẫn web: bản thân việc
compile không cần filesystem, không cần config Laravel, không cần biến môi trường.

## 2. Input / Output

```php
final class CompileOptions
{
    public string  $viewPath      = 'test.view';  // 'web.pages.home'
    public string  $functionName  = 'View';       // tên class sinh ra
    public string  $factoryName   = 'View';
    public string  $namespace     = '';           // 'web.pages.'
    public Target  $emit          = Target::Both; // Both | BladeOnly | JsOnly
    public Lang    $lang          = Lang::Js;     // Js | Ts  (runtime BẮT BUỘC Js — D7)
    public string  $idMode        = 'terse';      // PHẢI khớp app — xem 00 §5 Q3
    public string  $assetPrefix   = '';
    public bool    $sandbox       = false;        // theme không tin cậy — xem 04 §5
    public ?string $importBaseDir = null;         // resolve @import / component tag
}

final class CompileResult
{
    public ?string $blade;      // nội dung .blade.php
    public ?string $js;         // nội dung .js (hoặc .ts)
    public array   $css;        // các khối <style scoped> đã trích
    public array   $imports;    // component phụ thuộc — Node dùng để sinh registry
    public array   $markers;    // id hydrate đã cấp phát (để debug/kiểm tra)
    public array   $warnings;   // cảnh báo không chặn (vd @let dùng sai)
}
```

`blade` và `js` **luôn được sinh từ cùng một lần parse**, kể cả khi
`emit` chỉ lấy một trong hai. Đây là điều bảo đảm marker khớp (xem
[00 §3](00-overview.md#3-phần-thưởng-ngoài-dự-kiến-diệt-hẳn-một-lớp-bug)).

## 3. Ba người gọi

### 3.1 Node (build-time)

```bash
php compiler/bin/saoc compile resources/sao/web/pages/home.sao \
    --view-path=web.pages.home \
    --fn=HomeView \
    --json
```

stdout là một JSON object đúng hình `CompileResult`. Node ghi file, sinh registry,
báo lỗi. `index.js` đổi từ hai lần `spawn('python3', ...)` thành một lần
`spawn('php', ...)`.

### 3.2 Laravel (runtime)

```php
use Saola\Compiler\{SaolaCompiler, CompileOptions, Target};

$result = app(SaolaCompiler::class)->compile($sao, new CompileOptions(
    viewPath: 'theme.dark.pages.home',
    functionName: 'DarkHome',
    emit: Target::Both,
    sandbox: true,
));

file_put_contents($bladePath, $result->blade);
file_put_contents($jsPath,    $result->js);
```

Không process, không temp file, không I/O nếu bạn không muốn.

### 3.3 Artisan

```bash
php artisan sao:compile web            # một context
php artisan sao:compile --theme=dark   # một theme
```

## 4. Chế độ worker thường trú

Cho watch mode, tránh trả 32ms khởi động cho mỗi file:

```bash
php bin/saoc serve
```

NDJSON, một request một dòng:

```
→ {"id":1,"cmd":"compile","source":"...","options":{...}}
← {"id":1,"ok":true,"blade":"...","js":"..."}
```

Node giữ một tiến trình sống suốt phiên `--watch`. Hot reload một file về gần
bằng chi phí parse thuần.

> Đây là tối ưu, không phải yêu cầu. Làm ở Phase 5, sau khi parity đã xong.

## 5. Quy tắc thiết kế bắt buộc

Bốn quy tắc này giữ cho một class phục vụ được cả hai thế giới. Vi phạm là hỏng
usecase runtime:

1. **`compile()` không đọc/ghi filesystem.** Chuỗi vào, chuỗi ra. Resolve
   `@import` đi qua một `SourceResolver` inject được, không phải `file_get_contents()`
   rải rác trong parser.
2. **`compile()` không đọc biến môi trường.** `SAOLA_ID_MODE` được đọc **một lần**
   ở tầng CLI/ServiceProvider rồi truyền vào qua `CompileOptions`. Compiler Python
   hôm nay đọc `getenv` ngay trong `hydrate_id.py` — đừng chép lại thói quen đó.
3. **`compile()` không `exit()`, không `echo`.** Lỗi ném `CompileException` kèm
   `viewPath` + số dòng. CLI bắt và in; web bắt và log. Compiler Python hôm nay
   gọi `sys.exit(1)` và `print()` — không dùng được trong request.
4. **`compile()` không giữ trạng thái giữa các lần gọi.** Có tiền lệ thật:
   `php_js_converter.py` là singleton module-level và **đã từng rò method của view
   trước sang view sau** (xem comment `FIX(F3)` trong file đó). Dưới Octane, một
   worker sống hàng nghìn request — lỗi rò trạng thái sẽ nghiêm trọng hơn nhiều.
   Mọi state per-compile nằm trong object context, tạo mới mỗi lần `compile()`.
