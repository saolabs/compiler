# Saola Compiler v3.1.2

Fixes client-side compilation of named `route()` calls. The compiler now emits
`App.Router.getURL(...)` so views resolve named routes through the client router
instead of calling the nonexistent `App.View.route(...)` method.

## Compatibility

PHP ^8.3; optional Laravel integration.

## Release scope

Includes the named-route client runtime mapping fix and updated expression
parity expectations.
