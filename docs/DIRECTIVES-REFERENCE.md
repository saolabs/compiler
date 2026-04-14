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
| `@let(var = val)` | Biến local có thể thay đổi | `@let(total = price * qty)` |
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
| `@class([...])` | Class động dựa trên điều kiện | `<div @class(['active': active])>` |
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
<div @class([
    'btn',
    'btn-primary': isPrimary,
    'disabled': loading
])>
```

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
