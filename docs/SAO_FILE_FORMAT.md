# Saola File Format (`.sao`) Specification

The `.sao` file format is the proprietary templating language of the Saola Framework. It is designed to combine the reactivity of modern frontend frameworks (like Vue.js or Svelte) with the robust server-side processing of Laravel Blade.

This document describes the architecture, syntax, rules, and usage of `.sao` files. It also provides comprehensive guidelines for building IDE extensions (Syntax Highlighting, Snippets, Autocomplete, etc.) to support the Saola Framework ecosystem.

---

## 1. File Architecture & Sections

A `.sao` file is divided into three logical components:
1. **Declarations block**: Configuration, state, props, constants, and variables.
2. **Template block**: The HTML/UI structure that supports loops, conditional rendering, and dynamic bindings.
3. **Script / Style blocks**: Optional Vue/Svelte-like `<script setup>` and `<style>` for client-side logic and scoped styling.

### Wrapper Priority Rules (Crucial for Compilers & IDEs)
Saola supports multiple syntax modes within the Template block, determined by the "Wrapper Tag" used.

If multiple wrappers are placed in the same file, the **FIRST wrapper encountered** is exclusively processed. All subsequent wrappers (and their contents) are dropped and ignored.

#### A. Modern Syntax wrappers (JS-like syntax)
- `<template> ... </template>`
- `<sao:blade> ... </sao:blade>`
- **No wrapper** (Directly writing HTML without any enclosing wrapper tag)

*When the modern syntax is used, the preprocessor is automatically engaged. You write plain JavaScript syntax across your variables and loops (e.g. `users as user`), and Saola transforms it into PHP/Blade during compilation.*

#### B. Legacy PHP Syntax wrapper (Pass-through mode)
- `<blade> ... </blade>`

*When `<blade>` is used, the preprocessor is bypassed. You MUST write strict PHP/Blade syntax inside this block (e.g. `$users as $user`).*

---

## 2. Syntax & Declarations

The declarations section must be placed at the top of the file, outside of the template wrappers.

### `@states` (Recommended Modern Syntax)
Defines reactive state variables using a JavaScript object literal syntax.
```saola
@states({
    count: 0,
    user: { name: 'Alice', age: 25 },
    isVisible: true
})
```

### `@props`
Defines properties passed from parent components.
```saola
@props(title, content, theme = 'light')
```

### Variable Declarations
- `@let(varName = value)`: Mutable variable used within the template scope.
- `@const(API_URL = '/api/v1')`: Immutable constant.
- **Legacy variants**: `@useState($var, value)`, `@vars([$a = 1, $b = 2])`.

---

## 3. Directives & Template Expressions

Directives allow for standard control flows, DOM bindings, and event handling within the HTML structure. Saola inherits Laravel Blade's core directives but supercharges them with JS syntax and Vue-like DOM directives.

### 3.1. Outputting Variables
Variables are rendered using double curly braces (Mustache syntax).
- **Modern Mode**: `{{ user.name }}`
- **Legacy Mode**: `{{ $user['name'] }}`

### 3.2. Control Flow Directives

#### Conditionals
- `@if(condition)` / `@elseif(condition)` / `@else` / `@endif`: Standard if logic.
- `@switch(val)` / `@case(match)` / `@default` / `@endswitch`: Switch statements.
- `@show(condition)`: Conditionally renders an element (similar to `v-show`, toggling CSS `display`).
- `@hide(condition)`: The inverse of `@show`.

#### Loops
- `@foreach`: Iterates over arrays or objects.
  - *Modern*: `@foreach(items as item)` or `@foreach(items as key => item)`
  - *Legacy*: `@foreach($items as $key => $item)`
- `@for(i = 0; i < 10; i++)`: Standard for loop.
- `@while(condition)`: Standard while loop.
- `@break` / `@continue`: Loop control statements.

### 3.3. HTML Attribute Bindings
Saola provides shorthand directives to bind dynamic JS variables directly to HTML attributes, eliminating the need for string interpolation inside quotes.

- `@attr({ href: link, title: docTitle })`: Bind multiple generic attributes via object.
- `@class(['active' => isActive, 'text-red' => hasError, 'btn'])`: Dynamically apply CSS classes based on truthy conditions.
- `@style({ 'color': textColor, 'font-size': size + 'px' })`: Dynamically apply inline styles.
- **Form State Bindings**:
  - `@bind(stateVariable)`: Two-way data binding (like `v-model`).
  - `@val(value)`: Binds the `value` attribute.
  - `@checked(isChecked)`: Binds the `checked` boolean attribute.
  - `@selected(isSelected)`: Binds the `selected` boolean attribute.
