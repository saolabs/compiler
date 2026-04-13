# Saola Compiler Integration Guide

## Overview

Saola Compiler là một phần của thư viện **Saola** npm package. Khi người dùng cài đặt Saola, họ sẽ tự động có quyền truy cập vào compiler.

## Installation

### Cách 1: Thông qua Saola Package (Khuyến khích)
```bash
npm install saola
```

Compiler sẽ tự động được tích hợp vào node_modules.

### Cách 2: Global Installation (Optional)
```bash
npm install -g saola
```

Khi đó có thể sử dụng `sao-build` từ bất kỳ đâu.

## Available Commands

### Via npm scripts (Khuyến khích)
```bash
npm run build:views              # Build all contexts
npm run build:views:web          # Build web context
npm run build:views:admin        # Build admin context
npm run build:views:mobile       # Build mobile context
npm run build:views:watch        # Watch all contexts
```

### Via npx
```bash
npx sao-build web              # Build web context
npx sao-build all              # Build all contexts
npx sao-build web --watch      # Watch web context
```

### Via direct CLI (if installed globally)
```bash
sao-build web
sao-build admin --watch
```

## Setup in Laravel Project

### 1. Create Configuration File
Copy example config từ Saola package:
```bash
cp node_modules/saola/compiler/sao.config.example.json sao.config.json
```

Edit `sao.config.json` với đường dẫn của project:
```json
{
  "root": "resources/sao/app",
  "output": {
    "base": "public/static/one",
    "contexts": {
      "web": "public/static/one/web",
      "admin": "public/static/one/admin"
    }
  },
  "contexts": {
    "web": {
      "name": "Web",
      "app": ["resources/sao/app/web/app"],
      "views": {
        "web": "resources/sao/app/web/views"
      },
      "blade": {
        "web": "resources/views/web"
      },
      "temp": {
        "views": "resources/sao/js/temp/web/views",
        "registry": "resources/sao/js/temp/web/registry.js"
      }
    }
  }
}
```

### 2. Add npm Scripts
Update `package.json`:
```json
{
  "scripts": {
    "build": "npm run build:templates && npm run build:webpack",
    "build:templates": "sao-build all",
    "build:templates:web": "sao-build web",
    "build:templates:admin": "sao-build admin",
    "build:templates:watch": "sao-build all --watch",
    "dev": "npm run build:templates:watch",
    "dev:web": "sao-build web --watch"
  }
}
```

### 3. Create Directory Structure
```bash
# Create source directories
mkdir -p resources/sao/app/web/app
mkdir -p resources/sao/app/web/views
mkdir -p resources/sao/app/admin/app
mkdir -p resources/sao/app/admin/views

# Create output directories
mkdir -p resources/views/web
mkdir -p resources/views/admin
mkdir -p resources/sao/js/temp/web/views
mkdir -p resources/sao/js/temp/admin/views
```

### 4. Run Compiler
```bash
# Build all templates
npm run build:templates

# Watch for changes during development
npm run dev

# Build specific context
npm run build:templates:web
```

## Integration with Saola Library

### Export from Main Package
Compiler được exported từ main Saola package:

```javascript
// In saola package.json
"exports": {
  "./compiler": {
    "import": "./compiler/index.js",
    "require": "./compiler/index.js"
  }
}
```

### Usage in Node.js
```javascript
// Import compiler directly
const Compiler = require('saola/compiler');

// Use programmatically
const compiler = new Compiler();
await compiler.run(['web']);

// Or build all contexts
await compiler.buildAllContexts(config, projectRoot);
```

## CLI in package.json Scripts

Compiler CLI được daftarkan sebagai `bin` di Saola package:

```json
"bin": {
  "sao-build": "./compiler/cli.js"
}
```

Ini memastikan:
- `sao-build` command tersedia dalam npm scripts
- Dapat dipanggil via `npx sao-build` tanpa instalasi global
- Tersedia sebagai global command jika package diinstal globally

## Build Process

### Command Flow
```
npm run build:views
       ↓
sao-build all
       ↓
node compiler/cli.js all
       ↓
Compiler.run(['all'])
       ↓
buildAllContexts()
       ↓
For each context:
  - Scan .sao files
  - Parse → AST
  - Generate Blade + JS
  - Write output files
  - Generate registry.js
```

