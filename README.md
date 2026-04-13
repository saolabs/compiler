# Saola Compiler

Saola Compiler là một công cụ biên dịch chuyên biệt chuyển đổi các file template `.sao` thành hai định dạng output:
- **Blade files** (.blade.php) cho Laravel Server-Side Rendering (SSR)
- **JavaScript View files** (.js) cho client-side rendering

## Cấu Trúc Compiler

```
compiler/
├── index.js           # Main compiler - Node.js wrapper
├── cli.js             # CLI entry point  
├── config-manager.js   # Manages sao.config.json
├── python/            # Python compiler (13k+ lines from sao)
│   ├── main_compiler.py
│   ├── cli.py
│   ├── function_generators.py
│   ├── section_handlers.py
│   ├── php_js_converter.py
│   └── ... (26 modules)
├── package.json       # Package configuration
└── README.md          # This file
```

## Quy Trình Biên Dịch

### Node.js Wrapper (index.js)
1. Đọc `.sao` files
2. Tách các phần: declarations, blade template, script, style
3. **GHI NGAY Blade file** (declarations + template)
4. **Song song:** Gọi Python compiler để generate JavaScript
5. Copy app files vào temp directory

### Python Compiler
- Xử lý Blade syntax → JavaScript
- Generate prerender & render functions
- Xử lý directives (@await, @fetch, @section, etc)
- Convert PHP expressions sang JavaScript
- Tạo state management code

## Key Features

✅ **Declaration Order Preservation**: Giữ nguyên thứ tự khai báo
✅ **Auto-Create Folders**: Tự động tạo temp directories
✅ **Namespace Support**: Nhiều namespaces mỗi context
✅ **App Files Copy**: Tự động copy app files vào temp
✅ **PHP to JS Conversion**: Chuyển đổi PHP expressions
✅ **Prerender/Render Separation**: Riêng biệt SSR và dynamic rendering
✅ **Parallel Processing**: Blade và JS compile song song

## File Cấu Hình: sao.config.json

Đặt tại project root của Laravel:

```json
{
  "packages": {
    "saola": "1.0.0"
  },
  "paths": {
    "resources": "resources",
    "saolaView": "resources/sao",
    "bladeView": "resources/views",
    "temp": "resources/js/temp",
    "public": "public/static/one"
  },
  "output": {
    "default": "app/js",
    "contexts": {
      "admin": "admin/js",
      "web": "web/js",
      "mobile": "mobile/js"
    }
  },
  "contexts": {
    "web": {
      "name": "Web",
      "app": ["web/app"],
      "views": {
        "web": "web/views"
      },
      "blade": {
        "web": "web"
      },
      "temp": {
        "views": "web/views",
        "app": "web/app",
        "registry": "web/registry.js"
      }
    },
    "admin": {
      "name": "Admin Panel",
      "app": ["admin/app"],
      "views": {
        "admin": "admin/views"
      },
      "blade": {
        "admin": "admin"
      },
      "temp": {
        "views": "admin/views",
        "app": "admin/app",
        "registry": "admin/registry.js"
      }
    },
    "default": {
      "name": "Default - All Contexts",
      "app": ["admin/app", "web/app"],
      "views": {
        "web": "web/views",
        "admin": "admin/views"
      },
      "blade": {
        "web": "web",
        "admin": "admin"
      },
      "temp": {
        "views": "app/views",
        "app": "app/app",
        "registry": "app/registry.js"
      }
    }
  }
}
```

### Config Structure

#### paths (Base Paths)
Các base paths cho các mục khác nhau:
- `saolaView`: Base cho views và app sources
- `bladeView`: Base cho blade outputs  
- `temp`: Base cho JS temp outputs
- `public`: Base cho production outputs

#### contexts
Mỗi context có các relative paths (sẽ được prefix với base paths):
- `app[]`: Array các thư mục app sources (sẽ copy vào compiled.app)
- `views{}`: Object namespace → view path mapping
- `blade{}`: Object namespace → blade output path mapping
- `compiled.views`: Temp views output directory
- `compiled.app`: Temp app output directory
- `compiled.registry`: Registry file path

**Lưu ý:** `default` context không phải là context thật, dùng khi không chỉ định context.
## Cách Sử Dụng

### 1. Installation
```bash
npm install saola
# hoặc local install để test
cd /path/to/saola && npm pack
cd /path/to/your-project && npm install ../saola/saola-1.0.0.tgz
```

### 2. CLI Commands
```bash
# Compile specific context
npx sao-compile web
npx sao-compile admin

# Compile all contexts (skips 'default')
npx sao-compile all

# Watch mode (chưa implement)
npx sao-compile web --watch

# Show help
npx sao-compile --help
```

### 3. Quy Trình Compile

**Input Structure:**
```
resources/sao/
├── web/
│   ├── app/              ← App sources
│   │   ├── helpers/
│   │   └── services/
│   └── views/            ← .sao files
│       └── pages/
│           ├── home.sao
│           └── about.sao
└── admin/
    ├── app/
    └── views/
```

**Output Structure:**
```
resources/
├── views/                ← Blade outputs
│   ├── web/
│   │   └── pages/
│   │       ├── home.blade.php
│   │       └── about.blade.php
│   └── admin/
└── js/temp/              ← JS temp outputs
    ├── web/
    │   ├── app/          ← Copied from sources
    │   │   ├── helpers/
    │   │   └── services/
    │   └── views/        ← Compiled JS
    │       ├── WebPagesHome.js
    │       └── WebPagesAbout.js
    └── admin/
```

### 4. Path Resolution

