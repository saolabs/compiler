# Optional types and computed values

```sao
@vars(title: string = 'Cart', items: number[] = [])
@let(offset: number = 0, caption = 'Total')
@const(rate: number = 2)
@props({price: 5}: {price: number})
@states({enabled: true}: {enabled: boolean})
@state(qty: number = 2)
@computed(total: number = price * qty + offset)

<template>
    <strong>{{ total }}</strong>
    <button @click(increment())>+</button>
</template>
<script setup>
export default {
    increment() {
        setQty(qty + 1);
        console.log(get$total());
    }
}
</script>
```

Types are optional. Annotations use TypeScript syntax, including unions, array
types, object shapes and generics. Declare shared variables outside the template
wrapper or at the top level of `<script setup>`; see
[setup-declarations.md](setup-declarations.md). An annotation makes the output
TypeScript even without `lang="ts"`.
`CompileResult.lang` identifies the output language. Builder, `compileFile`,
directory compilation and the Artisan command use that language for the file
extension. Explicit `.js` output paths become `.ts` for typed source.

Annotations are preserved on local declarations, props interfaces, state values,
setters and computed return values. PHP output contains values and expressions;
TypeScript types are erased. Run `tsc --noEmit` over generated views and application
code to enforce these types. PHP compilation and Vite transpilation alone do not
perform TypeScript semantic checking. An annotation does not validate incoming
HTTP/JSON data at runtime; validate that data on the server or at its input boundary.

## The exported object's `this`

Methods in `export default { ... }` are merged into the View instance and bound
to it. The client API supplies a `ThisType` context for the generated
`setUserDefinedConfig` call: `this` includes the declared View API and the
properties and methods inferred from the exported object. No explicit
`this: SomeInterface` parameter or duplicate method interface is needed.

```ts
export default {
    requests: 0,
    record(step: number) {
        this.requests += step;
        console.log(this.path);
    },
    mounted() {
        this.record(1);
    },
}
```

Declare per-instance fields on that object so their types can be inferred. For
an initially undefined timer, use
`timer: undefined as ReturnType<typeof setTimeout> | undefined`. The context
excludes View's legacy catch-all index signature so unknown member names and
incorrect arguments are diagnosed. This affects type checking only; runtime
merge and binding remain the same. Use method syntax or ordinary functions for
view-bound methods; arrow functions retain their lexical `this`.

## Computed semantics

- A computed value is read-only. Dependencies are collected from its expression.
- State writes invalidate dependent caches synchronously, including chains.
  Values are evaluated only when read and are cached between dependency changes.
  DOM notifications remain batched. Unread computed values do not run during a flush.
- In templates and other computed expressions, use the plain name: `total`.
  The compiler emits a getter call for each read.
- In `<script setup>`, read `get$total()`. There is no mutable local `total`
  snapshot. This replaces the old compiler's local mirror and prevents stale
  reads immediately after a setter. Existing scripts that read the former mirror
  must switch to the getter.
- SSR initializes inputs, then computed values in dependency order, using PHP
  assignments. Dependency cycles are rejected at compilation and by the runtime
  API. Computed inputs must be deterministic, side-effect-free expressions that
  the shared Saola expression language supports. Browser-only functions are not
  SSR computations. Mutable `@let` variables are not reactive inputs; use state.

Collection expressions support array `.filter`, `.map`, `.reduce`, and `.length`:

```sao
@computed(activeCount = users.filter(user => user.active).length)
@computed(totalRoles = users.reduce((sum, user) => sum + user.roles.length, 0))
```

The browser executes native array methods. PHP uses array operations and arrow
closures; property reads use Laravel `data_get` for arrays and objects. Callbacks
accept one or two simple parameters and an expression body. `reduce` requires an
explicit initial value. This is a shared expression subset, not a general JavaScript
engine on the server.

## Validation

Regression tests cover optional annotations, nested generic types, typed defaults
and setters using the actual TypeScript checker, server props including zero,
collection callbacks on both targets, computed chains, immediate reads, laziness,
dependency replacement, read-only writes, cleanup and dependency cycles.

The full legacy parity suite was also run. Its pre-existing failures remain;
the SymbolCollector and Preprocessor comparisons additionally differ from the old
JavaScript implementation because that implementation lacks these computed
declarations. Example snapshots were regenerated and reviewed for the intended
changes. New behavior is checked by compiler unit tests and real compile-to-mount
and Laravel execution tests, rather than by regenerating all legacy snapshots.

Verification commands: `npm run check` in the application checks all generated
views and application TypeScript; production build runs this gate first. The
focused typed fixtures also check negative setter/default/prop cases. Historical
application-wide diagnostic counts are recorded in dated audit reports; they
are not a permanent exception to strict checking.
