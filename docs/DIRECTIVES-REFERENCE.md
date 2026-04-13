# Blade Directives - Tra cứu Nhanh & Ví dụ

> **Hướng dẫn Đầy đủ về One Laravel Blade Directives**  
> Nắm vững tất cả directives với bảng tra cứu nhanh và ví dụ chi tiết

---

## Mục lục

1. [Bảng Tra cứu Nhanh](#bảng-tra-cứu-nhanh)
2. [Quản lý State](#quản-lý-state)
3. [Xử lý Sự kiện](#xử-lý-sự-kiện)
4. [Data Binding](#data-binding)
5. [Attributes & Styling](#attributes--styling)
6. [Control Flow](#control-flow)
7. [Cấu trúc Template](#cấu-trúc-template)
8. [API & Async](#api--async)
9. [Directives Nâng cao](#directives-nâng-cao)

---

## Bảng Tra cứu Nhanh

### Directives Quản lý State

| Directive                   | Aliases | Mô tả                                     | Ví dụ Đơn giản                    |
| --------------------------- | ------- | ----------------------------------------- | --------------------------------- |
| `@useState($var, $initial)` | -       | Khai báo reactive state variable          | `@useState($count, 0)`            |
| `@vars($var1, $var2...)`    | -       | Khai báo non-reactive variables           | `@vars($user, $posts)`            |
| `@let($var = value)`        | -       | Khai báo local variable (trong component) | `@let($total = $price * $qty)`    |
| `@const($var = value)`      | -       | Khai báo constant variable                | `@const($API_URL = '/api/users')` |

[→ Xem ví dụ chi tiết](#quản-lý-state)

---

### Directives Xử lý Sự kiện

| Directive              | Aliases         | Mô tả                | Ví dụ Đơn giản                 |
| ---------------------- | --------------- | -------------------- | ------------------------------ |
| `@click(handler)`      | `@onClick`      | Xử lý click events   | `@click(increment())`          |
| `@input(handler)`      | `@onInput`      | Xử lý input events   | `@input(handleSearch($event))` |
| `@change(handler)`     | `@onChange`     | Xử lý change events  | `@change(updateValue())`       |
| `@submit(handler)`     | `@onSubmit`     | Xử lý form submit    | `@submit(saveForm())`          |
| `@keyup(handler)`      | `@onKeyup`      | Xử lý keyup events   | `@keyup(handleKey($event))`    |
| `@keydown(handler)`    | `@onKeydown`    | Xử lý keydown events | `@keydown(checkEscape())`      |
| `@focus(handler)`      | `@onFocus`      | Xử lý focus events   | `@focus(showHelp())`           |
| `@blur(handler)`       | `@onBlur`       | Xử lý blur events    | `@blur(validate())`            |
| `@mouseenter(handler)` | `@onMouseenter` | Xử lý mouse enter    | `@mouseenter(showTooltip())`   |
| `@mouseleave(handler)` | `@onMouseleave` | Xử lý mouse leave    | `@mouseleave(hideTooltip())`   |

[→ Xem ví dụ chi tiết](#xử-lý-sự-kiện)

---

### Directives Data Binding

| Directive         | Aliases        | Mô tả                | Ví dụ Đơn giản                               |
| ----------------- | -------------- | -------------------- | -------------------------------------------- |
| `@bind($var)`     | `@model($var)` | Two-way data binding | `<input @bind($username) />`                 |
| `@val($var)`      | -              | Bind value attribute | `<input @val($email) />`                     |
| `@checked($var)`  | -              | Bind checkbox/radio  | `<input type="checkbox" @checked($agree) />` |
| `@selected($var)` | -              | Bind select option   | `<option @selected($isDefault)>`             |

[→ Xem ví dụ chi tiết](#data-binding)

---

### Directives Attributes & Styling

| Directive           | Aliases | Mô tả                       | Ví dụ Đơn giản                    |
| ------------------- | ------- | --------------------------- | --------------------------------- |
| `@attr([...])`      | -       | Dynamic attributes          | `@attr(['disabled' => $loading])` |
| `@class([...])`     | -       | Dynamic CSS classes         | `@class(['active' => $isActive])` |
| `@style([...])`     | -       | Dynamic inline styles       | `@style(['color' => $textColor])` |
| `@show($condition)` | -       | Toggle visibility (display) | `@show($isVisible)`               |
| `@hide($condition)` | -       | Ẩn element                  | `@hide($isHidden)`                |

[→ Xem ví dụ chi tiết](#attributes--styling)

---

### Directives Control Flow

| Directive                     | Aliases | Mô tả                  | Ví dụ Đơn giản                    |
| ----------------------------- | ------- | ---------------------- | --------------------------------- |
| `@if($condition)`             | -       | Conditional rendering  | `@if($user->isAdmin())`           |
| `@elseif($condition)`         | -       | Else if condition      | `@elseif($user->isModerator())`   |
| `@else`                       | -       | Else condition         | `@else`                           |
| `@endif`                      | -       | Kết thúc if block      | `@endif`                          |
| `@foreach($items as $item)`   | -       | Lặp qua array          | `@foreach($posts as $post)`       |
| `@endforeach`                 | -       | Kết thúc foreach block | `@endforeach`                     |
| `@for($i = 0; $i < 10; $i++)` | -       | For loop               | `@for($i = 0; $i < $count; $i++)` |
| `@endfor`                     | -       | Kết thúc for block     | `@endfor`                         |
| `@while($condition)`          | -       | While loop             | `@while($hasMore)`                |
| `@endwhile`                   | -       | Kết thúc while block   | `@endwhile`                       |
| `@switch($value)`             | -       | Switch statement       | `@switch($type)`                  |
| `@case($value)`               | -       | Switch case            | `@case('admin')`                  |
| `@break`                      | -       | Break switch           | `@break`                          |
| `@default`                    | -       | Default case           | `@default`                        |
| `@endswitch`                  | -       | Kết thúc switch block  | `@endswitch`                      |
| `@empty($array)`              | -       | Kiểm tra empty         | `@empty($posts)`                  |
| `@isset($var)`                | -       | Kiểm tra isset         | `@isset($user)`                   |

[→ Xem ví dụ chi tiết](#control-flow)

---

### Directives Cấu trúc Template

| Directive           | Aliases            | Mô tả                     | Ví dụ Đơn giản                             |
| ------------------- | ------------------ | ------------------------- | ------------------------------------------ |
| `@extends($layout)` | -                  | Extend layout template    | `@extends($__layout__.'base')`             |
| `@block('name')`    | `@section('name')` | Định nghĩa content block  | `@block('content')`                        |
| `@endBlock`         | `@endSection`      | Kết thúc block            | `@endBlock`                                |
| `@useBlock('name')` | `@yield('name')`   | Render block content      | `@useBlock('content')`                     |
| `@include($view)`   | -                  | Include partial view      | `@include('partials.header')`              |
| `@component($name)` | -                  | Include component         | `@component('card', ['title' => 'Hello'])` |
| `@slot('name')`     | -                  | Định nghĩa component slot | `@slot('header')`                          |
| `@endSlot`          | -                  | Kết thúc slot             | `@endSlot`                                 |

[→ Xem ví dụ chi tiết](#cấu-trúc-template)

---

### Directives API & Async

| Directive            | Aliases | Mô tả               | Ví dụ Đơn giản                 |
| -------------------- | ------- | ------------------- | ------------------------------ |
| `@fetch($var, $url)` | -       | Fetch data từ API   | `@fetch($users, '/api/users')` |
| `@await($promise)`   | -       | Chờ async operation | `@await($loadingData)`         |

[→ Xem ví dụ chi tiết](#api--async)

---

### Directives Nâng cao

| Directive                | Aliases              | Mô tả                        | Ví dụ Đơn giản                            |
| ------------------------ | -------------------- | ---------------------------- | ----------------------------------------- |
| `@view($config)`         | `@template($config)` | Component wrapper config     | `@view('div', :class="container")`        |
| `@register('resources')` | `@setup`, `@script`  | Định nghĩa component methods | `@register('resources')`                  |
| `@verbatim`              | -                    | Bỏ qua blade compilation     | `@verbatim {{ vue syntax }} @endverbatim` |
| `@php`                   | -                    | Inline PHP code              | `@php $total = $a + $b; @endphp`          |

[→ Xem ví dụ chi tiết](#directives-nâng-cao)

---

## Quản lý State

### `@useState` - Reactive State Variable

**Mục đích:** Khai báo reactive state variable để trigger UI updates khi thay đổi

**Cú pháp:**

```blade
@useState($variableName, $initialValue)
```

**Ví dụ Cơ bản:**

```blade
@useState($count, 0)

<div>
    <p>Số đếm: {{ $count }}</p>
    <button @click(setCount(count + 1))>Tăng</button>
    <button @click(setCount(count - 1))>Giảm</button>
</div>
```

**Multiple States:**

```blade
@useState($username, '')
@useState($email, '')
@useState($isValid, false)

<form>
    <input @bind($username) placeholder="Tên đăng nhập" />
    <input @bind($email) placeholder="Email" />
    <button @click(validate())>Kiểm tra</button>
    @if($isValid)
        <p>Form hợp lệ!</p>
    @endif
</form>

@register('resources')
    <script setup>
    export default {
        validate() {
            setIsValid(username.length > 0 && email.includes('@'));
        }
    }
    </script>
@endregister
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

## Xử lý Sự kiện

### `@click` - Click Events

**Mục đích:** Xử lý click events trên elements

**Cú pháp:**

```blade
@click(handlerFunction())
@click(handlerFunction($event))
```

**Ví dụ:**

```blade
@useState($count, 0)

<button @click($setCount($count + 1))>
    Đã click {{ $count }} lần
</button>
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

## Data Binding

### `@bind` - Two-Way Binding

**Mục đích:** Tạo two-way data binding giữa input và state

**Cú pháp:**

```blade
<input @bind($variableName) />
```

**Ví dụ:**

```blade
@useState($name, '')

<div>
    <input @bind($name) placeholder="Nhập tên" />
    <p>Xin chào, {{ $name }}!</p>
</div>
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

## Attributes & Styling

### `@class` - Dynamic CSS Classes

**Mục đích:** Toggle CSS classes dựa trên conditions

**Cú pháp:**

```blade
@class([
    'class-name' => $condition,
    'static-class'
])
```

**Ví dụ:**

```blade
@useState($isActive, false)

<div
    @class([
        'container',
        'active' => $isActive,
        'disabled' => !$isActive
    ])
>
    Nội dung
</div>
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

## Control Flow

### `@if` / `@elseif` / `@else` - Conditional Rendering

**Mục đích:** Render nội dung có điều kiện

**Cú pháp:**

```blade
@if($condition)
    {{-- content --}}
@elseif($condition2)
    {{-- content --}}
@else
    {{-- content --}}
@endif
```

**Ví dụ:**

```blade
@vars($user)

@if($user->isAdmin())
    <div class="admin-panel">
        <h2>Bảng điều khiển Admin</h2>
    </div>
@elseif($user->isModerator())
    <div class="moderator-panel">
        <h2>Bảng điều khiển Moderator</h2>
    </div>
@else
    <div class="user-panel">
        <h2>Bảng điều khiển Người dùng</h2>
    </div>
@endif
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

### `@foreach` - Lặp qua Array

**Mục đích:** Lặp qua arrays/collections

**Cú pháp:**

```blade
@foreach($array as $item)
    {{-- content --}}
@endforeach
```

**Ví dụ:**

```blade
@vars($products)

<div class="product-grid">
    @foreach($products as $product)
        <div class="product-card">
            <h3>{{ $product->name }}</h3>
            <p>${{ $product->price }}</p>
            <button @click(addToCart({{ $product->id }}))>
                Thêm vào giỏ
            </button>
        </div>
    @endforeach
</div>
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

## Cấu trúc Template

### `@extends` - Extend Layout

**Mục đích:** Extend parent layout template

**Cú pháp:**

```blade
@extends($layoutName)
```

**Ví dụ:**

```blade
@extends($__layout__.'base')

@block('title')
    Trang chủ
@endBlock

@block('content')
    <div class="home">
        <h1>Chào mừng đến Trang chủ</h1>
    </div>
@endBlock
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

### `@include` - Include Partial

**Mục đích:** Include reusable view partials

**Cú pháp:**

```blade
@include('view.path')
@include('view.path', ['key' => 'value'])
```

**Ví dụ:**

```blade
<div class="page">
    @include('partials.header')

    <main>
        @include('partials.sidebar', ['menu' => $menuItems])

        <div class="content">
            <h1>Nội dung chính</h1>
        </div>
    </main>

    @include('partials.footer')
</div>
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

## API & Async

### `@fetch` - Fetch API Data

**Mục đích:** Fetch data từ API endpoint

**Cú pháp:**

```blade
@fetch($variableName, $url)
```

**Ví dụ:**

```blade
@fetch($users, '/api/users')

<div class="users-list">
    @foreach($users as $user)
        <div class="user">
            <h3>{{ $user->name }}</h3>
            <p>{{ $user->email }}</p>
        </div>
    @endforeach
</div>
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

## Directives Nâng cao

### `@register` - Component Methods

**Mục đích:** Định nghĩa component methods và lifecycle hooks

**Cú pháp:**

```blade
@register('resources')
    <script setup>
    export default {
        // Methods và lifecycle
    }
    </script>
@endregister
```

**Ví dụ:**

```blade
@useState($count, 0)

<div>
    <p>Số đếm: {{ $count }}</p>
    <button @click(increment())>+</button>
</div>

@register('resources')
    <script setup>
    export default {
        created() {
            console.log('Component đã tạo');
        },

        increment() {
            setCount(count + 1);
        }
    }
    </script>
@endregister
```

[← Quay lại bảng](#bảng-tra-cứu-nhanh)

---

## Xem thêm

- [Blade Compiler Documentation](BLADE-COMPILER.md) - Tìm hiểu cách directives được compile
- [Saola Framework](SAOLA-FRAMEWORK.md) - Hiểu runtime powers directives
- [State Management](STATE-MANAGEMENT.md) - Deep dive vào reactive state

---

**Cập nhật lần cuối:** 8 Tháng 1, 2026  
**Phiên bản:** 1.0.0
