# Client middleware declaration

Available in compiler 3.5.0, client 1.7.0 and builder 1.5.0. Module-level
`clientMiddleware()` declarations require Core 2.9.0.

Declare middleware for a routed `.sao` page outside its template:

```sao
@middleware('requireLogin')

<template>
    <h1>Account</h1>
</template>
```

Multiple aliases, alias parameters, groups and repeated declarations work:

```sao
@middleware('auth', 'profile:complete')
@middleware(['audit', 'role:admin,editor'])
```

It can also be declared at top level inside `<script setup>`. Only static
quoted aliases are accepted; variables, helper calls and functions are not
available before the view exists. Declarations inside the template or nested
inside a setup function are rejected. Comments, strings and `@verbatim`
examples do not register policies.

The compiler strips the declaration from Blade and emits static JavaScript
metadata outside the factory:

```js
export const __SAOLA_MIDDLEWARE__ = ["requireLogin"];
Object.assign(AccountFactory, {middleware: __SAOLA_MIDDLEWARE__});
```

Register aliases in project bootstrap with
`defineBundle({middleware: {aliases: {...}}})`. The Router reads this metadata
before invoking setup, consuming SSR hydration data or restoring a cached
page. Builder carries the same metadata on generated lazy loaders so it is
available before importing the page chunk. Route-level policies run before
view-level policies.

This is a client navigation declaration on the route's entry view. It does
not install PHP middleware or guard synchronous `@include`/`@extends` calls
or direct ViewManager mounts. Keep Laravel route middleware and API checks
for server access; use `@auth`/`@useAuth` for conditional reactive content in
components and layouts. For a guard shared across several pages, declare
`clientMiddleware(...)` on their module/context.

Use the updated compiler, client and builder together and rebuild views and
registries. For a hand-written lazy registry, carry the static aliases on the
loader explicitly; the Router cannot read a module that has not been loaded:

```ts
Object.assign(() => import('./account.js'), {middleware: ['requireLogin']})
```