### Output Structure
```
resources/views/
├── web/
│   ├── pages/
│   │   ├── Home.blade.php
│   │   └── About.blade.php
│   └── components/
│       └── Header.blade.php
└── admin/
    ├── Dashboard.blade.php
    └── Users.blade.php

resources/sao/js/temp/
├── web/
│   ├── views/
│   │   ├── WebPagesHome.js
│   │   ├── WebPagesAbout.js
│   │   └── WebComponentsHeader.js
│   └── registry.js
└── admin/
    ├── views/
    │   ├── AdminDashboard.js
    │   └── AdminUsers.js
    └── registry.js

public/static/one/
├── web/
│   ├── main.bundle.js
│   ├── main.css
│   └── assets/
└── admin/
    ├── main.bundle.js
    ├── main.css
    └── assets/
```

## Watch Mode

Untuk development, gunakan watch mode:

```bash
# Watch web context
npm run dev:web

# Watch all contexts
npm run dev

# Or directly
sao-build web --watch
sao-build all --watch
```

Watch mode akan:
- Detect file changes dalam .sao directories
- Automatically recompile affected files
- Preserve development experience
- Support hot reloading (if configured with webpack)

## Version Management

Compiler version selalu sama dengan Saola version:
- Saola package: v1.0.0
- Compiler module: v1.0.0

Update keduanya bersama-sama:
```bash
npm update saola
```

## Troubleshooting

### Command not found: sao-build
```bash
# Make sure Saola is installed
npm install saola

# Or use npx
npx sao-build web
```

### sao.config.json not found
```bash
# Ensure config exists at project root
ls sao.config.json

# Copy from template if missing
cp node_modules/saola/compiler/sao.config.example.json sao.config.json
```

### No .sao files found
Check:
1. Files exist in configured paths
2. Files have .sao extension
3. Paths in sao.config.json are correct
4. Directory structure matches config

### Permission denied on CLI
```bash
# Make CLI executable
chmod +x node_modules/saola/compiler/cli.js

# Or use npx
npx sao-build web
```

## Programmatic Usage

```javascript
const Compiler = require('saola/compiler');

const compiler = new Compiler();

// Build single context
await compiler.buildContext(config, projectRoot, 'web');

// Build all contexts
await compiler.buildAllContexts(config, projectRoot);

// With watch mode
await compiler.run(['web', '--watch']);
```

## Files Included in Package

Thư viện Saola include:

```
node_modules/saola/
├── compiler/
│   ├── cli.js                    # CLI executable
│   ├── index.js                  # Main Compiler class
│   ├── parser.js                 # Parser module
│   ├── blade-generator.js        # Blade generator
│   ├── js-generator.js           # JS generator
│   ├── config-manager.js         # Config manager
│   ├── test.js                   # Test suite
│   ├── package.json              # Compiler package info
│   ├── README.md                 # Compiler documentation
│   ├── ARCHITECTURE.md           # Technical docs
│   └── sao.config.example.json   # Example config
├── dist/                         # Compiled TypeScript
├── package.json                  # Main package
└── ...
```

## Development Workflow

### Development
```bash
# Terminal 1: Watch TypeScript
npm run build:watch

# Terminal 2: Watch templates
npm run dev:web

# Terminal 3: Start Laravel dev server
php artisan serve
```

### Production
```bash
# Build everything
npm run build
npm run build:templates

# Webpack build
npm run build:webpack
```

## Integration with Build System

### Webpack Integration
```javascript
// webpack.config.js
const Compiler = require('saola/compiler');

module.exports = {
  plugins: [
    // Plugin to trigger compiler during build
    new (class {
      apply(compiler) {
        compiler.hooks.beforeCompile.tapPromise(
          'SaolaCompiler',
          async () => {
            const ov = new Compiler();
            await ov.buildAllContexts(config, projectRoot);
          }
        );
      }
    })()
  ]
};
```

### Laravel Mix Integration
```javascript
// webpack.mix.js
const mix = require('laravel-mix');
const { execSync } = require('child_process');

mix.before(() => {
  execSync('sao-build all', { stdio: 'inherit' });
});

mix.js('resources/js/app.js', 'public/js/app.js')
   .css('resources/css/app.css', 'public/css/app.css');
```

---

**Saola Compiler v1.0.0**  
**Part of Saola Framework**  
**Created: 2026-02-03**
