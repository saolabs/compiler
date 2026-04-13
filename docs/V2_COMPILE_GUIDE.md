# Saola V2 Compiler Guide

## Cấu Trúc File Input (.sao)

File `.sao` thường có 4 thành phần chính:

### 1. **Phần Khai Báo** (Declarations)
- Các directive như `@vars`, `@let`, `@const`, `@useState`, `@await`
- Khai báo các biến/state sẽ được dùng trong view
- Khi compile sang JS, những biến này sẽ được khởi tạo trong `__setup__()` method

### 2. **Phần Template** (HTML/Blade content)
- Nội dung HTML/Blade để render
- Có thể được bao bọc trong cặp thẻ:
  - `<blade>...</blade>` (khuyến khích dùng cho Laravel Blade)
  - `<template>...</template>` (generic template)
  - Hoặc không bao nếu code đơn giản (ngay sau phần khai báo)
- Chỉ lấy nội dung bên trong các thẻ bao này (nếu có)

### 3. **Phần Script** (JavaScript/TypeScript)
- Khai báo các methods, lifecycle hooks, logic
- Dùng thẻ `<script setup>...</script>` 
- Phần object sau `export default { ... }` sẽ được đưa vào `setUserDefined()`
- **Lưu ý**: Bản mới không cần bao trong `@register ... @endregister`

### 4. **Phần Style** (CSS/SCSS)
- Khai báo styles cho component
- Hỗ trợ thẻ `<style>...</style>` hoặc `<link href="..."/>`
- Không cần khai báo directive trước

---

## Naming Convention

### Class Naming
Format: `[Context][FolderPath][To][Filename]View`

**Ví dụ:**
- Input: `resources/sao/app/web/views/pages/demo2.sao`
- Context: `web`
- Folder path từ views: `pages`
- Filename: `demo2`
- **Output class name**: `WebPagesDemo2View`

### Constants Naming
```javascript
const __VIEW_PATH__ = 'web.pages.demo2';           // [context].[folder].[file]
const __VIEW_NAMESPACE__ = 'web.pages.';           // [context].[folder]
const __VIEW_TYPE__ = 'view';                       // view type (view, component, layout, etc)
```

---

## Example 1: File Đơn Giản (No Tags)

### Input (.sao file)
```one
@useState($isOpen, false)
<div class="demo2-component" @click($setIsOpen(! $isOpen))>
    Status: {{ $isOpen ? 'Open' : 'Closed' }}
</div>
```

**Đặc điểm:**
- Chỉ có 2 thành phần: khai báo và template
- Không có thẻ `<blade>` hoặc `<template>` bao
- Không có `<script>` hoặc `<style>`

### Output (JavaScript)

