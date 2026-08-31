# Examples — đầu vào và đầu ra

Mỗi example là một cặp: một file `.sao` trong [`src/`](src/) và output tương ứng
trong [`expected/`](expected/) gồm **cả hai đích** — `.blade.php` (SSR) và
`.js`/`.ts` (CSR).

Đọc song song hai file output là cách nhanh nhất để hiểu compiler làm gì, đặc
biệt là **hợp đồng marker** giữa SSR và CSR:

```blade
{{-- expected/02-state.blade.php --}}
<span @class([$__VIEW_ID__ . '-e11'])>
    @startMarker('output', 'e11o1'){{ $name }}@endMarker('output', 'e11o1')
```

```js
// expected/02-state.js
this.html(`e11`, "span", parentElement, {}, (parentElement) => [
    this.output(`e11o1`, parentElement, true, ["name"], (parentElement) => name),
```

Cùng một id `e11o1` ở hai bên. Lệch id là hydrate nhân đôi DOM — xem
[docs/01-architecture.md §6](../docs/01-architecture.md) (bất biến I2).

## Danh sách

| Example | Nội dung |
|---|---|
| `01-basic` | HTML tĩnh — không state, không directive |
| `02-state` | `@states` + `{{ }}` có marker + `@click` |
| `03-props-children` | `@props`, `@children`, `@class` có điều kiện |
| `04-foreach` | `@foreach` + `@key` (reconcile theo field) |
| `05-conditional` | `@if` / `@elseif` / `@else` |
| `06-switch` | `@switch` / `@case` / `@default` |
| `07-bindings` | `@class`, `@style`, `@attr`, `:attr` rút gọn |
| `08-computed` | `@computed` — state dẫn xuất có memo hoá |
| `09-script-setup` | method `<script setup>` gọi qua `this.view` |
| `10-scoped-style` | `<style scoped>` — scope quyết định lúc biên dịch |
| `11-verbatim` | `@verbatim` giữ nguyên văn |
| `12-await-fetch` | `@await` / `@fetch` — bật prerender |
| `13-syntax` | ⚠️ Cú pháp **Vue** (`v-model`, `v-for`) mà Saola không cài đặt — minh hoạ compiler xử lý directive lạ thế nào (truyền nguyên vẹn), KHÔNG phải mẫu chạy đúng. Xem docs/05-roadmap.md §9 |
| `14-demo-full` | View **tổng hợp** nhiều tính năng cùng lúc trên một component thật — nơi hay lộ bug thật hơn các ví dụ đơn lẻ ở trên. `@import` alias + component tag (`<UserItem>`, children qua `<UserGroup>`), `@props`/`@vars`/`@let`/`@states`/`@const` (kể cả destructuring `useState`), `@computed`, `@switch`, `@if/@else`, `@foreach` + `@key`, và tổ hợp `@class`+`@style`+`@attr`+`:attr` **cùng một element** (đúng chỗ bug §8②③ từng nằm) |

## Chạy như một cổng kiểm

```bash
./tests/Parity/examples/run.sh
```

So output hiện tại với ảnh chụp trong `expected/`. Có trong `run-all.sh`.

## Sinh lại khi output đổi CÓ CHỦ Ý

```bash
./examples/regenerate.sh
git diff examples/expected/     # ← đây mới là thứ cần review
```

## Vì sao golden được SINH RA, không viết tay

Output viết tay chỉ chứng minh **người viết nghĩ gì**, không chứng minh
**compiler làm gì** — nó mù với chính khâu sinh code mà nó định kiểm.

Nhưng golden do compiler tự sinh thì có một lỗ hổng hiển nhiên: compiler sai
vẫn sinh ra golden sai một cách nhất quán. Chỗ bịt lỗ hổng đó là các example
này **cũng chạy qua cổng `full-pipeline`**, nơi so với compiler Python:

```
examples/  →  golden gate      : "PHP không đổi ngoài ý muốn"   (còn mãi)
examples/  →  full-pipeline    : "PHP giống hệt Python"          (đến khi P6 gỡ Python)
```

Cái thứ hai là thứ làm cái thứ nhất đáng tin.

## Thêm example mới

1. Đặt file `.sao` vào `src/` theo dạng `NN-tên.sao`
2. Chạy `./regenerate.sh`
3. **Đọc output sinh ra** — nếu không đúng ý thì lỗi ở compiler, không phải ở golden
4. Chạy `./tests/Parity/full-pipeline/run.sh` để xác nhận Python cũng ra đúng vậy
