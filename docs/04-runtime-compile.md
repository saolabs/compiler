# 04 — Compile lúc runtime & cài theme

## 1. Luồng mục tiêu

```
Admin upload theme.zip
   │
   ├─ 1. Xác thực gói      (manifest, chữ ký, phiên bản)
   ├─ 2. Giải nén          → storage/themes/staging/{slug}/
   ├─ 3. Compile           → SaolaCompiler::compileDirectory()
   ├─ 4. Đổi chỗ nguyên tử → themes/{slug}/  (chỉ khi compile SẠCH)
   ├─ 5. Xả cache          view cache + Octane worker + manifest revision
   └─ 6. Kích hoạt
```

Compile chạy trong **queue job**, không phải trong request. Một theme 50 view mất
vài giây — đủ lâu để timeout một HTTP request và quá lâu để bắt admin ngồi đợi.

## 2. API

```php
$result = app(SaolaCompiler::class)->compileDirectory(
    $stagingDir,
    new CompileOptions(
        namespace: "theme.{$slug}.",
        emit: Target::Both,
        lang: Lang::Js,      // BẮT BUỘC — không TS lúc runtime (D7)
        sandbox: true,
        idMode: config('saola.id_mode'),   // PHẢI khớp app — Q3
    ),
);

if ($result->hasErrors()) {
    // huỷ staging, không đụng gì tới theme đang chạy
    return;
}
```

## 3. Vấn đề thật: registry hôm nay cần bundler

Đây là trở ngại kỹ thuật lớn nhất của toàn bộ usecase, và nó **không hiển nhiên**.

View compile ra hôm nay mở đầu bằng:

```js
import { View, ViewController, app, Application } from '@saolabs/client';
```

`@saolabs/client` là **bare module specifier** — trình duyệt không tự resolve
được. Và `registry-generator.js` sinh ra một file `.ts` tĩnh chứa
`() => import('./views/home.js')` để **bundler** cắt chunk.

Nghĩa là: PHP compile ra `.js` xong thì file đó **vẫn chưa chạy được trên trình
duyệt**. Cần một lượt Vite. Mà runtime không có Node.

### Ba lựa chọn

| | Cách | Đánh giá |
|---|---|---|
| **A** | Runtime chỉ compile Blade/SSR. JS cần build riêng | Theme cài lúc chạy **không có tương tác client**. Cắt mất phần lớn giá trị |
| **B** | Phát ESM chuẩn trình duyệt + **import map** + manifest JSON | ✅ **Chọn cái này** |
| **C** | Chạy Node build trong background job | Kéo Node trở lại production. Phá đúng mục tiêu ban đầu |

### Phương án B chi tiết

**1. Import map trong layout** — resolve bare specifier, không cần bundler:

```html
<script type="importmap">
{ "imports": { "@saolabs/client": "/static/saola/client.esm.js" } }
</script>
```

Trình duyệt hiện đại hỗ trợ sẵn. Runtime `client/` phải được build **một lần**
thành ESM và ship kèm package — không build lại khi cài theme.

**2. View của theme phát ESM thuần**, import con dùng đường dẫn tương đối (đã sẵn
tương đối trong compiler hôm nay, chỉ cần thêm đuôi `.js`).

**3. Registry thành manifest JSON** thay vì file `.ts` tĩnh:

```json
{
  "revision": "0d3f9c1a",
  "views": {
    "theme.dark.pages.home": "/static/themes/dark/views/pages/home.js"
  }
}
```

Runtime `ViewManager` fetch manifest lúc boot và `import(url)` khi cần. Chuyển từ
**bundler resolve** sang **runtime resolve**.

> **Việc phải làm ở phía client, không phải phía compiler.** `ViewManager` hiện
> đọc registry tĩnh; phải dạy nó đọc thêm manifest. Đây là công việc trong
> `client/`, cần lên lịch song song — xem [05-roadmap.md](05-roadmap.md) Phase 6.

**Đánh đổi:** view của theme không được tree-shake, không minify, mỗi view một
HTTP request (HTTP/2 làm nhẹ bớt). Chấp nhận được — theme cài động vốn là đường
đánh đổi hiệu năng lấy tính linh hoạt. App chính vẫn đi đường Vite như cũ.

## 4. Tính nguyên tử và cache

**Compile vào staging, đổi chỗ khi xong.** Không bao giờ ghi trực tiếp vào theme
đang phục vụ. Một lần compile lỗi giữa chừng mà ghi đè trực tiếp = site trắng.

```
storage/themes/staging/{slug}/   ← compile ở đây
        ↓ chỉ khi 0 lỗi
themes/{slug}/                   ← rename() nguyên tử
```

