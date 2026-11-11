# Saola Compiler v3.8.0

New compile option `fuseRows` (`CompileOptions::$fuseRows`, CLI `--fuse-rows`, **off by default**): a simple `@foreach` body is emitted as one `this.__fused(template, idSuffix, parentElement, get)` call per root element of the row instead of a nested `this.html(...)` tree. The template is a static descriptor hoisted like `__scN`; `get(i)` returns the row's i-th dynamic value (text, class / attr / style binding, event handler list). Ids equal those of the regular output and Blade does not change, so hydration and `s:o:` markers are unaffected.

A body is fused only when the existing memo analysis gives mode 1 or 2 and every node is an element, static text, escaped `{{ }}`, event, or class / attr / style binding. `@if`, `@include`, nested loops, `@bind`, form controls, raw echoes, interpolated class names, transitions, prop bindings and exec nodes keep the previous output byte for byte. A loop nested inside another loop is never fused (its memo mode is 0). With the option off, output is identical to 3.7.0.

Fused output requires `@saolabs/client` ≥ 1.10.0. Do not set the option by hand for older clients; `@saolabs/builder` 1.7.0 enables it from the installed client version. `SAOC_FUSE_ROWS=1` forces it on for running the parity gates over fused output.

Validation: 348 compiler tests, 950 assertions; golden gates regenerated for corpus drift in the starter's views (default output is unchanged by this release; Examples gate still stale as before); marker-sync gate 218/218 with fusing forced on; starter browser gate 70/70 with client 1.10.0 and builder 1.7.0.

# Saola Compiler v3.7.0

`@foreach` memo now carries the list of states its body captures: the trailing arguments of `__foreach` become `mode, ["stateA", "stateB"]`. The client re-runs kept rows only when one of those states changes instead of when any state of the view changes. States read only inside self-updating bindings (`factory: () => …, stateKeys: [...]` of class / attr / style / prop) are not listed, because those bindings subscribe on their own. Memo is no longer switched off by an `@if` / `@switch` inside the loop body or by the view's system constants (`__base__`, `__module__`, …).

Fixes raw echo hydration: `{!! expr !!}` without state had an output marker in JavaScript but none in Blade, so hydration could not claim it and rendered the content twice (seen with `{!! trans('…') !!}`). Blade now always wraps raw echoes in `@startMarker('output', …)`; the `@children` slot is unchanged.

Pairs with `@saolabs/client 1.9.0` (older clients ignore the extra argument). Every application must rebuild Blade and JavaScript views with the same compiler because Blade markers around raw echoes changed.

Validation: 332 compiler tests, 897 assertions; golden gates regenerated (Examples gate still stale as before); SSR↔CSR browser parity gate on the starter 35/37 (two known normaliser misses on `/docs/directives`).

# Saola Compiler v3.6.1

Fixes `<textarea>` and `<title>` elements with static attributes / classes being hoisted into a `__scN` constant before `content` is appended in `JsEmitter::genHtml()`.

Validation: 324 compiler tests, 877 assertions.

# Saola Compiler v3.6.0

Adds `@foreach` memo mode (`0` / `1` / `2` trailing argument on `__foreach`), closed block hydration IDs (`s{name}-…`), hoisted static element configs (`__scN`), and compile-time validation for misplaced `@block` directives (names outside `\w+`, nested inside a tag or loop, or used in a view without `@extends` inside `<template>`). `<output>` and tags resembling hydration ID kinds now receive ordinary element IDs.

Pairs with `@saolabs/client 1.8.0`. Every application must rebuild Blade and JavaScript views with the same compiler because hydration IDs inside blocks changed.

Validation: 323 compiler tests, 875 assertions.

# Saola Compiler v3.5.0

Adds static `@middleware(...)` declarations for routed views outside `<template>` or at top level in `<script setup>`.

Validation: 312 compiler tests, 848 assertions.

# Saola Compiler v3.4.1

useAuth now subscribes directly to App.Auth and calls set__auth__(App.Auth.user()). The view owns unsubscribe through its existing ResourceScope.defer lifecycle. No synthetic state subscription is generated for useAuth. Setters still batch rendering and retain pause/resume behavior, while local auth state updates immediately on service changes.

Compatible with existing @saolabs/client >=1.6.0 and saola/core >=2.8.0; rebuild application views. Includes complete .sao and generated JavaScript examples in docs/examples.

Validation: 308 compiler tests, 785 assertions; actual Blade hydration, immediate state propagation, local setter, paused rendering, and destroy cleanup integration tests.

# Saola Compiler v3.4.0

Adds @useAuth / @useAuth() and lowercase aliases. Opted-in views receive writable __auth__ state, the ordinary set__auth__(...) setter and an automatic auth subscription disposed with the view. SSR stores only Core public user fields. Also fixes reactive dependency extraction for state identifiers containing underscores.

Uses existing @saolabs/client >=1.6.0 and saola/core >=2.8.0 APIs; rebuild application views. No client/runtime release is required. See docs/USE_AUTH.md.

Validation: 308 compiler tests, 777 assertions; client SSR/hydration, setter, login/logout, pause/resume and listener cleanup integration coverage; full client suite 648 tests passed.

# Saola Compiler v3.3.0

Adds reactive auth/guest directives, including named guards and alternative branches. Auth reads in rendering expressions carry the auth dependency through SSR hydration markers and client subscriptions. Pairs with @saolabs/client 1.6.0 and saola/core 2.8.0; rebuild application views when upgrading.

Validation: 305 tests, 690 assertions, including directive/marker parity.

# Saola Compiler v3.1.2

Fixes client-side compilation of named `route()` calls. The compiler now emits
`App.Router.getURL(...)` so views resolve named routes through the client router
instead of calling the nonexistent `App.View.route(...)` method.

## Compatibility

PHP ^8.3; optional Laravel integration.

## Release scope

Includes the named-route client runtime mapping fix and updated expression
parity expectations.
