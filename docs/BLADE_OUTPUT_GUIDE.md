# Saola V2 - Blade Output Guide

## Tổng Quan

Khi compile file `.sao`, phần template sẽ được sinh ra thành file Blade (`*.blade.php`) để Laravel server-side rendering.

---

## Output Format

### Blade File Structure

```php
{{-- File: resources/views/web/demo2.blade.php --}}

@if(isset($isOpen))
    {{-- Component HTML --}}
    <div class="demo2-component" @click="setIsOpen(!{{ $isOpen }})">
        Status: {{ $isOpen ? 'Open' : 'Closed' }}
    </div>
@endif
```

**Đặc điểm:**
- Chỉ chứa HTML/Blade directives, không có JavaScript hoặc CSS inline
- Variables được interpolate với `{{ }}`
- Directives chuyển từ `.sao` syntax sang Blade syntax

---

## Directive Mapping

### Conditional Statements

**.sao syntax:**
```one
@if($isOpen)
    <div>Opened</div>
@else
    <div>Closed</div>
@endif
```

**Blade output:**
```php
@if(isset($isOpen) && $isOpen)
    <div>Opened</div>
@else
    <div>Closed</div>
@endif
```

### Loops

**.sao syntax:**
```one
@foreach($items as $item)
    <div>{{ $item->name }}</div>
@endforeach
```

**Blade output:**
```php
@foreach($items as $item)
    <div>{{ $item->name }}</div>
@endforeach
```

### Sections & Blocks

**.sao syntax:**
```one
@section('content')
    <h1>Welcome</h1>
@endsection
```

**Blade output:**
```php
@section('content')
    <h1>Welcome</h1>
@endsection
```

### Variables & Interpolation

**.sao syntax:**
```one
<div class="user">
    {{ $user->name }}
    {{ $user->email }}
</div>
```

**Blade output:**
```php
<div class="user">
    {{ $user->name ?? '' }}
    {{ $user->email ?? '' }}
</div>
```

---

## State in Blade

Khi file `.sao` khai báo `@useState`:

**.sao input:**
```one
@useState($isOpen, false)
<div>
    Status: {{ $isOpen ? 'Open' : 'Closed' }}
</div>
```

**.blade output:**
```php
{{-- Variable dùng từ controller data --}}
<div>
    Status: {{ $isOpen ? 'Open' : 'Closed' }}
</div>
```

**Cách dùng trong Controller:**
```php
public function show() {
    return view('web.demo2', [
        'isOpen' => true,  // Initial state từ server
    ]);
}
```

---

## Data Binding

### Two-Way Data from Server

Server cung cấp dữ liệu:
```php
return view('web.pages.home', [
    'user' => auth()->user(),
    'items' => Item::all(),
    'config' => config('app'),
]);
```

Template sử dụng:
```blade
<div class="user-card">
    <h2>{{ $user->name }}</h2>
    <p>{{ $user->email }}</p>
</div>

@foreach($items as $item)
    <div class="item">{{ $item->title }}</div>
@endforeach
```

### Event Handlers

Events được khai báo trong `.sao` nhưng xử lý phía client (JavaScript):

**.sao:**
```one
<button @click($handleClick())>Click me</button>
```

**Blade output:**
```blade
<button id="btn-123" data-event="click" data-handler="handleClick">
    Click me
</button>
```

Client-side JavaScript sẽ xử lý event này khi view được hydrate.

---

## Component Composition

### Including Other Views

**.sao:**
```one
@include('components.header')
<div class="content">
    Content here
</div>
@include('components.footer')
```

**Blade output:**
```php
@include('components.header')
<div class="content">
    Content here
</div>
@include('components.footer')
```

### With Data Passing

```blade
@include('components.user-card', ['user' => $user])
```

---

## Layout Inheritance

### Using @extends

**.sao:**
```one
@extends('layouts.app')

@section('content')
    <h1>Page Title</h1>
@endsection
```

**Blade output:**
```php
@extends('layouts.app')

@section('content')
    <h1>Page Title</h1>
@endsection
```

---

## Security Considerations

### HTML Escaping

All variables sẽ bị escape tự động để prevent XSS:

```php
{{-- Escapes HTML --}}
{{ $user_input }}

{{-- Raw output (dùng với cảnh báo) --}}
{!! $trusted_html !!}
```

### CSRF Token Protection

Nếu có form submit:

```blade
<form method="POST" action="/save">
    @csrf
    <input type="text" name="title" />
    <button type="submit">Save</button>
</form>
```

---

## Comments in Blade

```blade
{{-- This is a Blade comment --}}

{{-- Multi-line comments work too --}}

<!-- HTML comments show in source -->
```

---

## Special Directives

### @auth / @guest

```blade
@auth
    <p>User is authenticated</p>
@else
    <p>User is guest</p>
@endauth
```

### @switch / @case

```blade
@switch($status)
    @case('pending')
        <span class="badge-yellow">Pending</span>
        @break
    @case('completed')
        <span class="badge-green">Completed</span>
        @break
    @default
        <span class="badge-gray">Unknown</span>
@endswitch
```

### @forelse

```blade
@forelse($items as $item)
    <div>{{ $item->name }}</div>
@empty
    <p>No items found</p>
@endforelse
```

---

## Blade Stack (Assets)

Khi view memerlukan assets khusus:

```blade
@push('scripts')
    <script>
        console.log('View loaded');
    </script>
@endpush

@push('styles')
    <style>
        .demo { color: red; }
    </style>
@endpush
```

---

## Output Blade File Path

**Convention:**
```
resources/views/
├── web/
│   ├── home.blade.php
│   ├── about.blade.php
│   └── pages/
│       ├── demo.blade.php
│       └── contact.blade.php
├── admin/
│   ├── dashboard.blade.php
│   ├── users.blade.php
│   └── settings.blade.php
└── mobile/
    ├── home.blade.php
    └── profile.blade.php
```

**File naming:**
- Input: `resources/sao/app/web/views/pages/demo.sao`
- Output: `resources/views/web/pages/demo.blade.php`

---

## Example: Complete Blade Output

### Input (.sao file):
```one
@let($title, "User Profile")
@await($user, fetchUser())

<blade>
@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ $title }}</h1>
        
        @if($user)
            <div class="user-info">
                <p>Name: {{ $user->name }}</p>
                <p>Email: {{ $user->email }}</p>
            </div>
        @else
            <p>User not found</p>
        @endif
    </div>
@endsection
</blade>
```

### Output (.blade.php file):
```php
{{-- File: resources/views/web/profile.blade.php --}}

@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ $title ?? 'User Profile' }}</h1>
        
        @if(isset($user) && $user)
            <div class="user-info">
                <p>Name: {{ $user->name }}</p>
                <p>Email: {{ $user->email }}</p>
            </div>
        @else
            <p>User not found</p>
        @endif
    </div>
@endsection
```

---

## Tham Khảo Thêm

- [Laravel Blade Documentation](https://laravel.com/docs/blade)
- [Blade Security Best Practices](https://laravel.com/docs/blade#security)
- Saola V2 Compiler Guide