**Xả cache sau khi đổi chỗ** — bốn tầng, thiếu tầng nào cũng ra hành vi cũ:

1. Laravel view cache (`view:clear` cho các view bị ảnh hưởng)
2. `ViewContextRegistry` trong Octane worker — **sống suốt vòng đời worker**, không tự mất
3. Manifest revision (buộc client tải lại)
4. Cache trình duyệt (`?v={revision}` trên URL manifest)

Tầng 2 đặc biệt dễ quên: `OctaneServiceProvider.php:217` ghi rõ
`ViewContextRegistry` là worker-wide. Cài theme phải phát broadcast tới mọi
worker, hoặc bump revision để worker tự nhận ra state đã cũ.

## 5. Bảo mật — đọc kỹ mục này

**Cài theme = chạy code tuỳ ý trên server.** Đây là hệ quả của thiết kế, không
phải lỗ hổng có thể vá:

- `.sao` compile ra `.blade.php`, mà Blade **là PHP thực thi**
- `@php`, `@exec`, `@vars` chứa được biểu thức PHP tuỳ ý
- `sao.directives.php` của theme là code PHP được nạp thẳng

Nói thẳng ra: **"cài theme từ trang admin" tương đương "cài plugin"**, không
tương đương "upload ảnh". Phải xử lý theo đúng mức đó.

### Bắt buộc

1. **Chỉ admin, có quyền riêng.** Không phải quyền `admin` chung — một quyền
   `theme.install` tách bạch.
2. **Nguồn tin cậy.** Chữ ký gói, hoặc marketplace do bạn kiểm duyệt. Đừng
   nhận zip tuỳ ý từ internet.
3. **Đầy đủ log kiểm toán.** Ai cài, lúc nào, hash gói.
4. **Có đường lùi.** Giữ theme cũ, bật lại được bằng một cú click.

### Chế độ `sandbox: true` — giảm bề mặt, không phải khoá kín

Với theme không đầy đủ tin cậy, `sandbox` chặn:

| Chặn | Vì sao |
|---|---|
| `@php` / `@endphp` | PHP tuỳ ý |
| `@exec` | thực thi câu lệnh |
| Nạp `sao.directives.php` | code PHP của theme |
| Biểu thức gọi hàm ngoài allowlist | `system()`, `file_get_contents()`, ... |
| `@import` trỏ ra ngoài thư mục theme | path traversal |

**Đừng nhầm sandbox với an toàn.** Đó là bộ lọc dựa trên phân tích tĩnh, và mọi
bộ lọc như vậy đều có đường vòng. Nó nâng chi phí tấn công, không đưa về 0. Nguồn
tin cậy vẫn là biện pháp thật.

> **Câu hỏi mở Q1** trong [00-overview.md](00-overview.md#5-câu-hỏi-còn-mở--cần-chốt-trước-phase-3):
> theme có được phép chứa PHP không? Nếu **không**, sandbox trở thành mặc định
> và mô hình đơn giản hơn hẳn. Nếu **có**, hãy gọi đúng tên nó là hệ thống plugin
> và thiết kế quy trình duyệt cho tương xứng.

## 6. Giới hạn tài nguyên

Compile là công việc nặng CPU trên input do người khác cung cấp:

```php
'saola.theme' => [
    'max_files'        => 500,
    'max_file_bytes'   => 512 * 1024,
    'max_total_bytes'  => 20 * 1024 * 1024,
    'compile_timeout'  => 120,      // giây, cho cả batch
    'max_include_depth'=> 32,       // chặn @include đệ quy
],
```

`max_include_depth` không phải tuỳ chọn. **Đã kiểm tra: compiler Python hôm nay
KHÔNG có guard chống `@include` đệ quy** — các biến `depth` trong `template_ast.py`
chỉ dùng để đếm ngoặc, không phải đếm độ sâu include. Hôm nay chưa sao vì input là
view do chính đội viết. Khi input đến từ theme của người ngoài thì đó là một
đường DoS, nên bản PHP **bắt buộc** phải thêm guard này.

## 7. Checklist nghiệm thu cho usecase này

- [ ] Cài theme 50 view trong < 10s ở queue job
- [ ] Compile lỗi → theme đang chạy không hề bị đụng tới
- [ ] Theme mới cài render và hydrate đúng, không cần build Node
- [ ] Marker id khớp giữa blade và js của theme (kiểm tra tự động)
- [ ] Octane worker nhận theme mới không cần restart
- [ ] Gỡ theme sạch, quay lại theme trước được
- [ ] Theme `sandbox` chứa `@php` bị **từ chối kèm lỗi rõ ràng**, không phải bỏ qua im lặng
