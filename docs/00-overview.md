# 00 — Tổng quan & quyết định

> **Ưu tiên hiện tại (cập nhật):** mục tiêu trước mắt là compiler **chạy được và
> cho ra kết quả giống hệt bản Python**. Usecase cài theme mô tả dưới đây là đích
> đến, nhưng đã đẩy về Phase 7 — xem [05-roadmap.md](05-roadmap.md#3-các-phase).
> Nó vẫn dẫn dắt thiết kế (nhất là D5 và quy tắc API ở [02 §5](02-public-api.md#5-quy-tắc-thiết-kế-bắt-buộc)),
> chỉ là không được cài đặt trước.

## 1. Usecase dẫn dắt

Toàn bộ thiết kế phục vụ một kịch bản cụ thể:

```
Admin bấm "Cài theme"
  → upload theme.zip
  → giải nén vào themes/{slug}/
  → SaolaCompiler compile toàn bộ .sao trong đó
  → theme hoạt động ngay
```

Không SSH. Không chạy `npm run build`. Không redeploy. Không có Python và Node
trên server production.

Đây không phải "tối ưu tốc độ" hay "đồng nhất stack". Đó là **một năng lực mà
kiến trúc hiện tại không thể có**: compiler Python bị Node spawn ra, mà Node
không có mặt lúc runtime.

## 2. Ba mục tiêu

### M1 — Composer package, compile in-process

`composer require saola/compiler`. Không spawn process, không yêu cầu runtime
ngoài PHP. Chạy được trong request, trong queue job, trong Octane worker.

### M2 — Directive registry

Hôm nay muốn thêm một directive phải sửa `template_ast.py` (thêm nhánh vào chuỗi
`if/elif` dài 1.600 dòng) **và** `render_generator.py` (thêm nhánh `isinstance`)
**và** `hydrate_processor.py`. Ba file lõi cho một directive.

Mục tiêu: một class, đăng ký một dòng, không đụng lõi. Chi tiết ở
[03-directives.md](03-directives.md).

### M3 — Một class công khai `SaolaCompiler`

Cùng một API cho hai người gọi:

- **Node** (`@saolabs/builder` trên npm) — build-time, watch, Vite/Webpack plugin
- **Laravel** (`saola/compiler` trên composer) — runtime, artisan command

Khi cài cả hai, chúng chạy **cùng một compiler**, không phải hai bản cài đặt phải
tự giữ đồng bộ với nhau. Chi tiết ở [02-public-api.md](02-public-api.md).

## 3. Phần thưởng ngoài dự kiến: diệt hẳn một lớp bug

Kiến trúc hôm nay chạy **hai lần duyệt cây độc lập**:

```
.sao ──> python3 sao2blade/cli.py ──> .blade.php   (tự sinh marker id)
    └──> python3 sao2js/cli.py    ──> .js          (tự sinh marker id)
```

Hai tiến trình riêng biệt, không nói chuyện với nhau, nhưng **bắt buộc phải sinh
ra dãy marker id giống hệt nhau**. Lệch một id là hydration nhân đôi DOM.

Bản PHP chạy **một lần duyệt, hai bộ phát mã**, dùng chung một allocator id:

```
.sao ──> parse ──> AST ──┬──> BladeEmitter ──> .blade.php
                         └──> JsEmitter    ──> .js
                    (một MarkerAllocator duy nhất)
```

Marker desync không còn là bug phải đi săn — nó trở thành **trạng thái không biểu
diễn được**. Xét theo lịch sử bug của repo này, đây có thể là giá trị lớn nhất
của cả cuộc port, hơn cả việc bỏ Python.

## 4. Quyết định đã chốt

| # | Quyết định | Lý do |
|---|---|---|
| D1 | Composer package `saola/compiler`, namespace `Saola\Compiler\` | Khớp `saola/core` đã có |
| D2 | PHP `^8.3`, chỉ cần `ext-json` + `ext-mbstring`, **không dependency runtime** | Compiler Python hiện tại cũng zero-dep. Giữ nguyên tính chất đó |
| D3 | Thư mục `compiler/`, nạp vào `saola/` qua composer path repo | Giống cách `core/` đang được nạp |
| D4 | **Một lần parse, hai emitter**, chung `MarkerAllocator` | Xem mục 3 |
| D5 | Port cả preprocessor (đang là JS) sang PHP | Bắt buộc — xem [01](01-architecture.md#3-phát-hiện-quan-trọng-preprocessor-cũng-phải-port) |
| D6 | Runtime mode phát ESM + import map + manifest JSON, **không bundler** | Xem [04](04-runtime-compile.md#3-vấn-đề-thật-registry-hôm-nay-cần-bundler) |
| D7 | Theme compile lúc runtime **chỉ phát JS, không TS** | Trình duyệt không chạy TS, mà runtime không có bundler |
| D8 | Giữ compiler Python làm **oracle đối chiếu** trong CI cho tới khi parity 100% | Cổng nghiệm thu duy nhất đáng tin — xem [05](05-roadmap.md) |
| D9 | Node giữ vai trò điều phối (watch, config, vite plugin), không port sang PHP | Đó là phần Node làm tốt, và không cần lúc runtime |

## 5. Câu hỏi còn mở — cần chốt trước Phase 3

| # | Câu hỏi | Vì sao quan trọng |
|---|---|---|
| Q1 | Theme cài từ admin có được phép chứa `@php`, `@exec`, raw PHP không? | Cho phép = admin cài theme là **RCE toàn quyền**. Xem [04 §5](04-runtime-compile.md#5-bảo-mật-đọc-kỹ-mục-này) |
| Q2 | Theme có cần TypeScript không? | D7 giả định **không**. Nếu có thì runtime bắt buộc phải có bước build |
| Q3 | `SAOLA_ID_MODE` (`terse`/`compact`/`md5`/`raw`) — có khoá cứng một mode cho theme không? | Theme compile ở máy khác mode với app = hydration hỏng toàn bộ |
| Q4 | Bỏ hẳn compiler Python sau parity, hay giữ song song một thời gian? | Đề xuất: giữ trong CI 1 release cycle rồi xoá |
| Q5 | Directive do theme đăng ký: cho phép hay chỉ dùng directive core? | Directive là closure PHP → cùng câu chuyện RCE với Q1 |

## 6. Cái này **không** giải quyết

Nói rõ để không ai kỳ vọng nhầm:

- **Không** thay thế bundler cho luồng build chính. Vite vẫn là đường chuẩn cho app.
- **Không** làm app nhanh hơn lúc chạy. Đây là compiler, không phải runtime.
- **Không** tự động khiến theme bên thứ ba an toàn. Xem [04 §5](04-runtime-compile.md#5-bảo-mật-đọc-kỹ-mục-này).
- **Không** thay đổi cú pháp `.sao`. Parity byte-for-byte là ràng buộc cứng.
