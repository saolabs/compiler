# Saola V2 - Tài Liệu Chi Tiết File .sao

> Hướng dẫn hoàn chỉnh về cách tạo, cấu trúc, khai báo và đầu ra của file `.sao`

---

## Mục lục

1. [Giới Thiệu](#giới-thiệu)
2. [Cấu Trúc Cơ Bản](#cấu-trúc-cơ-bản)
3. [Phần Khai Báo (Declarations)](#phần-khai-báo-declarations)
4. [Phần Template (HTML/Blade)](#phần-template-htmlblade)
5. [Phần Script (JavaScript/TypeScript)](#phần-script-javascripttypescript)
6. [Phần Style (CSS/SCSS)](#phần-style-cssscss)
7. [Các Directive Chi Tiết](#các-directive-chi-tiết)
8. [Đầu Ra Blade (.blade.php)](#đầu-ra-blade-bladephp)
9. [Đầu Ra JavaScript](#đầu-ra-javascript)
10. [Ví Dụ Thực Tế](#ví-dụ-thực-tế)
11. [Best Practices](#best-practices)

---

## Giới Thiệu

File `.sao` là định dạng file đặc biệt của Saola V2 cho phép bạn:
- Khai báo state và variables
- Viết template HTML/Blade
- Định nghĩa logic JavaScript/TypeScript
- Quản lý styles CSS/SCSS

**Tất cả trong một file duy nhất**, sau đó compiler sẽ:
- Sinh ra file `*.blade.php` cho Laravel server-side rendering
- Sinh ra file `*.js` cho client-side logic

---

## Cấu Trúc Cơ Bản

### Cấu Trúc Đầy Đủ

```sao
<!-- 1. DECLARATIONS (Khai báo) -->
@vars($temp1, $temp2)
@useState($state, initialValue)
@let($variable = expression)
@const($CONSTANT = value)

<!-- 2. TEMPLATE (HTML/Blade) -->
<blade>
  <!-- HTML content -->
  <div>{{ $state }}</div>
</blade>

<!-- 3. SCRIPT (JavaScript) -->
<script setup lang="ts">
export default {
  methods: {
    handleClick() { ... }
  }
}
</script>

<!-- 4. STYLE (CSS) -->
<style scoped>
.component { ... }
</style>
```

### Cấu Trúc Tối Giản

```sao
@useState($count, 0)
<button @click($setCount($count + 1))>Click count: {{ $count }}</button>
```

---

## Phần Khai Báo (Declarations)

Phần khai báo xuất hiện ở **đầu file** trước khi template.

### 1. @vars - Khai Báo Biến Từ Controller/Include

```sao
@vars($var1, $var2, $var3)
```

**Đặc điểm:**
- Định nghĩa các biến được **truyền từ controller** hoặc **từ @include(view, data)**
- Có thể khai báo multiple variables cùng lúc
- Non-reactive (không trigger re-render khi thay đổi)
- Giá trị khởi tạo từ controller/parent view
- **Luôn được khai báo đầu tiên**

**Ví dụ 1: Biến từ Controller**

```sao
@vars($users, $categories, $currentUser)

<blade>
<div>
  <h1>{{ $currentUser->name }}</h1>
  <p>Users: {{ count($users) }}</p>
  <p>Categories: {{ count($categories) }}</p>
</div>
</blade>
```

Controller gửi:
```php
return view('users.list', [
  'users' => User::all(),
  'categories' => Category::all(),
  'currentUser' => Auth::user()
]);
```

**Ví dụ 2: Biến từ @include**

```sao
@vars($product, $showPrice, $discount)

<blade>
<div class="product-card">
  <h3>{{ $product->name }}</h3>
  @if($showPrice)
    <p>Price: ${{ $product->price }}</p>
    @if($discount)
      <p>Discount: {{ $discount }}%</p>
    @endif
  @endif
</div>
</blade>
```

Gọi từ view khác:
```blade
@include('product.card', [
  'product' => $item,
  'showPrice' => true,
  'discount' => 10
])
```

---

### 2. @useState - Khai Báo Reactive State

```sao
@useState($variableName, initialValue)
```

**Đặc điểm:**
- Tạo reactive variable
- Tự động sinh hàm setter `$setVariableName()`
- Khi giá trị thay đổi, view tự động re-render

**Ví dụ:**

```sao
@useState($count, 0)
@useState($isOpen, false)
@useState($username, '')
@useState($items, [])
@useState($user, ['id' => 1, 'name' => 'John'])

<div>
  <p>Count: {{ $count }}</p>
  <button @click($setCount($count + 1))>Increment</button>
  
  <p>Username: {{ $username }}</p>
  <input @bind($username) />
</div>
```

**Đầu ra setter:**
```javascript
// Đăng ký state với __STATE__
const set$count = __STATE__.__.register('count');

// Lưu giá trị hiện tại
let count = null;

// Setter wrapper - cập nhật cả local variable và state
const setCount = (state) => {
  count = state;           // Update local value
  set$count(state);        // Notify state manager
};

// Đăng ký setter vào state
__STATE__.__.setters.setCount = setCount;
__STATE__.__.setters.count = setCount;

// Hàm update thay thế (dùng khi có canUpdateStateByKey)
const update$count = (value) => {
  if (__STATE__.__.canUpdateStateByKey) {
    updateStateByKey('count', value);
    count = value;
  }
};
```

---

### 3. @let - Khai Báo Local Variable

```sao
@let($variableName = expression)
```

**Đặc điểm:**
- Khai báo variable local (non-reactive)
- Được tính toán mỗi khi render
- Không có setter

**Ví dụ:**

```sao
@useState($price, 100)
@let($discountedPrice = $price * 0.9)
@let($message = 'Welcome to ' . $storeName)

<!-- Destructuring from array/object -->
@let([$firstName, $lastName] = explode(' ', $fullName))
@const({$host, $port} = $config)

<div>
  <p>Original: {{ $price }}</p>
  <p>Discounted: {{ $discountedPrice }}</p>
  <p>{{ $message }}</p>
</div>
```

---

### 4. @const - Khai Báo Hằng Số

```sao
@const($CONSTANT_NAME = value)
```

**Đặc điểm:**
- Khai báo constant (không thay đổi)
- Thường dùng cho config, URLs, keys
- Viết HOA theo quy ước

**Ví dụ:**

```sao
@const($API_URL = '/api')
@const($MAX_ITEMS = 100)
@const($CATEGORIES = ['work' => 'Work', 'personal' => 'Personal', 'urgent' => 'Urgent'])

<div>
  <a href="{{ $API_URL }}/users">Users API</a>
  <p>Max items: {{ $MAX_ITEMS }}</p>
  
  @foreach($CATEGORIES as $key => $label)
    <span>{{ $label }}</span>
  @endforeach
</div>
```

---

## Phần Template (HTML/Blade)

### Container Tags (Tùy Chọn)

Template có thể được bao trong:

#### 1. `<blade>...</blade>` (Khuyến khích cho Laravel)

```sao
<blade>
  <div class="component">
    <h1>{{ $title }}</h1>
    @if($isVisible)
      <p>Content here</p>
    @endif
  </div>
</blade>
```

**Đặc điểm:**
- Chỉ định Blade template
- Tốt nhất cho Laravel integration
- Compiler sẽ extractayout nội dung

#### 2. `<template>...</template>` (Generic)

```sao
<template>
  <div>{{ $content }}</div>
</template>
```

**Đặc điểm:**
- Generic template container
- Tương thích với nhiều framework
- Nội dung vẫn được compile thành Blade

#### 3. Không Bao (Inline)

```sao
@useState($count, 0)
<div>
  <p>Count: {{ $count }}</p>
</div>
```

**Đặc điểm:**
- Đơn giản nhất
- Tất cả HTML sau declarations được coi là template
- Phù hợp file nhỏ

---

### Biến và Interpolation

#### Variable Syntax

```sao
<!-- Interpolation đơn giản -->
<p>{{ $variable }}</p>

<!-- Expression -->
<p>{{ $count + 1 }}</p>

<!-- Ternary -->
<p>{{ $isAdmin ? 'Admin' : 'User' }}</p>

<!-- Nối string -->
<p>{{ 'Hello ' . $name . '!' }}</p>
```

#### Null-Safe Operator

```sao
<!-- Auto null-check trong Blade output -->
<p>{{ $user->name }}</p>  <!-- Sinh: {{ $user->name ?? '' }} -->
```

---

### Control Flow Directives

#### @if / @elseif / @else / @endif

```sao
@if($user->isAdmin)
  <div>Admin Panel</div>
@elseif($user->isModerator)
  <div>Moderator Tools</div>
@else
  <div>Regular User</div>
@endif
```

#### @foreach / @endforeach

```sao
@foreach($items as $item)
  <div class="item">
    <h3>{{ $item->title }}</h3>
    <p>{{ $item->description }}</p>
  </div>
@endforeach

<!-- Nested loop -->
@foreach($categories as $category)
  <div class="category">
    <h2>{{ $category->name }}</h2>
    @foreach($category->items as $item)
      <p>{{ $item }}</p>
    @endforeach
  </div>
@endforeach
```

#### @for / @endfor

```sao
@for($i = 0; $i < 10; $i++)
  <div>Item {{ $i }}</div>
@endfor
```

#### @while / @endwhile

```sao
@while($hasMore)
  <div>{{ $current }}</div>
@endwhile
```

#### @switch / @case / @break / @default / @endswitch

```sao
@switch($status)
  @case('active')
    <span class="badge badge-success">Active</span>
    @break
  @case('inactive')
    <span class="badge badge-secondary">Inactive</span>
    @break
  @default
    <span class="badge badge-danger">Unknown</span>
@endswitch
```

---

### Event Directives

#### @click - Xử Lý Click Event

```sao
<!-- Gọi method -->
<button @click(increment())>Add</button>

<!-- Với parameter -->
<button @click(deleteItem($item->id))>Delete</button>

<!-- Multiple actions -->
<button @click(action1(), action2())>Multi</button>
```

#### @input - Xử Lý Input Event

```sao
<input @input(handleSearch($event.target.value)) />
```

#### @change - Xử Lý Change Event

```sao
<select @change(updateCategory($event.target.value))>
  <option value="cat1">Category 1</option>
  <option value="cat2">Category 2</option>
</select>
```

#### @submit - Xử Lý Form Submit

```sao
<form @submit(handleSubmit())>
  <input type="text" name="email" />
  <button type="submit">Submit</button>
</form>
```

#### Các Event Khác

```sao
@keyup(handleKey($event))      <!-- Keyup -->
@keydown(handleKey($event))    <!-- Keydown -->
@focus(showHelp())              <!-- Focus -->
@blur(hideHelp())               <!-- Blur -->
@mouseenter(show())             <!-- Mouse Enter -->
@mouseleave(hide())             <!-- Mouse Leave -->
```

---

### Data Binding Directives

#### @bind - Two-Way Data Binding

```sao
@useState($email, '')
<input @bind($email) />
<!-- $email tự động update khi input thay đổi -->
```

#### @val - Bind Value

```sao
<input type="text" value="{{ $username }}" />
```

#### @checked - Bind Checkbox/Radio

```sao
@useState($agree, false)
<input type="checkbox" @checked($agree) />
```

#### @selected - Bind Select Option

```sao
@useState($selectedCategory, 'work')
<select>
  <option value="work" @selected($selectedCategory == 'work')>Work</option>
  <option value="personal" @selected($selectedCategory == 'personal')>Personal</option>
</select>
```

---

### Attribute Directives

#### @attr - Dynamic Attributes

```sao
@useState($isDisabled, false)
<button @attr(['disabled' => $isDisabled])>
  Click Me
</button>

<!-- Compile thành: -->
<!-- @if($isDisabled) disabled @endif -->
```

#### @class - Dynamic Classes

```sao
@useState($isActive, false)
@const($BASE_CLASS = 'btn')

<div @class([$BASE_CLASS, 'active' => $isActive, 'large' => $size > 10])>
  Button
</div>

<!-- Compile thành Blade @class() -->
```

#### @style - Dynamic Styles

```sao
@useState($textColor, 'black')

<div @style(['color' => $textColor, 'font-size' => $fontSize . 'px'])>
  Styled Text
</div>
```

#### @show / @hide - Visibility

```sao
@useState($isVisible, true)

@if($isVisible)
  <div>This shows when isVisible is true</div>
@endif

@if(!$isHidden)
  <div>This shows when isHidden is false</div>
@endif
```

---

## Phần Script (JavaScript/TypeScript)

### Cấu Trúc Script

```sao
<script setup lang="ts">
export default {
  // Methods
  incrementCounter() {
    this.setCount(this.count + 1);
  },
  
  // Lifecycle hooks
  init() {
    console.log('Component initialized');
  },
  
  // Async operations
  async loadData() {
    const response = await fetch('/api/data');
    this.data = await response.json();
  }
}
</script>
```

### Methods

Định nghĩa các function/method:

```sao
<script setup>
export default {
  // Method đơn giản
  greet() {
    console.log('Hello');
  },
  
  // Method nhận parameter
  updateName(newName) {
    this.setUsername(newName);
  },
  
  // Method async
  async fetchUsers() {
    try {
      const res = await fetch('/api/users');
      return await res.json();
    } catch (error) {
      console.error(error);
    }
  }
}
</script>
```

### Lifecycle Hooks

```sao
<script setup>
export default {
  // Khi component initialize
  init() {
    console.log('Init hook called');
  },
  
  // Khi component được mount vào DOM
  mounted() {
    console.log('Mounted hook called');
  },
  
  // Khi component bị destroy
  destroy() {
    console.log('Destroy hook called');
  }
}
</script>
```

### Accessing State & Variables

```sao
@useState($count, 0)

<script setup>
export default {
  handleClick() {
    // ✅ Access state - truy cập giá trị hiện tại
    console.log(this.count);  // 0, 1, 2, ...
    
    // ✅ Update state - cập nhật state qua setter
    this.setCount(this.count + 1);
  },
  
  // ✅ Hoặc dùng arrow function
  increment: function() {
    const newValue = this.count + 1;
    this.setCount(newValue);  // Cập nhật state và trigger re-render
  }
}
</script>
```

**Cách hoạt động trong thực tế:**

```javascript
// Khi gọi this.count:
// 1. Truy cập local variable `count` đã khai báo
// 2. Lấy giá trị hiện tại

// Khi gọi this.setCount(newValue):
// 1. Cập nhật local variable: count = newValue
// 2. Notify state manager: set$count(newValue)
// 3. State manager trigger re-render
// 4. Template được render lại với giá trị mới
```

### TypeScript Support

```sao
<script setup lang="ts">
interface User {
  id: number;
  name: string;
  email: string;
}

export default {
  users: [] as User[],
  
  addUser(user: User): void {
    this.users.push(user);
  },
  
  async fetchUser(id: number): Promise<User | null> {
    const res = await fetch(`/api/users/${id}`);
    return await res.json();
  }
}
</script>
```

---

## Phần Style (CSS/SCSS)

### Style Tag

```sao
<style scoped>
.component {
  padding: 20px;
  background-color: #fff;
}

.component h1 {
  color: #333;
  font-size: 24px;
}
</style>
```

### Scoped Styles

```sao
<!-- Scoped styles chỉ apply cho component này -->
<style scoped>
.button {
  padding: 10px 20px;
}
</style>
```

### SCSS Support

```sao
<style scoped lang="scss">
$primary-color: #007bff;
$border-radius: 4px;

.component {
  background-color: $primary-color;
  border-radius: $border-radius;
  
  &:hover {
    opacity: 0.9;
  }
  
  .button {
    padding: 10px;
  }
}
</style>
```

### External Stylesheet

```sao
<link rel="stylesheet" href="/css/components.css" />
```

---

## Các Directive Chi Tiết

### Directive State Management

| Directive | Cấu trúc | Mô tả | Ví dụ |
|-----------|----------|-------|-------|
| @vars | `@vars($v1, $v2)` | Biến từ controller/@include (khai báo đầu tiên) | `@vars($users, $settings)` |
| @useState | `@useState($var, init)` | Reactive state | `@useState($count, 0)` |
| @let | `@let($var = expr)` | Local variable | `@let($total = $price * 2)` |
| @const | `@const($VAR = val)` | Constant | `@const($API = '/api')` |

### Directive Event Handling

| Directive | Cấu trúc | Mô tả |
|-----------|----------|-------|
| @click | `@click(handler())` | Click event |
| @input | `@input(handler($e))` | Input event |
| @change | `@change(handler())` | Change event |
| @submit | `@submit(handler())` | Form submit |
| @keyup | `@keyup(handler($e))` | Keyup event |
| @keydown | `@keydown(handler($e))` | Keydown event |
| @focus | `@focus(handler())` | Focus event |
| @blur | `@blur(handler())` | Blur event |
| @mouseenter | `@mouseenter(handler())` | Mouse enter |
| @mouseleave | `@mouseleave(handler())` | Mouse leave |

### Directive Binding

| Directive | Cấu trúc | Mô tả |
|-----------|----------|-------|
| @bind | `@bind($var)` | Two-way binding |
| @val | `@val($var)` | Value binding |
| @checked | `@checked($var)` | Checkbox binding |
| @selected | `@selected($cond)` | Select binding |

### Directive Attributes

| Directive | Cấu trúc | Mô tả |
|-----------|----------|-------|
| @attr | `@attr([...])` | Dynamic attributes |
| @class | `@class([...])` | Dynamic classes |
| @style | `@style([...])` | Dynamic styles |
| @show | `@show($cond)` | Show/hide element |
| @hide | `@hide($cond)` | Hide element |

---

## Đầu Ra Blade (.blade.php)

### Cấu Trúc Đầu Ra

Khi compile file `.sao`, phần template sẽ được sinh thành `.blade.php`:

```php
{{-- File: compiled.blade.php --}}

<!-- Variables -->
@if(isset($isOpen))
  <!-- HTML content -->
  <div class="demo-component" @click="setIsOpen(!{{ $isOpen }})">
    Status: {{ $isOpen ? 'Open' : 'Closed' }}
  </div>
@endif
```

### Directive Mapping

#### Control Flow Directives

**Input (.sao):**
```sao
@if($isActive)
  <div>Active</div>
@else
  <div>Inactive</div>
@endif
```

**Output (.blade.php):**
```php
@if(isset($isActive) && $isActive)
  <div>Active</div>
@else
  <div>Inactive</div>
@endif
```

#### Loops

**Input:**
```sao
@foreach($items as $item)
  <div>{{ $item->name }}</div>
@endforeach
```

**Output:**
```php
@foreach($items as $item)
  <div>{{ $item->name ?? '' }}</div>
@endforeach
```

#### Variable Interpolation

**Input:**
```sao
<p>{{ $user->name }}</p>
```

**Output:**
```php
<p>{{ $user->name ?? '' }}</p>
```

---

## Đầu Ra JavaScript

### JavaScript File Structure

Khi compile, JavaScript output có cấu trúc sau:

```javascript
// 1. Imports
import { View } from 'onelaraveljs';
import { app } from 'onelaraveljs';

// 2. Constants
const __VIEW_PATH__ = 'web.pages.demo';
const __VIEW_NAMESPACE__ = 'web.pages.';
const __VIEW_TYPE__ = 'view';

// 3. Class Definition
class WebPagesDemo extends View {
  constructor(App, systemData) {
    super(__VIEW_PATH__, __VIEW_TYPE__);
    this.__ctrl__.setApp(App);
  }

  __setup__(__data__, systemData) {
    // Setup logic here
    const { 
      __base__, __layout__, __page__, __component__, 
      __template__, __context__, __partial__ 
    } = systemData;
    
    // Get utilities
    const App = app.make("App");
    const Helper = app.make("Helper");
    
    // Initialize state
    const __STATE__ = this.__ctrl__.states;
    const useState = (value) => __STATE__.__useState(value);
    
    // Define variables and state
    // ... variable declarations ...
    
    // User-defined methods
    this.__ctrl__.setUserDefined({
      // Methods from <script setup>
      handleClick: () => { ... },
      loadData: async () => { ... }
    });
    
    // Setup render function
    this.__ctrl__.setup({
      viewType: 'view',
      path: __VIEW_PATH__,
      data: __data__,
      // ... more config ...
    });
  }
}

// 4. Export
export default WebPagesDemo;
```

### Class Naming Convention

Format: `[Context][Path][Filename]View`

**Ví dụ:**

Input file: `resources/sao/web/views/pages/demo2.sao`

- Context: `web`
- Path: `pages`
- Filename: `demo2`
- **Output class**: `WebPagesDemo2View`

### State Variable Output

Khi khai báo state:

```sao
@useState($count, 0)
```

Output JavaScript:

```javascript
// 1. Đăng ký state
const set$count = __STATE__.__.register('count');

// 2. Lưu giá trị hiện tại
let count = 0;

// 3. Setter wrapper - cập nhật local + state manager
const setCount = (state) => {
  count = state;        // Cập nhật local value
  set$count(state);     // Notify state manager để trigger re-render
};

// 4. Đăng ký setter vào state manager
__STATE__.__.setters.setCount = setCount;
__STATE__.__.setters.count = setCount;

// 5. Update function (alternative approach)
const update$count = (value) => {
  if (__STATE__.__.canUpdateStateByKey) {
    updateStateByKey('count', value);
    count = value;
  }
};
```

**Cách hoạt động:**
1. `__STATE__.__.register('count')` - Đăng ký state với quản lý global
2. `let count = 0` - Local variable lưu current value
3. `setCount()` - Wrapper function cập nhật cả local + global state
4. `__STATE__.__.setters` - Đăng ký setter vào state manager để có thể gọi từ template

### User-Defined Methods

Methods từ `<script setup>`:

**Input:**
```sao
<script setup>
export default {
  increment() {
    this.setCount(this.count + 1);
  }
}
</script>
```

**Output:**
```javascript
this.__ctrl__.setUserDefined({
  increment: function() {
    this.setCount(this.count + 1);
  }
});
```

---

## Ví Dụ Thực Tế

### Ví Dụ 1: Counter Component

**File: counter.sao**

```sao
@vars($tempValue)
@useState($count, 0)
@const($STEP = 1)

<blade>
<div class="counter-container">
  <h2>Counter: {{ $count }}</h2>
  
  <div class="button-group">
    <button @click(decrement())>-</button>
    <button @click(reset())>Reset</button>
    <button @click(increment())>+</button>
  </div>
  
  @if($count > 10)
    <p class="warning">Count is high!</p>
  @endif
</div>
</blade>

<script setup lang="ts">
export default {
  increment() {
    this.setCount(this.count + this.$STEP);
  },
  
  decrement() {
    this.setCount(Math.max(0, this.count - this.$STEP));
  },
  
  reset() {
    this.setCount(0);
  }
}
</script>

<style scoped>
.counter-container {
  padding: 20px;
  border: 1px solid #ddd;
  border-radius: 8px;
}

.button-group {
  margin: 10px 0;
}

.button-group button {
  margin: 0 5px;
  padding: 10px 20px;
  cursor: pointer;
}

.warning {
  color: red;
  font-weight: bold;
}
</style>
```

---

### Ví Dụ 2: Todo List

**File: todo-list.sao**

```sao
@vars($tempFilter, $sortOrder)
@useState($todos, [])
@useState($newTodo, '')
@let($totalTodos = count($todos))
@let($completedTodos = array_filter($todos, fn($t) => $t['done']))
@const($MAX_TITLE_LENGTH = 100)

<blade>
<div class="todo-container">
  <h1>My Todos</h1>
  
  <form @submit(addTodo())>
    <input 
      @bind($newTodo)
      @keyup(handleKeyup($event))
      placeholder="Add a new todo..."
      maxlength="{{ $MAX_TITLE_LENGTH }}"
    />
    <button type="submit">Add</button>
  </form>
  
  <p>{{ $totalTodos }} total, {{ count($completedTodos) }} completed</p>
  
  @if(count($todos) > 0)
    <ul class="todo-list">
      @foreach($todos as $index => $todo)
        <li @class(['todo-item', 'completed' => $todo['done']])>
          <input 
            type="checkbox" 
            @checked($todo['done'])
            @change(toggleTodo($index))
          />
          <span>{{ $todo['title'] }}</span>
          <button @click(deleteTodo($index))>×</button>
        </li>
      @endforeach
    </ul>
  @else
    <p class="empty-state">No todos yet. Add one to get started!</p>
  @endif
</div>
</blade>

<script setup lang="ts">
interface Todo {
  id: string;
  title: string;
  done: boolean;
}

export default {
  handleKeyup(event: KeyboardEvent) {
    if (event.key === 'Enter') {
      this.addTodo();
    }
  },
  
  addTodo() {
    if (!this.newTodo.trim()) return;
    
    const todo: Todo = {
      id: Date.now().toString(),
      title: this.newTodo,
      done: false
    };
    
    this.setTodos([...this.todos, todo]);
    this.setNewTodo('');
  },
  
  toggleTodo(index: number) {
    const updated = [...this.todos];
    updated[index].done = !updated[index].done;
    this.setTodos(updated);
  },
  
  deleteTodo(index: number) {
    this.setTodos(
      this.todos.filter((_, i) => i !== index)
    );
  }
}
</script>

<style scoped>
.todo-container {
  max-width: 500px;
  margin: 20px auto;
  padding: 20px;
}

form {
  display: flex;
  gap: 10px;
  margin: 20px 0;
}

form input {
  flex: 1;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

form button {
  padding: 10px 20px;
  background: #007bff;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.todo-list {
  list-style: none;
  padding: 0;
}

.todo-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border-bottom: 1px solid #eee;
}

.todo-item.completed span {
  text-decoration: line-through;
  color: #999;
}

.todo-item button {
  margin-left: auto;
  padding: 5px 10px;
  background: #dc3545;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.empty-state {
  text-align: center;
  color: #999;
  padding: 20px;
}
</style>
```

---

### Ví Dụ 3: Data Fetching

**File: user-profile.sao**

```sao
@vars($users, $loading, $error)
@useState($selectedUserId, null)

<blade>
<div class="profile-container">
  <h1>User Profile</h1>
  
  @if($loading)
    <div class="loading">Loading users...</div>
  @elseif($error)
    <div class="error">{{ $error }}</div>
  @else
    <select @change(selectUser($event.target.value))>
      <option value="">Select a user...</option>
      @foreach($users as $user)
        <option value="{{ $user->id }}">{{ $user->name }}</option>
      @endforeach
    </select>
    
    @if($selectedUserId)
      <div class="user-card">
        <h2>{{ $users[$selectedUserId]->name }}</h2>
        <p>Email: {{ $users[$selectedUserId]->email }}</p>
        <p>Phone: {{ $users[$selectedUserId]->phone }}</p>
      </div>
    @endif
  @endif
</div>
</blade>

<script setup lang="ts">
interface User {
  id: number;
  name: string;
  email: string;
  phone: string;
}

export default {
  init() {
    this.loadUsers();
  },
  
  async loadUsers() {
    this.loading = true;
    this.error = null;
    
    try {
      const response = await fetch('/api/users');
      if (!response.ok) throw new Error('Failed to fetch users');
      
      this.users = await response.json();
    } catch (error) {
      this.error = error.message;
    } finally {
      this.loading = false;
    }
  },
  
  selectUser(userId: string) {
    this.setSelectedUserId(parseInt(userId) || null);
  }
}
</script>

<style scoped>
.profile-container {
  max-width: 600px;
  margin: 20px auto;
  padding: 20px;
}

.loading, .error {
  padding: 20px;
  border-radius: 4px;
}

.loading {
  background: #e7f3ff;
  color: #0066cc;
}

.error {
  background: #ffe7e7;
  color: #cc0000;
}

select {
  width: 100%;
  padding: 10px;
  margin: 10px 0;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.user-card {
  margin-top: 20px;
  padding: 20px;
  border: 1px solid #ddd;
  border-radius: 4px;
  background: #f9f9f9;
}

.user-card h2 {
  margin-top: 0;
}
</style>
```

---

## Best Practices

### 1. Naming Conventions

```sao
<!-- ✅ Dùng tiền tố $ cho state/variable -->
@useState($isOpen, false)
@useState($username, '')

<!-- ✅ Viết HOA cho constants -->
@const($MAX_LENGTH = 100)
@const($API_URL = '/api')

<!-- ✅ Prefix hàm setter với 'set' -->
this.setIsOpen(true)
this.setUsername('John')

<!-- ❌ Tránh tên quá chung -->
@useState($x, 0)
@const($VAL = 'something')
```

### 2. State Organization

```sao
<!-- ✅ Nhóm các state liên quan -->
@useState($firstName, '')
@useState($lastName, '')
@useState($email, '')

<!-- ❌ Tránh quá nhiều state nhỏ -->
@useState($a, '')
@useState($b, '')
@useState($c, '')
```

### 3. Method Structure

```sao
<script setup>
export default {
  // ✅ Organize methods logically
  
  // Initialization
  init() { },
  
  // Event handlers
  handleClick() { },
  handleInputChange() { },
  
  // Data fetching
  async loadData() { },
  async saveData() { },
  
  // Utils
  validate() { },
  format() { }
}
</script>
```

### 4. Template Organization

```sao
<!-- ✅ Organize template sections -->
<blade>
  <!-- Header -->
  <div class="header">
    <h1>{{ $title }}</h1>
  </div>
  
  <!-- Content -->
  <div class="content">
    <!-- Main content -->
  </div>
  
  <!-- Footer -->
  <div class="footer">
    <!-- Footer content -->
  </div>
</blade>
```

### 5. Error Handling

```sao
@vars($error, $successMessage)
@useState($isLoading, false)

<script setup>
export default {
  async loadData() {
    this.setIsLoading(true);
    this.error = null;
    
    try {
      const data = await fetch('/api/data');
      // process data
    } catch (error) {
      this.error = error.message;
    } finally {
      this.isLoading = false;
    }
  }
}
</script>
```

### 6. Type Safety

```sao
<script setup lang="ts">
interface User {
  id: number;
  name: string;
  email: string;
}

export default {
  async fetchUser(id: number): Promise<User> {
    const res = await fetch(`/api/users/${id}`);
    return await res.json();
  }
}
</script>
```

---

## Troubleshooting

### Issue: State Not Updating

**Problem:** State changes don't trigger re-renders

**Solution:**
```sao
<!-- ✅ Use the setter function -->
<button @click(setCount($count + 1))>Increment</button>

<!-- ❌ Don't modify state directly -->
<button @click(count++)>Wrong</button>
```

### Issue: Variable Undefined

**Problem:** Variables show as undefined in template

**Solution:**
```sao
<!-- ✅ Declare variables before using -->
@useState($username, '')
<p>{{ $username }}</p>

<!-- ❌ Use without declaring -->
<p>{{ $username }}</p>
```

### Issue: Methods Not Available

**Problem:** Methods defined in script aren't callable

**Solution:**
```sao
<!-- ✅ Methods must be in export default -->
<button @click(handleClick())>Click</button>

<script setup>
export default {
  handleClick() { console.log('clicked'); }
}
</script>
```

---

## Cheat Sheet

```sao
<!-- DECLARATIONS -->
@useState($var, init)           <!-- Reactive state -->
@let($var = expr)              <!-- Local variable -->
@const($VAR = val)             <!-- Constant -->
@vars($v1, $v2)                <!-- Non-reactive vars -->

<!-- TEMPLATE BINDING -->
{{ $variable }}                 <!-- Interpolation -->
@bind($variable)               <!-- Two-way binding -->
@val($variable)                <!-- Value binding -->
@checked($condition)           <!-- Checkbox/radio -->
@selected($condition)          <!-- Select option -->

<!-- EVENTS -->
@click(handler())              <!-- Click event -->
@input(handler($e))            <!-- Input event -->
@change(handler())             <!-- Change event -->
@submit(handler())             <!-- Form submit -->

<!-- CONDITIONS -->
@if($cond) ... @endif          <!-- If statement -->
@foreach($items as $i) ... @endforeach  <!-- Loop -->

<!-- ATTRIBUTES -->
@attr(['key' => $val])         <!-- Dynamic attributes -->
@class(['base', 'active' => $cond])  <!-- Classes -->
@style(['color' => $color])    <!-- Inline styles -->

<!-- SCRIPTS -->
<script setup lang="ts">
export default {
  methodName() { },
  async asyncMethod() { }
}
</script>

<!-- STYLES -->
<style scoped>
.selector { }
</style>
```

---

## Tài Liệu Tham Khảo

- [DIRECTIVES-REFERENCE.md](./DIRECTIVES-REFERENCE.md) - Chi tiết các directives
- [BLADE_OUTPUT_GUIDE.md](./BLADE_OUTPUT_GUIDE.md) - Đầu ra Blade
- [V2_COMPILE_GUIDE.md](./V2_COMPILE_GUIDE.md) - Hướng dẫn compile
