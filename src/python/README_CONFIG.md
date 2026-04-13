# Compiler Configuration

Hệ thống config cho Python Blade Compiler cho phép dễ dàng chỉnh sửa input path và output path.

## Cấu trúc Config

### File JSON: `compiler.config.json`

```json
{
  "paths": {
    "views_input": "resources/views",
    "js_input": "resources/js/app",
    "public_static": "public/static",
    "app_output": "public/static/app",
    "scopes_output": "public/static/app/scopes"
  },
  "files": {
    "view_templates": "view.templates.js",
    "wrapper": "wraper.js",
    "main": "main.js"
  },
  "patterns": {
    "blade": "**/*.blade.php",
    "js": "**/*.js"
  },
  "settings": {
    "default_scope": "web",
    "auto_create_dirs": true,
    "verbose": false
  }
}
```

## Sử dụng Config

### 1. Chỉnh sửa đường dẫn

Để thay đổi input/output paths, chỉ cần sửa file `compiler.config.json`:

```json
{
  "paths": {
    "views_input": "custom/views/path",
    "js_input": "custom/js/path",
    "app_output": "custom/output/path"
  }
}
```

### 2. Sử dụng trong Python

```python
from compiler.config import config

# In config hiện tại
config.print_config()

# Lấy đường dẫn
views_path = config.get_views_path('web')
js_path = config.get_js_input_path()
output_path = config.get_view_templates_output_path()

# Reload config từ file
config.reload_config()

# Cập nhật config động
config.update_paths(views_input_path="/new/path")
```

### 3. Test Config

```bash
python3 test_config.py
```

## Các đường dẫn chính

- **Views Input**: `resources/views` - Nơi chứa các file Blade template
- **JS Input**: `resources/js/app` - Nơi chứa các file JavaScript modules
- **Build Output**: `resources/js/build` - Nơi build tạm thời (không deploy)
- **Build Scopes**: `resources/js/build/scopes` - Nơi build scope files tạm thời
- **App Output**: `public/static/app` - Nơi xuất các file JavaScript đã compile (deploy)
- **Scopes Output**: `public/static/app/scopes` - Nơi xuất các file scope (deploy)

## Workflow Build

### 1. Build Process
1. **Compile Blade templates** → `resources/js/build/scopes/`
2. **Build view.templates.js** → `resources/js/build/view.templates.js`
3. **Copy view.templates.js** → `resources/js/app/view.templates.js`
4. **Build main.js** → `resources/js/build/main.js`
5. **Copy only main.js** → `public/static/app/main.js`

### 2. Lợi ích
- ✅ **Tách biệt build và deploy**: Build trong `resources`, deploy vào `public`
- ✅ **Dễ debug**: Có thể kiểm tra files trong `resources/build` trước khi deploy
- ✅ **Rollback dễ dàng**: Có thể giữ lại version cũ trong `public`
- ✅ **Preserve originals**: Giữ nguyên tất cả file gốc tại `resources/build/`
- ✅ **CI/CD friendly**: Có thể build riêng, deploy riêng

## Tính năng

- ✅ **Auto Create Dirs**: Tự động tạo thư mục nếu chưa tồn tại
- ✅ **Verbose Mode**: Hiển thị thông tin chi tiết khi compile
- ✅ **Backward Compatibility**: Tương thích với code cũ
- ✅ **Dynamic Reload**: Có thể reload config mà không cần restart
- ✅ **Build-Deploy Separation**: Tách biệt quá trình build và deploy

## Ví dụ sử dụng

### Thay đổi build directories

```json
{
  "build_directories": [
    "web",
    "admin",
    "layouts",
    "partials",
    "custom"
  ]
}
```

**Lưu ý:** Chỉ cần nhập tên thư mục, không cần path đầy đủ. Path sẽ được tính từ `resources/views`.

### Thay đổi output path

```json
{
  "paths": {
    "app_output": "dist/js",
    "scopes_output": "dist/js/scopes"
  }
}
```

### Thay đổi input path

```json
{
  "paths": {
    "views_input": "src/templates",
    "js_input": "src/assets/js"
  }
}
```

### Bật verbose mode

```json
{
  "settings": {
    "verbose": true
  }
}
```

## Cách sử dụng mới

### Chạy build đơn giản

```bash
# Không cần tham số
python3 build.py
```

### Thêm thư mục mới vào build

1. Thêm vào `compiler.config.json`:
```json
{
  "build_directories": [
    "web",
    "admin",
    "layouts",
    "partials",
    "custom"
  ]
}
```

2. Chạy build:
```bash
python3 build.py
```

### View naming convention

Views sẽ được đặt tên theo cấu trúc thư mục từ `resources/views`:

- `resources/views/web/user-detail.blade.php` → `web.user-detail`
- `resources/views/admin/dashboard.blade.php` → `admin.dashboard`
- `resources/views/layouts/base.blade.php` → `layouts.base`
- `resources/views/partials/footer.blade.php` → `partials.footer`
