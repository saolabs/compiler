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
