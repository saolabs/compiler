# View auth state

With compiler 3.4.0, client 1.6.0+ and Core 2.8.0+, put `@useAuth` at the
top level of a view, either before the template or in `<script setup>`.
`@useAuth()`, `@useauth` and `@useauth()` are equivalent. No arguments are needed.

```sao
<script setup lang="ts">
@useAuth
function clearLocally() { set__auth__(null); }
</script>
<template>
    @if(__auth__)
        <span>{{ __auth__.name }}</span>
    @else
        <span>Guest</span>
    @endif
    <button @click(clearLocally())>Clear local display</button>
</template>
```

The directive declares an ordinary writable state `__auth__`, initialized on the
client from `App.Helper.auth().user()`, and the usual `set__auth__(value)` setter.
The server initializes the same state from Core's public user projection. This
keeps the serialized hydration state limited to configured public fields instead
of exposing a Laravel user model. A guest is `null` on both sides.

The compiler registers a state subscription to the existing `@auth` dependency.
The runtime shares one AuthService listener per view, batches updates, preserves
paused-view behavior and removes subscriptions when the view is destroyed. No
polling, extra HTTP request or manual mounted/unmounted hook is necessary.
Login, logout and profile changes refresh `__auth__`; a view's local setter does
not change App.Auth or the server session. The next AuthService change replaces
that local value with the current user again.

`__auth__` is reserved in an opted-in view: declaring it again, repeating
`@useAuth`, passing arguments or placing the directive inside a setup function
is a compile error. Literal examples in setup strings/comments and `@verbatim`
do not activate the directive. This directive subscribes a view to auth state;
protected routes still need server middleware/policies.
