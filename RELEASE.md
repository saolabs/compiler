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
