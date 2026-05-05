# Saola Component AST - Tài Liệu Hướng Dẫn Chi Tiết

Tài liệu này cung cấp cái nhìn chuyên sâu về chức năng **Component AST** trong trình biên dịch Saola Framework. Chức năng này cho phép lập trình viên nhúng và sử dụng các component bằng cú pháp thẻ HTML tiêu chuẩn (VD: `<demo />`, `<tasks>...</tasks>`) thay vì phải gọi thông qua các directive `@include` phức tạp. Đặc biệt, chức năng này đảm bảo tính tương thích và đồng bộ ID 100% giữa quá trình Render tĩnh (Blade SSR) và Hydration (JS CSR).

---

## 1. Cú Pháp Khai Báo (Importing Components)

Để sử dụng một Component dưới dạng thẻ HTML, bạn cần đăng ký nó ở đầu tệp `.sao` thông qua directive `@import`.

### 1.1 Import Đơn Giản
```html
{{-- Tên thẻ mặc định sẽ là từ cuối cùng của đường dẫn (ở đây là <tasks>) --}}
@import(__template__ + 'sessions.tasks')
```

### 1.2 Import Kèm Tên Tùy Chỉnh (Alias)
```html
{{-- Sử dụng từ khóa 'as' để đổi tên thẻ thành <projects> --}}
@import(__template__ + 'sessions.projects' as projects)
```

### 1.3 Import Hàng Loạt (Object Syntax)
```html
@import({
    counter: 'sessions.tasks.count',
    demo: __template__ + 'demo.fetch',
    alert: __blade_custom_path__
})
```

---

## 2. Cách Sử Dụng Component Trong Template

Sau khi khai báo, bạn có thể gọi Component bằng tên thẻ tương ứng. Saola hỗ trợ ba hình thức truyền dữ liệu cốt lõi:

### 2.1 Truyền Thuộc Tính Tĩnh (Static Props)
Ghi trực tiếp thuộc tính mà không có tiền tố. Dữ liệu sẽ được truyền vào dưới dạng chuỗi (String).
```html
<alert type="success" message="This is a custom alert component!" />
```

### 2.2 Truyền Thuộc Tính Động / State Binding (Dynamic Props)
Sử dụng tiền tố `:` hoặc `x-bind:` để biểu thị dữ liệu động hoặc biến state.
Trình biên dịch sẽ tự động chuyển đổi logic JS thành cú pháp PHP (`$users`) khi xuất ra file Blade.
```html
<demo :users="users" :config="{ theme: 'dark', max: 10 }" />
```

### 2.3 Gán Sự Kiện (Event Handlers)
Sử dụng tiền tố `@` hoặc `x-on:` để gắn các hàm sự kiện. Các thuộc tính này **chỉ thực thi trên Client-side** (JS), do đó biểu thức bên trong sẽ không bị biên dịch sang cú pháp biến PHP.
```html
<button-component @click="doAction('login')" />
```

### 2.4 Component Lồng Nhau (Slots / Children)
Bạn có thể lồng các Component với nhau. Nội dung bên trong Component cha sẽ được biên dịch và truyền xuống Component con dưới dạng biến `$__ONE_CHILDREN_CONTENT__`.
```html
<projects :projects="projects">
    <div class="header">
        <h2>My Projects</h2>
    </div>
    
    {{-- Component lồng Component --}}
    <tasks :owners="['Alice', 'Bob']">
        <demo :users="users" />
    </tasks>
</projects>
```

---

## 3. Quá Trình Biên Dịch và Đầu Ra (Compiler Output)

Saola Compiler tạo ra hai phiên bản riêng biệt từ file `.sao`:
1. **Blade Output (`.blade.php`)**: Phục vụ cho lần render đầu tiên (Server-Side Rendering).
2. **JS Output (`.ts` / `.js`)**: Phục vụ cho tính năng Re-render và Reactivity (Client-Side Rendering / Hydration).

Điều sống còn của hệ thống AST là đảm bảo 2 đầu ra này tạo ra cấu trúc DOM và cây ID **giống hệt nhau** để Hydration map chính xác.

