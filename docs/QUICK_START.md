# Saola Compiler - Quick Start

## Installation

```bash
# NPM install (khi đã publish)
npm install saola

# Local install (để test)
cd /path/to/saola && npm pack
cd /path/to/your-project && npm install ../saola/saola-1.0.0.tgz
```

## Configuration

Tạo `sao.config.json` ở project root:

```json
{
  "paths": {
    "saolaView": "resources/sao",
    "bladeView": "resources/views",
    "temp": "resources/js/temp"
  },
  "contexts": {
    "web": {
      "name": "Web",
      "app": ["web/app"],
      "views": {"web": "web/views"},
      "blade": {"web": "web"},
      "temp": {
        "views": "web/views",
        "app": "web/app",
        "registry": "web/registry.js"
      }
    }
  }
}
```

## Usage

```bash
# Compile specific context
npx sao-compile web
npx sao-compile admin

# Compile all contexts (skips 'default')
npx sao-compile all

# Show help
npx sao-compile --help
```

## Project Structure

### Input
```
resources/sao/
├── web/
│   ├── app/              ← JavaScript sources
│   │   ├── helpers/
│   │   └── services/
│   └── views/            ← .sao templates
│       └── pages/
│           └── home.sao
```

### Output
```
resources/
├── views/                ← Blade files (SSR)
│   └── web/
│       └── pages/
│           └── home.blade.php
└── js/temp/              ← JavaScript files
    └── web/
        ├── app/          ← Copied from sources
        │   ├── helpers/
        │   └── services/
        └── views/        ← Compiled views
            └── WebPagesHome.js
```

## .sao File Format

```one
@vars($users = [
    ['name' => 'John', 'role' => 'Admin'],
    ['name' => 'Jane', 'role' => 'User']
])
@useState($userList, $users)
@useState($count, count($users))
@await

<blade>
    @extends($__layout__.'base')
    @section('title', 'Users')
    @block('content')
        <div class="users">
            <h2>Total: {{ $count }}</h2>
            @foreach($userList as $user)
                <div>{{ $user['name'] }} - {{ $user['role'] }}</div>
            @endforeach
        </div>
    @endblock
</blade>

<script setup>
export default {
    async loadUsers() {
        const response = await fetch('/api/users');
        const users = await response.json();
        this.setUserList(users);
        this.setCount(users.length);
    }
}
</script>

<style scoped>
.users {
    padding: 20px;
}
</style>
```

## Key Features

✅ **Declaration Order**: Giữ nguyên thứ tự khai báo
✅ **Auto-Create Folders**: Tự động tạo output directories  
✅ **Namespace Support**: Multiple namespaces per context
✅ **App Files Copy**: Copy app sources vào temp
✅ **PHP to JS**: Convert PHP expressions sang JavaScript
✅ **Prerender/Render**: Separate SSR và dynamic rendering
✅ **Parallel**: Blade và JS compile song song
✅ **Error Handling**: Skip missing sources gracefully

## Common Issues

### 1. Declaration Order Wrong
**Problem:** Variables used before declared
**Cause:** Old compiler grouped by type
**Fixed:** v1.0.0 preserves original order

### 2. Temp Folders Not Created
**Problem:** Output folders missing
**Cause:** Manual creation required
**Fixed:** Auto-create with `ensureDir()`

### 3. App Files Not Copied
**Problem:** App sources missing in temp
**Cause:** Not implemented
**Fixed:** `copyAppFiles()` function added

### 4. PHP Concatenation Not Converted
**Problem:** `'string' .$var. 'string'` stays as-is
**Cause:** Simple string detection bug
**Fixed:** Check for concatenation operators

## Console Output

```bash
🔨 Building context: web

📁 Namespace: web
   Views config: web/views
   Views: /path/to/resources/sao/web/views
   Blade config: web
   Blade: /path/to/resources/views/web
   Found: 2 files

  ✓ web.pages.home
  ✓ web.pages.about

✅ Successfully compiled 2 files for context: web

📦 Copying app files for context: web
   📁 Created temp directory: web/app
   📁 web/app → web/app
   ✅ Copied 5 items to web/app
```

## Next Steps

- [ ] Implement watch mode
- [ ] Generate registry.js
- [ ] Production build (minify, bundle)
- [ ] Source maps
- [ ] Error reporting improvements

## Support

- Documentation: See `compiler/README.md`
- Architecture: See `compiler/ARCHITECTURE.md`
- Issues: GitHub Issues