- **Boolean Attributes**:
  - `@disabled(isDisabled)`
  - `@required(isRequired)`
  - `@readonly(isReadonly)`

### 3.4. Event Handlers
Events are attached directly using `@eventName(handler)`. You can call JS methods defined in your `<script setup>` or execute inline state mutations.
- `@click(count++)` or `@click(submitForm())`
- `@change(updateVal(event))`
- `@input(...)`
- `@submit(...)`
- `@keydown`, `@keyup`, `@keypress`
- `@mouseenter`, `@mouseleave`, `@mouseover`, `@mouseout`
- `@focus`, `@blur`
- `@wheel`, `@scroll`, `@resize`, `@load`, `@dblclick`, `@contextmenu`

### 3.5. Template Architecture & Components

- `@import(...)`: Load other Saola components to use as custom HTML tags. Example: `@import('components.card' as card)` -> `<card></card>`.
- `@extends('path.to.layout')`: Declare a parent layout.
- `@section('name', 'content')`: Define a section of content for the parent layout.
- `@block('name') ... @endblock`: Define a block of content (similar to section).
- `@yield('name')`: Output the content of a section/block.
- `@include('path')`: Include another template directly.
- `@exec(JS_Expression)`: Execute an arbitrary JS/PHP expression silently without outputting it to the DOM (e.g., `@exec(a = 10, b = 20)`).

---

## 4. Script and Style Sections

### `<script setup lang="ts">`
Similar to Vue 3, this defines the client-side logic mapped directly to the component's `ViewController`.
```html
<script setup lang="ts">
import { saola } from 'saola';
export default {
    name: 'Counter',
    methods: {
        increment() {
            // "count" aligns with the @states({ count: 0 })
            setCount(count + 1);
        }
    }
}
</script>
```

### `<style scoped>`
Defines CSS styles for the component. The `scoped` attribute ensures styles don't leak out to other components.
```html
<style scoped>
.header { color: red; }
</style>
```

---

## 5. Guide for IDE Extension Developers

Building an extension for VS Code, WebStorm, or IntelliJ to support `.sao` files? Here is what you need to implement:

### 1. File Recognition & Icons
- **Extension**: `.sao`
- **Language ID**: `saola`
- **Icon Recommendation**: The Saola mascot or a hybrid HTML/PHP icon.

### 2. Syntax Highlighting
The `.sao` syntax is a hybrid. A robust Grammar (e.g., TextMate grammar for VSCode) should:
- Inject **HTML** grammar at the root level.
- Inject **JavaScript/TypeScript** grammar inside `<script>` tags.
- Inject **CSS/SCSS** grammar inside `<style>` tags.
- Tokenize `@` directives (`@states`, `@props`, `@foreach`, `@if`, `@import`) as Control Keywords / Macros.
- Inside `@states(...)`, tokenize the content strictly as a **JavaScript Object Literal**.
- Inside `{{ ... }}` expressions, tokenize the content as JavaScript expressions.

### 3. Autocomplete / IntelliSense
- **Top-level snippets**:
  - `states` -> expands to `@states({\n\t$1\n})`
  - `props` -> expands to `@props($1)`
  - `template` -> expands to `<template>\n\t$1\n</template>`
  - `script` -> expands to `<script setup lang="ts">\nexport default {\n\t$1\n}\n</script>`
- **Directives snippets**:
  - `foreach` -> `@foreach(${1:items} as ${2:item})\n\t$3\n@endforeach`
  - `if` -> `@if(${1:condition})\n\t$2\n@endif`
- **State linking**: If a developer types `{{ `, suggest autocomplete options derived from parsing keys inside `@states({...})` and `@props(...)` at the top of the file!

### 4. Language Server Protocol (LSP) hints
For an advanced extension:
- If inside `<blade>`, prompt a warning or info tip: *"Legacy PHP Syntax Mode active. Variables require `$` prefix."*
- If inside `<template>` or `<sao:blade>` or no wrapper, provide JavaScript parameter hints.
- Warn if multiple level-0 wrappers (`<template>` and `<blade>`) are detected, as only the first counts and the others are discarded by the compiler.
