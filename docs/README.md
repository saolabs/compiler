# Saola Compiler Documentation

> Tài liệu kỹ thuật cho Saola Compiler - Build Tools & .sao File Processing

## 📚 Tổng Quan

Thư mục này chứa tất cả tài liệu kỹ thuật liên quan đến **Saola Compiler** - công cụ build để compile file `.sao` thành Blade templates và JavaScript.

## 📋 Danh Sách Tài Liệu

### 📄 .sao File Format
- **[SAO_FILE_FORMAT.md](SAO_FILE_FORMAT.md)** - Định dạng file .sao chi tiết
- **[V2_COMPILE_GUIDE.md](V2_COMPILE_GUIDE.md)** - Hướng dẫn compile V2
- **[V2_OUTPUT_FORMAT.md](V2_OUTPUT_FORMAT.md)** - Định dạng output V2

### 🏗️ Compiler Architecture
- **[ARCHITECTURE_PLAN.md](ARCHITECTURE_PLAN.md)** - Kế hoạch kiến trúc
- **[IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)** - Kế hoạch implementation
- **[UPDATE_SUMMARY.md](UPDATE_SUMMARY.md)** - Tóm tắt updates

### 🎨 Blade Output
- **[BLADE_OUTPUT_GUIDE.md](BLADE_OUTPUT_GUIDE.md)** - Hướng dẫn output Blade

### 📚 References
- **[CORE_DIRECTIVES_WIKI.md](CORE_DIRECTIVES_WIKI.md)** - Wiki directives core
- **[DIRECTIVES-REFERENCE.md](DIRECTIVES-REFERENCE.md)** - Tham khảo directives
- **[CHANGELOG.md](CHANGELOG.md)** - Lịch sử thay đổi

## 🚀 Bắt Đầu

1. **Đọc SAO_FILE_FORMAT.md** để hiểu cú pháp .sao files
2. **Xem V2_COMPILE_GUIDE.md** để biết cách compile
3. **Đọc ARCHITECTURE_PLAN.md** để hiểu thiết kế tổng thể

## 📖 CLI Usage

```bash
# Compile single file
npx saola-compile component.sao

# Development server
npx saola-dev

# Production build
npx saola-build
```

## 🔧 Configuration

```json
{
  "input": "resources/views/components",
  "output": "resources/views/compiled",
  "jsOutput": "resources/js/components",
  "watch": ["resources/views/**/*.sao"],
  "options": {
    "minify": true,
    "sourceMap": true
  }
}
```

## 📝 .sao File Example

```one
@props(['title' => 'Default'])
@useState($count, 0)

<div class="component">
  <h1>{{ $title }}</h1>
  <button @click($setCount($count + 1))>
    Count: {{ $count }}
  </button>
</div>

<script setup>
export default {
  methods: {
    reset() { setCount(0); }
  }
}
</script>
```

## 🔗 Liên Kết

- [Saola Compiler README](../README.md)
- [Saola Client](../../client/README.md)
- [Examples](../../examples/)

---

*Tài liệu này được tách từ Saola V2 để phục vụ Saola Compiler ecosystem.*