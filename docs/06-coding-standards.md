# 06 — Chuẩn code

Ràng buộc cứng cho toàn bộ `compiler/`. Port 26.000 dòng mà mỗi file một
kiểu thì cuối cùng không ai đọc nổi.

## 1. PSR

| Chuẩn | Áp dụng |
|---|---|
| **PSR-4** | `Saola\Compiler\` → `src/`. Một class một file, tên file = tên class, thư mục = namespace. Không có ngoại lệ |
| **PSR-12** | Định dạng code |
| **PSR-1** | `StudlyCaps` cho class, `camelCase` cho method, `UPPER_SNAKE` cho hằng |

Kiểm tra nhanh — mọi file trong `src/` phải khớp namespace với đường dẫn:

```bash
composer dump-autoload -o --strict-psr
```

## 2. Bắt buộc trong mọi file

```php
<?php

declare(strict_types=1);

namespace Saola\Compiler\...;
```

`strict_types=1` **không phải tuỳ chọn**. Đây là compiler xử lý chuỗi, và ép
kiểu ngầm của PHP (`"0" == false`, `"1" == "01"`, `"abc" == 0` ở PHP cũ) chính
là nơi khác biệt ngữ nghĩa với Python trốn vào. Xem
[05 §5 ②](05-roadmap.md#-type-juggling--nơi-bug-im-lặng-sinh-ra).

## 3. Hướng đối tượng

**`final` là mặc định.** Bỏ `final` chỉ khi thật sự cần kế thừa và ghi rõ lý do.
Directive dùng interface + composition, không dùng kế thừa nhiều tầng.

**Không static mang trạng thái.** Method static chỉ được dùng cho hàm thuần
(`HydrateId::hash()`, `Re::match()`). Class như vậy để `private function __construct()`
để không ai lỡ tay khởi tạo.

Có tiền lệ thật: `php_js_converter.py` bên Python là singleton module-level và
**đã từng rò method của view trước sang view sau**. Dưới Octane, một worker sống
hàng nghìn request nên lỗi kiểu đó sẽ nặng hơn nhiều.

**Trạng thái per-compile nằm trong object context**, tạo mới mỗi lần `compile()`.

**Khai báo kiểu ở mọi chỗ** — tham số, giá trị trả về, thuộc tính. `mixed` phải
kèm chú thích lý do. Dùng `readonly` cho thuộc tính không đổi sau khi khởi tạo.

**Constructor promotion + named arguments** cho object nhiều tham số:

```php
new HydrateIdScope(prefix: $elementId, loopVar: '__loopIndex');
```

## 4. Quy ước riêng của cuộc port này

### Đi thuần byte

`substr` / `strlen` / `strpos`, `preg` **không** cờ `/u`.

**Không bao giờ trộn `mb_*` với offset lấy từ `preg`.** Mọi delimiter compiler đi
tìm đều là ASCII và UTF-8 tự đồng bộ, nên offset byte luôn rơi đúng biên ký tự.
Trộn hai hệ là hỏng ngầm, và chỉ hỏng khi gặp file có dấu tiếng Việt.

Chỗ nào thật sự cần ngữ nghĩa unicode (`\w`, `\b` áp lên text người dùng) thì
thêm `/u` **và một comment nói rõ vì sao**.

### Mọi lệnh regex đi qua `Re`

Không gọi thẳng `preg_*`. `preg_*` trả `null` khi lỗi mà không ném gì; giá trị
null đó sẽ trôi qua cả pipeline và chỉ lộ ra ở output sai.

```php
use Saola\Compiler\Support\Re;

Re::match('/^@if\s*\(/', $line, $m);        // ✅
preg_match('/^@if\s*\(/', $line, $m);       // ❌
```

### So sánh nghiêm ngặt

`===` / `!==` mặc định. `if (!$x)` bị cấm cho chuỗi — dùng `if ($x === '')` hoặc
`if ($x === null)`. Python coi `"0"` là truthy, PHP coi là falsy; compiler này
xử lý đầy những chuỗi `"0"`, `""`, `"false"`.

### Comment giải thích cái vô hình

Không chép lại code thành lời. Comment tồn tại để ghi lại **cái mà đọc code
không thấy**: vì sao một hành vi kỳ lạ là cố ý, ràng buộc nào đang được giữ, bug
nào đã xảy ra thật.

Bản Python có sẵn nhiều comment loại này — **chúng là tài sản, hãy mang theo khi
port**, đừng bỏ lại. Ví dụ ghi chú trong `hydrate_id.py` về việc `block-outlet`
không có chữ số đã ghi lại một lần va chạm id có thật.

Comment viết tiếng Việt, khớp với phần còn lại của repo.

## 5. Không port cái đã chết

Bản Python có nhánh chết. Chép sang là mang nợ đi theo.

Đã gặp: `HydrateIdGenerator.format_js_id()` rẽ theo `dynamic_parts` nhưng **hai
nhánh trả về giá trị y hệt nhau**. Bản PHP viết thẳng kết quả và ghi một dòng
comment giải thích. Hành vi giống hệt, ít hơn một nhánh giả.

Quy tắc: hành vi phải khớp từng byte; **cấu trúc code thì không bắt buộc**. Nhánh
chết, biến không dùng (`rest` trong `_compact`), bộ đếm không ai đọc
(`_block_outlet_counter`) — bỏ hết, và ghi chú lại đã bỏ gì.

## 6. Test

**Cổng parity là bắt buộc.** Mọi module port xong phải có một cổng trong
`tests/Parity/` chứng minh khớp với bản Python. Xem
[tests/Parity/README.md](../tests/Parity/README.md).

Unit test (PHPUnit, `tests/Unit/`) dùng cho ca biên và hồi quy — **bổ sung** chứ
không thay thế cổng parity. Cái xác lập tính đúng đắn là parity.

## 7. Kiểm tra trước khi commit

```bash
php -l $(find src -name '*.php')      # cú pháp
composer dump-autoload -o --strict-psr   # PSR-4
./tests/Parity/run-all.sh             # parity — phải xanh hết
```
