# 📝 Saola V2 Documentation Update Summary

## Ngày Cập Nhật: 3 tháng 2, 2026

---

## 📚 Documents Đã Cập Nhật/Tạo Mới

### 1. **V2_COMPILE_GUIDE.md** (16.9 KB)
   - **Nội dung chính:**
     - Cấu trúc file input `.sao` (4 thành phần)
     - Naming convention cho classes và constants
     - Example 1: File đơn giản (không có tags)
     - Example 2: File với thẻ bao `<blade>` và `<script setup>`
     - Detailed JavaScript output structure
     - State Management Pattern (`@useState` registration)
     - Config Setup Object documentation
   
   - **Thay đổi từ bản cũ:**
     - Cấu trúc rõ ràng hơn, dễ follow
     - Thêm chi tiết từng bước trong `__setup__()` method
     - Giải thích về state lifecycle
     - Bổ sung Config properties reference

### 2. **BLADE_OUTPUT_GUIDE.md** (6.8 KB)
   - **Nội dung chính:**
     - Output format cho Blade files
     - Directive mapping (.sao → Blade)
     - Conditional statements, loops, sections
     - State dalam Blade context
     - Data binding từ Laravel
     - Component composition
     - Layout inheritance
     - Security considerations
     - Special directives (@auth, @switch, @forelse)
   
   - **Mục đích:**
     - Tài liệu cho phần Blade compilation
     - Reference cho developer cần hiểu output
     - Best practices cho Laravel integration

---

## 🎯 Cấu Trúc Mới Của Compiler Output

### Input Format (`.sao` file)
```
.sao file = 4 thành phần:
1. Khai báo directives (@useState, @let, @const, @await, @vars)
2. Template (HTML/Blade - có thể bao trong <blade> hoặc <template>)
3. Script (JavaScript logic trong <script setup>)
4. Style (CSS/SCSS trong <style> tags)
```

### Output 1: JavaScript View Class
```javascript
// Constants: __VIEW_PATH__, __VIEW_NAMESPACE__, __VIEW_TYPE__
class WebPagesDemo2View extends View {
    __setup__(__data__, systemData) {
        // 1. Extract systemData
        // 2. Get app instances
        // 3. Define state helpers
        // 4. Initialize tracking objects
        // 5. Process @vars declarations
        // 6. Process @useState declarations
        // 7. Set user defined methods
        // 8. Configure view with setup()
    }
}

export function WebPagesDemo2(data, systemData) {
    // Factory function
}
```

### Output 2: Blade Template File
```php
{{-- resources/views/{context}/{folder}/{file}.blade.php --}}
@extends('layouts.app')

@section('content')
    {{-- Template content here --}}
@endsection
```

---

## 📋 Class Naming Convention

### Pattern:
`[Context][FolderPath][To][Filename]View`

### Example:
- **Input:** `resources/sao/app/web/views/pages/demo2.sao`
- **Output class:** `WebPagesDemo2View`
- **Output function:** `WebPagesDemo2(data, systemData)`
- **Blade file:** `resources/views/web/pages/demo2.blade.php`

---

## 🔄 State Management Pattern

### Khi `.sao` file khai báo state:
```one
@useState($isOpen, false)
```

### Compiler sinh ra:
```javascript
const set$isOpen = __STATE__.__.register('isOpen');
let isOpen = null;

const setIsOpen = (state) => {
    isOpen = state;
    set$isOpen(state);
};

const update$isOpen = (value) => {
    if (__STATE__.__.canUpdateStateByKey) {
        updateStateByKey('isOpen', value);
        isOpen = value;
    }
};
```

---

## 🛠️ Config Setup Object Properties

```javascript
setup({
    // Metadata
    superView, hasSuperView, viewType, sections,
    
    // Wrapper configuration
    wrapperConfig: { enable, tag, subscribe, attributes },
    
    // Data fetching
    hasAwaitData, hasFetchData, subscribe, fetch,
    
    // View properties
    data, viewId, path,
    
    // Variables & rendering
    usesVars, hasSections, hasSectionPreload, hasPrerender,
    renderLongSections, renderSections, prerenderSections,
    
    // Assets
    scripts, styles, resources,
    
    // Lifecycle callbacks
    commitConstructorData(),
    updateVariableData(data),
    updateVariableItemData(key, value),
    prerender(),
    render()
})
```

---

## 🔗 File Locations

```
/Users/doanln/Desktop/2026/Projects/saola/docs/
├── V2_COMPILE_GUIDE.md           ← JavaScript Output Guide
├── BLADE_OUTPUT_GUIDE.md         ← Blade Output Guide
├── requirement-vi.md             ← Compiler Requirements (Vietnamese)
├── DIRECTIVES-REFERENCE.md       ← Directive Documentation (if exists)
└── ...
```

---

## 📖 Documentation Coverage

### ✅ Covered:
- File `.sao` structure & components
- Class naming convention
- JavaScript output format with `__setup__()` method
- State management with `@useState`
- Config setup object properties
- Blade output format & directives
- Directive mapping (.sao → Blade)
- Data binding & component composition
- Security considerations

### ⚠️ Still Need from Old Codebase:
- Detailed directive list & syntax
- Component nesting patterns
- Asset handling (images, fonts)
- SCSS/CSS processing details
- Complex state patterns
- Performance optimization tips
- Error handling strategies
- Example projects

---

## 🎓 Next Steps

1. **Reference Old Codebase** (`/sao/` và `/onelaravel/`)
   - Extract directive implementations
   - Review component patterns
   - Study asset handling

2. **Expand DIRECTIVES-REFERENCE.md**
   - List all @directives with examples
   - Show expected output for each

3. **Add Examples Section**
   - Real-world `.sao` files
   - Corresponding JavaScript output
   - Corresponding Blade output

4. **Create Integration Guide**
   - How to use compiled views in Laravel
   - How to use compiled JS in frontend
   - Webpack bundling configuration

5. **Performance Guide**
   - Lazy loading patterns
   - Code splitting strategies
   - Caching mechanisms

---

## 📊 File Sizes

| File | Size | Lines |
|------|------|-------|
| V2_COMPILE_GUIDE.md | 16.9 KB | ~500+ |
| BLADE_OUTPUT_GUIDE.md | 6.8 KB | ~300+ |
| requirement-vi.md | ~100 KB | 1009 |

---

## ✨ Key Improvements

1. **Clarity**: Documented examples now show exact output format
2. **Detail**: Each section has step-by-step explanation
3. **Structure**: Consistent naming convention across all examples
4. **Context**: Both JavaScript and Blade outputs documented
5. **State**: Clear pattern for state initialization and updates
6. **Config**: All setup object properties documented

---

## 🚀 Ready For

- AI Compiler Implementation
- Developer Training
- Build System Integration
- Framework Documentation

---

**Version**: 2.0  
**Status**: Ready for Implementation  
**Last Updated**: 2026-02-03