```javascript
import { View } from 'onelaraveljs';
import { app } from 'onelaraveljs';

// Constants declarations
const __VIEW_PATH__ = 'web.pages.demo2';
const __VIEW_NAMESPACE__ = 'web.pages.';
const __VIEW_TYPE__ = 'view';

// Class definition
class WebPagesDemo2View extends View {
    constructor(App, systemData) {
        super(__VIEW_PATH__, __VIEW_TYPE__);
        this.__ctrl__.setApp(App);
    }

    __setup__(__data__, systemData) {
        // 1. Extract system data
        const { __base__, __layout__, __page__, __component__, __template__, 
                __context__, __partial__, __system__, __env = {}, __helper = {} } = systemData;
        
        // 2. Get app instances
        const App = app.make("App");
        const Helper = app.make("Helper");
        const __VIEW_ID__ = this.__ctrl__.__SSR_VIEW_ID__ || App.Helper.generateViewId();
        const __STATE__ = this.__ctrl__.states;
        
        // 3. Define state helper functions
        const useState = (value) => __STATE__.__useState(value);
        const updateRealState = (state) => __STATE__.__.updateRealState(state);
        const lockUpdateRealState = () => __STATE__.__.lockUpdateRealState();
        const updateStateByKey = (key, state) => __STATE__.__.updateStateByKey(key, state);

        // 4. Initialize tracking objects
        const __UPDATE_DATA_TRAIT__ = {};
        const __VARIABLE_LIST__ = [];

        // 5. Process @vars declarations (if any)
        // ... variable declarations here ...

        // 6. Process @useState declarations
        const set$isOpen = __STATE__.__.register('isOpen');
        let isOpen = null;
        
        const setIsOpen = (state) => {
            isOpen = state;
            set$isOpen(state);
        };
        
        __STATE__.__.setters.setIsOpen = setIsOpen;
        __STATE__.__.setters.isOpen = setIsOpen;
        
        const update$isOpen = (value) => {
            if (__STATE__.__.canUpdateStateByKey) {
                updateStateByKey('isOpen', value);
                isOpen = value;
            }
        };

        // 7. Set user defined methods (from <script setup>)
        this.__ctrl__.setUserDefined({});

        // 8. Configure view and setup render function
        this.__ctrl__.setup({
            superView: null,
            hasSuperView: false,
            viewType: 'view',
            sections: {},
            wrapperConfig: { enable: false, tag: null, subscribe: true, attributes: {} },
            hasAwaitData: false,
            hasFetchData: false,
            subscribe: true,
            fetch: null,
            data: __data__,
            viewId: __VIEW_ID__,
            path: __VIEW_PATH__,
            usesVars: false,
            hasSections: false,
            hasSectionPreload: false,
            hasPrerender: false,
            renderLongSections: [],
            renderSections: [],
            prerenderSections: [],
            scripts: [],
            styles: [],
            resources: [],
            
            commitConstructorData: function () {
                update$isOpen(false);
                lockUpdateRealState();
            },
            
            updateVariableData: function (data) {
                for (const key in data) {
                    if (data.hasOwnProperty(key)) {
                        if (typeof this.config.updateVariableItemData === 'function') {
                            this.config.updateVariableItemData.call(this, key, data[key]);
                        }
                    }
                }
                update$isOpen(false);
                lockUpdateRealState();
            },
            
            updateVariableItemData: function (key, value) {
                this.data[key] = value;
                if (typeof __UPDATE_DATA_TRAIT__[key] === "function") {
                    __UPDATE_DATA_TRAIT__[key](value);
                }
            },
            
            prerender: function () {
                return null;
            },
            
            render: function () {
                let __outputRenderedContent__ = '';
                try {
                    __outputRenderedContent__ = `
<div class="demo2-component" ${this.__addEventConfig("click", [(event) => setIsOpen(!isOpen)])}>
Status: ${this.__reactive(`rc-${App.Helper.escString(__VIEW_ID__)}-67`, ['isOpen'], (__rc__) => isOpen ? 'Open' : 'Closed', {type: 'output', escapeHTML: true})}
</div>`;
                } catch (e) {
                    __outputRenderedContent__ = this.__showError(e.message);
                    console.warn(e);
                }
                return __outputRenderedContent__;
            }
        });
    }
}

// Export factory function (same name as class without 'View' suffix)
export function WebPagesDemo2(data, systemData) {
    const App = app.make("App");
    const view = new WebPagesDemo2View(App, systemData);
    view.__setup__(data, systemData);
    return view;
}
```

### Chi Tiết Output

**Cấu trúc chung:**
1. **Import statements** - Import View class và app instance
2. **Constants** - __VIEW_PATH__, __VIEW_NAMESPACE__, __VIEW_TYPE__
3. **Class definition** - Extends View base class
4. **__setup__() method** - Thay thế cho function wrapper (phương pháp cũ)
   - Extract system data
   - Initialize state helpers
   - Process @useState declarations
   - Setup user defined methods
   - Configure view with setup() callback
5. **Export function** - Factory function tạo view instance

---

## Example 2: File Với Thẻ Bao & Script

### Input (.sao file)
```one
@useState($isOpen, false)
<blade>
<div class="demo2-component" @click($setIsOpen(! $isOpen))>
    Status: {{ $isOpen ? 'Open' : 'Closed' }}
</div>
</blade>

<script setup>
    export default {
        init(){},
        mounted(){}
    }
</script>
```

