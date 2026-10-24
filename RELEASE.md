# Saola Compiler v3.0.0

Breaking translation contract: bare __() in .sao compiles to PHP text() and client Helper.text(). Nested keys resolve through the host's Saola language store/snapshot; they are not Laravel text-to-text lookups. Calls on objects/classes and Laravel __() in ordinary PHP are unchanged. Existing .sao templates that need Laravel translations must use trans()/choice() explicitly before upgrading.

Also preserves runtime props passed to @extends on both Blade and client output. PHP ^8.3; pair the new translation contract with saola/core >=2.4 and @saolabs/client >=1.3 (GitHub tag available for the coordinated admin alpha).

Validated with the full compiler suite in a separate checkout using its own Composer dependencies, including translation-call boundaries and extends runtime props. No source tag is overwritten.
