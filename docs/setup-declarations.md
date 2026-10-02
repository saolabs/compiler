# Declarations inside script setup

> This page documents the current contract. Shared `@...` declarations inside
> setup remain the supported syntax and continue to generate both SSR and CSR.
> Changing them to a JavaScript/TypeScript macro has been deferred; see
> [SAO_SYNTAX_UPGRADE_RFC.md](../../docs/SAO_SYNTAX_UPGRADE_RFC.md).

Shared declarations can live at the top level of `<script setup>`, next to their
TypeScript imports. Existing declarations outside the script remain supported;
both forms use the same compilation pipeline and view scope.

```sao
<script setup lang="ts">
import type { Status } from './types';

@props({initial: 0}: {initial: number})
@state(count: number = initial, status: Status = '')
@computed(doubled: number = count * 2)
@let(cardPath: string = __base__ + 'modules.components.statcard')
@importView(cardPath as StatCard)
@asset(logo = 'images/logo.svg')

function increment() { setCount(count + step); }
function logTotal() { console.log(get$doubled()); }
</script>

@const(step: number = 1)

<template>
    <button @click(increment())>{{ count }} / {{ doubled }}</button>
    <StatCard label="Shared declarations" value="SSR + client" />
</template>
```

The imported `Status` type stays in its `.ts` module; replace that example import
with the application's real type module. Types remain optional.

## Rules

- Supported setup declarations: `@vars`, `@props`, `@state`, `@states`, `@let`,
  `@const`, `@computed`, `@useState`, `@asset`, `@assets`, `@importView`.
- Use `@importView(path as Name)` inside setup. The existing `@import(path as Name)`
  form stays outside. JavaScript `import` and `import type` keep their usual syntax.
- Declarations must be top level, outside functions, methods and conditional
  blocks. The compiler rejects a nested declaration, unbalanced parentheses and
  `@import` inside setup. An optional trailing semicolon is accepted.
- Setup and outside declarations are collected in source order. Shared inputs
  should be declared before expressions that use them. Repeating a variable name
  across the two positions is an error, not an override.
- The setup script can appear before or after the template. Component tags belong
  in the template; put each imported component on its own line.
- Template control directives such as `@if`, `@foreach`, `@extends`, `@block`,
  `@await` and `@fetch` keep their existing positions outside setup.
- Top-level function declarations are instance-scoped and are registered for
  template events and lifecycle hooks. The existing `export default { ... }`
  object remains supported and can coexist when method names do not collide.

Simple components still need no script:

```sao
@state(count = 0)
<button @click(setCount(count + 1))>Click {{ count }}</button>
```

## Server, client and cost

Moving a declaration into setup changes its location, not where it runs.
Shared `@...` declarations still generate both Blade initialization and client
initialization, using the same expression rules and system variables. Computed
values are initialized on the server and use cached getters on the client.
An imported view path can use a shared binding such as `cardPath`; its server
registry is evaluated after the shared bindings are initialized.

Ordinary TypeScript imports, functions, local JavaScript statements and legacy
export-object methods remain client code. They are not automatically translated
to PHP or exposed as shared bindings. For example, a shared computed declaration
must not call a browser-only imported function. Use the existing server-only
blocks for server-specific work.

The new syntax adds a source scan during compilation. It adds no browser parser,
new runtime abstraction or hydration step: equivalent inside/outside declarations
produce the same targets apart from script whitespace. No benchmark claim is made
about the compile-time overhead.

Annotations are erased from Blade and preserved in generated TypeScript.
Run `tsc --noEmit` to enforce them; PHP compilation and Vite transpilation alone
do not check types. See [typed-computed.md](typed-computed.md) for computed reads,
the exported object's `this`, and runtime input validation limits.

## Test view

The Laravel demo `/demo/setup` exercises an imported type, shared props/state,
computed values, an asset path, an import path built from a system variable and
an outside constant. Grid is kept as-is. Tests cover generated TypeScript with
negative setter cases, real Laravel SSR, and browser hydration with interactions.