**Đặc điểm:**
- Template được bao trong `<blade>...</blade>`
- Có `<script setup>...</script>` với user-defined methods
- Chỉ lấy nội dung trong `<blade>` tags cho template phần

### Output (JavaScript)

```javascript
import { View } from 'saola';
import { app } from 'saola';

const __VIEW_PATH__ = 'web.pages.demo2';
const __VIEW_NAMESPACE__ = 'web.pages.';
const __VIEW_TYPE__ = 'view';

class WebPagesDemo2View extends View {
    constructor(App, systemData) {
        super(__VIEW_PATH__, __VIEW_TYPE__);
        this.__ctrl__.setApp(App);
    }

    __setup__(__data__, systemData) {
        const { __base__, __layout__, __page__, __component__, __template__, 
                __context__, __partial__, __system__, __env = {}, __helper = {} } = systemData;
        const App = app.make("App");
        const Helper = app.make("Helper");
        const __VIEW_ID__ = this.__ctrl__.__SSR_VIEW_ID__ || App.Helper.generateViewId();
        const __STATE__ = this.__ctrl__.states;
        
        const useState = (value) => __STATE__.__useState(value);
        const updateRealState = (state) => __STATE__.__.updateRealState(state);
        const lockUpdateRealState = () => __STATE__.__.lockUpdateRealState();
        const updateStateByKey = (key, state) => __STATE__.__.updateStateByKey(key, state);

        const __UPDATE_DATA_TRAIT__ = {};
        const __VARIABLE_LIST__ = [];

        // State registration
        const set$isOpen = __STATE__.__.register('isOpen');
        let isOpen = null;
        
        const setIsOpen = (state) => {
            isOpen = state;
            set$isOpen(state);
        };
        
        __STATE__.__.setters.setIsOpen = setIsOpen;
        __STATE__.__.setters.isOpen = setIsOpen;
        
        const update$isOpen = (value) => {
            if (__STATE__.__.canUpdateStateByKey) {
                updateStateByKey('isOpen', value);
                isOpen = value;
            }
        };

        // Set user defined methods from <script setup>
        this.__ctrl__.setUserDefined({
            init(){},
            mounted(){}
        });

        // Setup view configuration
        this.__ctrl__.setup({
            superView: null,
            hasSuperView: false,
            viewType: 'view',
            sections: {},
            wrapperConfig: { enable: false, tag: null, subscribe: true, attributes: {} },
            hasAwaitData: false,
            hasFetchData: false,
            subscribe: true,
            fetch: null,
            data: __data__,
            viewId: __VIEW_ID__,
            path: __VIEW_PATH__,
            usesVars: false,
            hasSections: false,
            hasSectionPreload: false,
            hasPrerender: false,
            renderLongSections: [],
            renderSections: [],
            prerenderSections: [],
            scripts: [],
            styles: [],
            resources: [],
            
            commitConstructorData: function () {
                update$isOpen(false);
                lockUpdateRealState();
            },
            
            updateVariableData: function (data) {
                for (const key in data) {
                    if (data.hasOwnProperty(key)) {
                        if (typeof this.config.updateVariableItemData === 'function') {
                            this.config.updateVariableItemData.call(this, key, data[key]);
                        }
                    }
                }
                update$isOpen(false);
                lockUpdateRealState();
            },
            
            updateVariableItemData: function (key, value) {
                this.data[key] = value;
                if (typeof __UPDATE_DATA_TRAIT__[key] === "function") {
                    __UPDATE_DATA_TRAIT__[key](value);
                }
            },
            
            prerender: function () {
                return null;
            },
            
            render: function () {
                let __outputRenderedContent__ = '';
                try {
                    __outputRenderedContent__ = `