### 3.1 Server-Side Rendering (Blade Output)

**Mã nguồn ban đầu:**
```html
<tasks :owners="['Alice', 'Bob']">
    <demo :users="users" />
</tasks>
```

**Mã Blade sinh ra:**
```php
{{-- Khởi tạo Marker và ID cho component CHA --}}
@startMarker('component', 'component-3')
    
    {{-- Mở section lưu nội dung con --}}
    @exec($__env->startSection($__ONE_COMPONENT_REGISTRY__['tasks'].'_1'))
        
        {{-- Khởi tạo Marker và ID cho component CON (kế thừa tiền tố của cha) --}}
        @startMarker('component', 'component-3-component-1')
            {{-- Biến users tự động được chuyển thành $users --}}
            @include($__template__ . 'demo.fetch', ['users' => $users])
        @endMarker('component', 'component-3-component-1')
        
    @exec($__env->stopSection())
    
    {{-- Nhúng Component Cha kèm nội dung con đã xuất ra --}}
    @exec($__tasks__1_content = $__env->yieldContent($__ONE_COMPONENT_REGISTRY__['tasks'].'_1'))
    @include($__template__ . 'sessions.tasks', [
        'owners' => ['Alice', 'Bob'], 
        '__ONE_CHILDREN_CONTENT__' => $__tasks__1_content
    ])

@endMarker('component', 'component-3')
```
*Lưu ý:* Cây Hydration ID sinh ra phân cấp rất rõ ràng (`component-3` và `component-3-component-1`), đảm bảo các phần tử HTML (`div`, `p`, v.v.) bên trong cũng sẽ kế thừa tiền tố này.

### 3.2 Client-Side Hydration (JS Output)

Trong ViewManager, hàm `render()` của JS sẽ chịu trách nhiệm sinh ra hoặc hydrate (đánh thức) lại các DOM Node. Trình biên dịch sẽ bọc các Component lồng nhau thông qua lệnh `this.include(...)` thay vì chuỗi HTML thông thường.

**Mã JS sinh ra (rút gọn):**
```javascript
__execArr.push(
    // Component CHA
    this.include(
        "component-3",                                // ID cấp 1
        __template__ + 'sessions.tasks',              // View Path
        parentElement, 
        [], 
        (parentElement) => ({
            "owners": ["Alice", "Bob"],               // Dynamic Props
            __ONE_CHILDREN_CONTENT__: (parentElement) => [ // Slots
                // Component CON
                this.include(
                    "component-3-component-1",        // ID cấp 2 (Lồng nhau)
                    __template__ + 'demo.fetch', 
                    parentElement, 
                    [], 
                    (parentElement) => ({"users": users})
                )
            ]
        })
    )
);
```

---

## 4. Đặc Tính Nổi Bật (Key Features & Bug Fixes)

- **Smart Prefix Tracking:** Trình phân tích từ vựng (Lexer) tự động phát hiện `x-bind` hay `:` để chuyển đổi các biến JavaScript (như `projects`, `person.name`) thành biến PHP hợp lệ (`$projects`, `$person->name`) mà không chạm vào các biểu thức sự kiện thuần Client (như `@click`).
- **Deep Hierarchical Hydration ID:** Việc đánh số thứ tự Component (`component-1`, `component-2-component-1`, v.v.) không phải là bộ đếm phẳng (flat counter). Khi một Component có chứa Component con, bộ đếm ID sẽ rẽ nhánh (push/pop scope) để đảm bảo thẻ `<div id="component-2-div-1">` được render ra bởi Blade sẽ được Engine JS tìm đúng và đánh thức, không gây vỡ giao diện.
- **Blade Template Registry:** Mọi component custom import bằng `@import` đều được ánh xạ ngầm thông qua thư viện danh bạ của Blade `$__ONE_COMPONENT_REGISTRY__`, nhằm tránh trùng lặp Section Namespace và giảm thiểu xung đột dữ liệu toàn cầu.