Compiler tự động resolve paths:
```
views:  paths.saoView + context.views[namespace]
        → resources/sao + web/views
        → resources/sao/web/views

blade:  paths.bladeView + context.blade[namespace]
        → resources/views + web
        → resources/views/web

temp:   paths.compiled + context.compiled.views
        → resources/js/temp + web/views
        → resources/js/temp/web/views
```

# Watch mode (development)
npm run dev:web
npm run dev:admin

# Or run CLI directly
sao-build web
sao-build admin --watch
```

## Input: .sao File Format

File `.sao` có 4 phần:

```one
@useState($isOpen, false)
@const($API_URL = '/api/users')

<blade>
<div class="component" @click($setIsOpen(!$isOpen))>
    Status: {{ $isOpen ? 'Open' : 'Closed' }}
</div>
</blade>

<script setup>
    export default {
        toggle() {
            setIsOpen(!isOpen);
        }
    }
</script>

<style scoped>
    .component {
        padding: 10px;
    }
</style>
```

## Output Structures

### Blade Output (resources/views/web/admin/users/List.blade.php)
```blade
@useState($isOpen, false)
<div class="component" @click(...)>
    Status: {{ $isOpen ? 'Open' : 'Closed' }}
</div>
```

### JavaScript Output (resources/sao/js/temp/web/views/WebAdminUsersList.js)
```javascript
class WebAdminUsersListView extends View {
    constructor(App, systemData) {
        super(__VIEW_PATH__, __VIEW_TYPE__);
        this.__ctrl__.setApp(App);
    }

    __setup__(__data__, systemData) {
        // 8-step initialization process
        // ... state management
        // ... lifecycle callbacks
        // ... render function
    }
}

export function WebAdminUsersList(data, systemData) {
    const App = app.make("App");
    const view = new WebAdminUsersListView(App, systemData);
    view.__setup__(data, systemData);
    return view;
}
```

### Registry Output (resources/sao/js/temp/web/registry.js)
```javascript
export const ViewRegistry = {
    'web.admin.users.List': () => import('./views/WebAdminUsersList.js'),
    'web.pages.Home': () => import('./views/WebPagesHome.js'),
    // ... more views
};
```

## Directory Structure Sync

**CRITICAL**: Cấu trúc folder PHẢI đồng bộ giữa input và Blade output

```
Input:  resources/sao/app/web/views/admin/users/List.sao
                      └─context─┘ └─folder path──┘

Output: resources/views/web/admin/users/List.blade.php
             └─context─┘ └─folder path──┘
```

**Rules:**
- ✅ Filename PHẢI giống nhau (.sao → .blade.php)
- ✅ Folder path PHẢI đồng bộ hoàn toàn
- ✅ Context prefix từ config
- ❌ JavaScript không cần match folder structure (tên file JS đã include path)

## Quy Trình Build 4 Bước

1. **KHỞI TẠO**: Đọc & parse sao.config.json
2. **DUYỆT & PHÂN TÍCH**: Parse tất cả .sao files
3. **SINH RA OUTPUT**: Generate Blade, JavaScript, Registry
4. **BUNDLING**: Webpack bundle + minify cho production

## State Management Patterns

### @useState
```one
@useState($count, 0)
@useState($isOpen, false)
```

### State Setters
```javascript
// Auto-generated setters
setCount(count + 1);
setIsOpen(!isOpen);
```

### Lifecycle Callbacks
```javascript
commitConstructorData()    // Initialize states
updateVariableData(data)   // Update all variables
updateVariableItemData()   // Update individual items
prerender()               // Pre-render hook
render()                  // Main render function
```

## Directives Supported

### Event Binding
- `@click(handler)`
- `@input(handler)`
- `@change(handler)`
- `@submit(handler)`
- `@keyup(handler)`, `@keydown(handler)`
- `@focus(handler)`, `@blur(handler)`
- `@mouseenter(handler)`, `@mouseleave(handler)`

### Data Binding
- `@bind($var)` - Two-way binding
- `@val($var)` - Value binding
- `@checked($var)` - Checkbox binding
- `@selected($var)` - Select option binding

### Conditional & Looping
- `@if`, `@elseif`, `@else`, `@endif`
- `@foreach`, `@endforeach`
- `@for`, `@endfor`
- `@while`, `@endwhile`

### Attributes & Styling
- `@attr([...])` - Dynamic attributes
- `@class([...])` - Dynamic classes
- `@style([...])` - Dynamic styles
- `@show($condition)` - Toggle visibility
- `@hide($condition)` - Hide element

## Error Handling

Compiler provides:
- ✅ Syntax error detection
- ✅ Line and column references
- ✅ Helpful error messages
- ✅ Validation at compile time
- ✅ Warnings for common issues

## Performance Optimizations

- ✅ Incremental builds (watch mode)
- ✅ File hash comparison
- ✅ Dependency tracking
- ✅ Cascading rebuilds when imports change
- ✅ Caching of parsed ASTs

## Troubleshooting

### sao.config.json not found
```bash
# Create the file at project root
cat > sao.config.json << 'EOF'
{
  "root": "resources/sao/app",
  ...
}
EOF
```

### Context not found
Check that context name in sao.config.json matches CLI argument:
```bash
sao-build web  # 'web' must be in config.contexts
```

### Files not being compiled
- Check file has .sao extension
- Verify file is in paths specified in sao.config.json
- Run compiler with explicit context: `sao-build web`

## Development

### Run tests
```bash
npm test
```

### Debug mode
```bash
DEBUG=saola:* sao-build web
```

## License

MIT
