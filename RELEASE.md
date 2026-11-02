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