<div class="demo2-component" ${this.__addEventConfig("click", [(event) => setIsOpen(!isOpen)])}>
Status: ${this.__reactive(`rc-${App.Helper.escString(__VIEW_ID__)}-67`, ['isOpen'], (__rc__) => isOpen ? 'Open' : 'Closed', {type: 'output', escapeHTML: true})}
</div>`;
                } catch (e) {
                    __outputRenderedContent__ = this.__showError(e.message);
                    console.warn(e);
                }
                return __outputRenderedContent__;
            }
        });
    }
}

// Export factory function
export function WebPagesDemo2(data, systemData) {
    const App = app.make("App");
    const view = new WebPagesDemo2View(App, systemData);
    view.__setup__(data, systemData);
    return view;
}
```

**Thay đổi so với Example 1:**
- Template được lấy từ `<blade>` tag content
- `setUserDefined()` nhận object từ `export default { ... }` trong `<script setup>`
- Lúc này `init()` và `mounted()` sẽ được gọi bởi View framework

---

## State Management Pattern

### @useState Registration

Khi file .sao khai báo:
```one
@useState($isOpen, false)
```

Compiler sẽ sinh ra:
```javascript
// Register state key
const set$isOpen = __STATE__.__.register('isOpen');

// Initialize state variable
let isOpen = null;

// Setter function
const setIsOpen = (state) => {
    isOpen = state;
    set$isOpen(state);
};

// Register setters with framework
__STATE__.__.setters.setIsOpen = setIsOpen;
__STATE__.__.setters.isOpen = setIsOpen;

// Update function (for SSR data hydration)
const update$isOpen = (value) => {
    if (__STATE__.__.canUpdateStateByKey) {
        updateStateByKey('isOpen', value);
        isOpen = value;
    }
};
```

### State Lifecycle

1. **Initialization** (`commitConstructorData`) - Lần đầu tiên khởi tạo state từ giá trị mặc định
2. **Rendering** - Khi view render, state values được sử dụng
3. **User Interaction** - User tương tác, setter được gọi để update state
4. **Re-render** - Framework detect state change và re-render view

---

## Config Setup Object

### Main Properties

```javascript
this.__ctrl__.setup({
    // View metadata
    superView: null,                // Parent view nếu có
    hasSuperView: false,             // Is child view?
    viewType: 'view',                // 'view', 'component', 'layout'
    
    // Sections (for template inheritance)
    sections: {},                    // Blade sections
    
    // Wrapper configuration
    wrapperConfig: { 
        enable: false, 
        tag: null, 
        subscribe: true, 
        attributes: {} 
    },
    
    // Data fetching
    hasAwaitData: false,             // Has @await directive?
    hasFetchData: false,             // Has fetch in script?
    subscribe: true,                 // Subscribe to changes?
    fetch: null,                     // Fetch function
    
    // View properties
    data: __data__,                  // Initial data passed to view
    viewId: __VIEW_ID__,             // Unique view ID
    path: __VIEW_PATH__,             // View path (e.g., 'web.pages.demo2')
    
    // Variables & rendering
    usesVars: false,                 // Has @vars?
    hasSections: false,              // Has @section/@block?
    hasSectionPreload: false,        // Preload sections?
    hasPrerender: false,             // Has @prerender?
    
    renderLongSections: [],          // Sections to render on load
    renderSections: [],              // Main sections
    prerenderSections: [],           // Pre-rendered sections
    
    // Assets
    scripts: [],                     // Script URLs/tags
    styles: [],                      // Style URLs/tags
    resources: [],                   // Other resources
    
    // Lifecycle callbacks
    commitConstructorData: function () { ... },      // Called after construction
    updateVariableData: function (data) { ... },     // Called when data updates
    updateVariableItemData: function (key, value) {}, // Called for each data item
    prerender: function () { ... },                  // Pre-render content
    render: function () { ... }                      // Main render function
});
```

---

## Tham Khảo Từ Bản Cũ

Phần này cần tham khảo từ bản cũ để bổ sung:
- Các directive chi tiết (@vars, @let, @const, @await, @for, @if, vv)
- Component nesting & composition
- Asset handling (images, fonts, etc)
- CSS/SCSS processing
- Complex state management patterns
- Performance optimization tips
- Error handling strategies

---

**Ghi chú**: File này cần được cập nhật thêm từ thư viện cũ (`/sao/`) để bao quát đầy đủ tất cả các tính năng.
