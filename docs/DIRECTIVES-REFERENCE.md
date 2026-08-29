# Blade Directives - Tra cứu Nhanh & Ví dụ

> **Hướng dẫn Đầy đủ về Saola Custom Directives**  
> Nắm vững tất cả directives với bảng tra cứu nhanh và ví dụ chi tiết cho định dạng `.sao`.

---

## Mục lục

1. [Bảng Tra cứu Nhanh](#bảng-tra-cứu-nhanh)
2. [Quản lý State & Khai báo](#quản-lý-state--khai-báo)
3. [Xử lý Sự kiện](#xử-lý-sự-kiện)
4. [Data Binding](#data-binding)
5. [Attributes & Styling](#attributes--styling)
6. [Control Flow](#control-flow)
7. [Cấu trúc Template & Component](#cấu-trúc-template--component)
8. [Tiện ích & Nâng cao](#tiện-ích--nâng-cao)

---

## Bảng Tra cứu Nhanh

### Directives Khai báo (Đặt ở đầu file)

| Directive | Mô tả | Ví dụ |
|-----------|-------|-------|
| `@state(var = val)` | Khai báo reactive state (gán) | `@state(count = 0)` |
| `@states({k: v})` | Khai báo reactive state (object) | `@states({isOpen: false})` |
| `@props(...)` | Khai báo component properties | `@props(title, theme='dark')` |
| `@let(var = val)` | Biến local — **KHÔNG reactive** | `@let(label = 'Tổng cộng')` |
| `@computed(var = expr)` | Giá trị dẫn xuất — **reactive**, có memo | `@computed(total = price * qty)` |
| `@const(var = val)` | Hằng số hoặc destructured state | `@const([x, setX] = useState(0))` |
| `@vars(a, b)` | Khai báo biến non-reactive | `@vars(users, posts)` |
| `@import(path as N)`| Import component khác | `@import('btn' as Button)` |
| `@await` | Đánh dấu component async | `@await` |

---

### Directives Xử lý Sự kiện

| Directive | Sự kiện | Ví dụ |
|-----------|---------|-------|
| `@click(h)` | Click | `<button @click(increment())>` |
| `@input(h)` | Input | `<input @input(handle($event))>` |
| `@change(h)` | Change | `<select @change(update())>` |
| `@submit(h)` | Submit | `<form @submit(save())>` |
| `@keydown(h)` | Key Down | `<input @keydown(check(event))>` |
| `@mouseenter(h)` | Mouse Enter | `<div @mouseenter(show())>` |

---

### Directives Binding & Attributes

| Directive | Mô tả | Ví dụ |
|-----------|-------|-------|
| `@bind(var)` | Two-way data binding | `<input @bind(name) />` |
| `@class({...})` | Class động dựa trên điều kiện | `<div @class({'active': active})>` |
| `@style({...})` | Style inline động | `<div @style({'color': color})>` |
| `@attr({...})` | Attributes động tùy chỉnh | `<div @attr({id: myId})>` |
| `@show(cond)` | Hiện/Ẩn (display: none) | `<div @show(isVisible)>` |
| `@disabled(c)` | Bind thuộc tính disabled | `<button @disabled(loading)>` |

---

## Quản lý State & Khai báo

### `@state` — Reactive State (Assignment)
Khai báo biến reactive và tự động tạo hàm setter `setVarName()`.

```saola
@state(count = 0)
@state(
    name = 'Alice',
    items = []
)

<button @click(setCount(count + 1))>+1</button>
```

### `@states` — Reactive State (Object)
Giống `@state` nhưng sử dụng cú pháp JS Object.

```saola
@states({
    user: { id: 1, name: 'Saola' },
    loading: false
})
```

### `@const` — Destructured State
Sử dụng pattern `useState` quen thuộc của React/Hooks.

```saola
@const([message, setMessage] = useState('Hello'))
```

### `@let` vs `@computed` — biến LOCAL và giá trị DẪN XUẤT

`@let` khai báo một biến JS thường (`let`), tính **MỘT LẦN** lúc khởi tạo.
Nó **KHÔNG reactive** — nếu giá trị phụ thuộc vào state, nó sẽ **đứng im**
khi state đó đổi, dù nhìn cú pháp rất giống một giá trị dẫn xuất:

```saola
@states({ count: 0 })
@let(double = count * 2)     {{-- SAI Ý ĐỊNH nếu muốn double cập nhật theo count --}}
<template>
    <p>{{ count }}</p>   {{-- cập nhật đúng khi count đổi --}}
    <p>{{ double }}</p>  {{-- đứng im ở giá trị lúc khởi tạo, KHÔNG đổi theo count --}}
</template>
```

Compiler sẽ **cảnh báo lúc compile** khi phát hiện `@let(x = expr)` mà
`expr` đọc một state đã khai báo — nhưng vẫn compile bình thường (đây là
cảnh báo, không phải lỗi: `@let` phụ thuộc state đôi khi là chủ ý, ví dụ
snapshot giá trị tại thời điểm mount).

Cần giá trị **cập nhật theo state** → dùng `@computed`:

```saola
@states({ count: 0 })
@computed(double = count * 2)   {{-- ĐÚNG — reactive, có memo hoá --}}
<template><p>{{ double }}</p></template>
```

| Directive | Reactive | Tính lại khi nào | Dùng khi |
|---|---|---|---|
| `@let` | ❌ | Một lần, lúc khởi tạo | Hằng, chuỗi tĩnh, giá trị tính một lần (không phụ thuộc state) |
| `@computed` | ✅ (có memo, lazy) | Khi một dep trong `deps` đổi, VÀ có ai đọc | Giá trị dẫn xuất từ state |
| `@state` / `@states` | ✅ | Khi gọi setter | State của instance |
| `@props` / `@vars` | ✅ (qua trait cập nhật) | Khi cha truyền data mới | Dữ liệu từ ngoài truyền vào |

> **Giới hạn còn lại:** dependency của `{{ }}`/`@if`/`@foreach`... được phát
> hiện bằng cách quét định danh NGAY TRONG biểu thức — đọc state **gián
> tiếp qua lời gọi hàm** (`{{ label() }}` với `label()` đọc state trong thân
> hàm) sẽ KHÔNG được theo dõi, dù lời gọi vẫn chạy đúng. Xem
> `client/docs/GAPS_AND_ROADMAP.md` mục N1/D1 và
> `docs/FIX_PLAN_2026-08-14.md` §F3 "Giới hạn còn lại".

---

## Xử lý Sự kiện

Tất cả các sự kiện HTML chuẩn đều được hỗ trợ qua tiền tố `@`.

```saola
{{-- Gọi phương thức trong <script setup> --}}
<button @click(handleClick(event))>Click Me</button>

{{-- Thay đổi state trực tiếp --}}
<button @click(setCount(count - 1))>-</button>
```

---

## Data Binding

### `@bind` — Two-way Binding
Liên kết 2 chiều giữa input và state (giống `v-model`).

```saola
@state(username = '')
<input @bind(username) placeholder="Tên" />
<p>Xin chào, {{ username }}</p>
```

### `@class` — Dynamic CSS Classes
```saola
<div @class({
    'btn',
    'btn-primary': isPrimary,
    'disabled': loading
})>
```

### Truy cập dữ liệu: `.key` hay `['key']`?

Compiler dịch `.` thành `->` cho nhánh Blade và giữ nguyên `['key']` cho cả hai nhánh.
Nên **chọn theo hình dạng thật của dữ liệu ở phía SSR**, nếu không SSR và CSR sẽ lệch
nhau mà không báo lỗi:

| Nguồn | SSR là gì | Viết |
|---|---|---|
| `@vars(user)` — controller truyền Eloquent/stdClass | object | `user.name` |
| `@states({rows: [{id: 1}]})` — object literal viết trong `.sao` | **mảng PHP** | `row['id']` |
| `@props({rec: {}})` — giá trị mặc định | **mảng PHP** (`[]`) | `rec['name']` |
| Props nhận qua `@include`/thẻ tuỳ chỉnh | theo đúng shape mà cha truyền | theo cha |
| Dữ liệu chỉ fetch ở client | SSR không chạm tới | cả hai đều được |

Object literal viết trong `.sao` **luôn** thành mảng PHP: preprocessor đổi `{` thành `[`
không phân biệt ngữ cảnh. Vì vậy `@states({rows: [{ id: 1 }]})` sinh ra
`@useState($rows, [[ 'id' => 1 ]])`, và trong `@foreach` phải viết `row['id']` —
`row.id` sẽ thành `$row->id` và trả `null` ở SSR trong khi CSR vẫn đúng.

```saola
{{-- ĐÚNG: literal → mảng --}}
@states({ todos: [{ id: 1, done: false }] })
@foreach(todos as todo)
    <li @class({'done': todo['done']})>{{ todo['id'] }}</li>
@endforeach

{{-- ĐÚNG: object do controller truyền vào --}}
@vars(user)
<p>{{ user.name }}</p>
```

### `<style scoped>` — phạm vi CSS

Compiler dán một class scope (suy từ chính nội dung CSS) lên **mọi element của
view**, rồi ghép class đó thẳng vào selector — cùng cách `data-v-` của Vue:

```css
.card { }        →  .card.s7k2f1 { }
.a .b { }        →  .a .b.s7k2f1 { }
.c:hover { }     →  .c.s7k2f1:hover { }
@keyframes x { } →  giữ nguyên
```

Vì scope được quyết định lúc BIÊN DỊCH, nó không cần view có wrapper — trang
`@extends` layout (render qua `@block`, không có wrapper riêng) vẫn ăn style
bình thường.

> **Selector chỉ trúng element của CHÍNH view này.** `.wrap a { }` không áp cho
> `<a>` do component con sinh ra. Muốn tác động vào con thì dùng CSS global,
> hoặc cho con nhận class qua prop.

---

## Control Flow

### Loop: `@foreach`
Saola hỗ trợ cú pháp JS-like trong `@foreach`.

```saola
@foreach(items as item)
    <li>{{ item.name }}</li>
@endforeach

{{-- Với index/key --}}
@foreach(items as key => item)
    <li>{{ key }}: {{ item.name }}</li>
@endforeach
```

#### `__loop` — metadata của vòng lặp

Bên trong `@foreach`, biến `__loop` cho biết trạng thái vòng lặp (tương đương
`$loop` của Blade — compiler tự map sang `$loop` ở nhánh SSR):

| Thuộc tính | Ý nghĩa |
|---|---|
| `__loop.index` | chỉ số, tính từ 0 |
| `__loop.iteration` | lần lặp, tính từ 1 |
| `__loop.count` | tổng số phần tử |
| `__loop.remaining` | số phần tử còn lại |
| `__loop.first` / `__loop.last` | có phải phần tử đầu / cuối |
| `__loop.odd` / `__loop.even` | lần lặp lẻ / chẵn (theo `iteration`) |
| `__loop.depth` | độ sâu lồng nhau (bắt đầu từ 1) |
| `__loop.parent` | `__loop` của vòng ngoài (khi lồng nhau) |

```saola
@foreach(items as item)
    <li @class({'first': __loop.first})>{{ __loop.iteration }}. {{ item.name }}</li>
@endforeach
```

> **`__loop` là bản chụp CHỈ ĐỌC theo từng vòng.** Không gán vào nó, và đừng
> giữ lại để dùng sau vòng lặp.

> ⚠️ **Đừng dùng `__loop.index` làm định danh để xoá/sửa phần tử.**
> `@foreach` TÁI DÙNG element khi phần tử không đổi, mà tham số của event được
> tính MỘT LẦN lúc tạo element — nên sau khi danh sách thay đổi, chỉ số đã
> "nướng" vào handler là chỉ số CŨ. Truyền chính phần tử (hoặc `item.id`):
>
> ```saola
> {{-- SAI: xoá nhầm phần tử sau lần xoá đầu tiên --}}
> <a @click(remove(__loop.index))>Xóa</a>
>
> {{-- ĐÚNG --}}
> <a @click(remove(item))>Xóa</a>
> ```
> ```js
> remove(value){ setItems(items.filter(x => x !== value)); }
> ```
> `__loop.index` vẫn hoàn toàn dùng tốt để HIỂN THỊ (số thứ tự, `first`/`last`,
> class chẵn/lẻ) — chỉ tránh dùng nó làm khoá định danh.

### Condition: `@if` / `@elseif` / `@else`
```saola
@if(count > 10)
    <p>Nhiều</p>
@elseif(count > 0)
    <p>Vừa đủ</p>
@else
    <p>Trống</p>
@endif
```

---

## Cấu trúc Template & Component

### `@import` — Import Component
```saola
@import(__template__ + 'card' as Card)

<Card title="Sản phẩm" :data="product" />
```

Thuộc tính không tiền tố là chuỗi tĩnh; `:` trước tên thì giá trị là biểu thức.
Dạng `:` sinh ra đúng cùng lời gọi như `@include` với object props — kể cả
`stateKeys` — nên prop vẫn được đẩy lại khi state đổi.

Alias đặt bằng `as` còn dùng được ở mọi chỗ nhận **đường dẫn view**, hiện là
`@extends` và `@include`:

```saola
@import(__layout__ + 'docs' as layout)
@import('web.parts.card' as card)

@extends(layout)              {{-- y hệt @extends(__layout__ + 'docs') --}}
@include(card, {value: count})
```

Alias là **điểm neo lúc biên dịch, không phải biến**: preprocessor thay nó bằng
chính biểu thức đường dẫn trước khi transform, nên Blade và JS luôn trỏ cùng một
view. Chỉ alias khai báo tường minh (`as tên`, hoặc dạng object
`@import({tên: path})`) mới dùng được ở đây; dạng không có `as` chỉ sinh tên thẻ.

> `@includeIf` / `@includeWhen` / `@includeUnless` / `@includeFirst` chưa đi qua
> preprocessor — chúng chưa nhận alias, và cũng chưa đổi `{...}` thành mảng.

### Layout: `@extends` & `@block`
```saola
@extends(__layout__ + 'app')

@block('content')
    <h1>Trang Chủ</h1>
@endblock
```

---

## Tiện ích & Nâng cao

### `@exec` — Thực thi không Output
Dùng để chạy logic JS/PHP thầm lặng.

```saola
@exec(n = 0)
@while(n < 5)
    <p>{{ n }}</p>
    @exec(n++)
@endwhile
```

### `@show` / `@hide`
Điều khiển hiển thị bằng CSS `display`.

```saola
<div @show(isVisible)>Đang hiển thị</div>
```

---

**Cập nhật lần cuối:** 14 Tháng 4, 2026  
**Phiên bản:** 1.1.0
