# FNLLA Core Runtime Contracts

These contracts describe the public runtime surface shipped by FNLLA Core
2.2.5. Full FNLLA builds on the same primitives and adds the integrated
project operations layer separately.

## Application Generators

Core includes offline-capable generators for common application classes:

```console
php fnlla make:controller Orders
php fnlla make:middleware OrderAccess
php fnlla make:command ImportOrders
php fnlla make:factory Order
php fnlla make:seeder Order
php fnlla make:migration create_orders
```

Generators create minimal PHP scaffolding, not business logic. Classes use a
single identifier made from letters, digits and underscores, with no leading
digit. Migrations accept letters, digits, underscores and hyphens; filenames use
UTC timestamps. Existing destinations are never overwritten.

## Routing And HTTP

Routes are registered explicitly and dispatched through `Fnlla\Php\Routing\Router`.
Dynamic parameters are segment-scoped, `HEAD` can fall back to `GET`, and
`OPTIONS` reports the matching `Allow` header. Route caching accepts controller
array handlers and rejects closures or object handlers.

`Fnlla\Php\Http\Request` captures method, path, headers, cookies, uploaded files,
raw body, JSON payloads and route parameters. Request IDs are normalized to a
header-safe token. Body capture is bounded by configured limits and rejects
oversized or malformed payloads.

`Fnlla\Php\Http\Response` normalizes headers and rejects control line breaks or
null bytes in header values.

## Container

`Fnlla\Php\Container\Container` provides explicit bindings, singleton instances,
constructor resolution, callable invocation and circular-dependency detection.
Runtime code should bind interfaces in providers rather than relying on hidden
global mutation.

## Validation

`Fnlla\Php\Validation\Validator` supports the maintained rule set used by Core:
`required`, `string`, `array`, `email`, `integer`, `numeric`, `url`, `min`,
`max`, `boolean`, `confirmed`, `file`, `nullable` and `in`. Invalid payloads
throw `ValidationException` with field-level errors.

## Database And Migrations

Core includes a MySQL-oriented query builder, named PDO connection manager,
migration base classes, seeders and CLI migration commands. Query identifiers
are validated and quoted; unsupported operators and invalid `NULL` comparisons
fail before execution.

Writes in non-local environments require explicit force flags in the relevant
CLI commands. Back up before destructive schema work.

## Sessions And Authentication

Application authentication uses `AuthManager`, `UserProviderInterface` and
`SessionStore`. Product-specific account contexts are provided by applications
built on top of Core.

Persistent HTTP sessions enforce idle and absolute expiry. Expiry or corrupt
metadata clears all session domains and rotates identifiers. Redis sessions use
strict IDs and bounded locks; file sessions require private storage outside the
web root.

## Cache And Queues

Core cache helpers publish PHP array cache files atomically and preserve a valid
previous file on failed rebuilds. Cache files may contain sensitive values and
must stay outside the public document root.

The queue managers support file and Redis stores with expiring reservations,
retry metadata and stale-token rejection. Application jobs must remain
idempotent.

## Proxy And Request Boundaries

Only explicitly configured trusted proxies may supply forwarded identity.
Forwarded chains are walked from the nearest hop toward the client; client-supplied
left prefixes cannot override the trust boundary. Trusted ingress must overwrite
forwarded protocol headers with one canonical value.

## Upgrade Boundary

FNLLA Core owns public framework primitives. Full FNLLA builds on those
primitives with the broader application platform, project workflow and product
experience. Keep Core changes useful to standalone framework consumers and add
shared primitives here before consuming them from the full product.
