# FNLLA Core Runtime Contracts

These contracts describe the public runtime surface in the unreleased FNLLA
Core 2.3 candidate. The published baseline remains 2.2.4. Full FNLLA builds on
the same primitives and adds the integrated project operations layer separately.

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
array handlers and rejects closures or object handlers. Product Module routes
are registered from current lifecycle state on each bootstrap and are excluded
from the application route cache, so disable cannot leave a cached privileged
route active.

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

`ProductModuleRegistry` uses the same Container and Router contracts for
validated, application-configured module extensions. It rejects a module
service that would replace an existing binding and rejects duplicate routes
before applying any registration.

`scoped()` bindings live only inside `withinScope()` and are disposed in its
`finally` boundary. Queue workers create one scope per job. `inspectBindings()`
returns only deterministic abstract/concrete/lifetime metadata, never service
values.

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

`afterCommit()` defers callbacks until the outer managed transaction commits;
nested rollback discards its callbacks. An externally owned PDO transaction
cannot accept deferred effects because Core cannot observe its final commit.
Callback failure after commit throws `PostCommitCallbackException`, which
explicitly means the database commit already succeeded.

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

The queue manager supports file and Redis stores with expiring reservations,
renewal, retry/backoff metadata, poison quarantine and stale-token rejection.
Jobs use the `fnlla.queue.v1` envelope and an explicit `queue.job_types`
registry. A bounded legacy reader is enabled only until FNLLA Core 3.0.0.

Each job receives an isolated `JobContext` containing correlation, tenant,
actor, attempt and idempotency identifiers. Handlers must call
`assertLeaseOwned()` immediately before external effects and may call
`renewLease()` during long work. Durable idempotency markers suppress a replay
after successful handling but cannot close the crash window between an external
provider accepting an effect and the local completion marker. Delivery is
at-least-once; exactly-once is not claimed.

Direct queue, event and mail dispatch during a managed database transaction
fails closed. Use `pushAfterCommit()`, `dispatchAfterCommit()` or
`sendAfterCommit()`. `queue:work [max-jobs] [max-seconds]` has bounded lifetime
and finishes the active job on a stop signal before taking another.
`runtime:inspect` emits `fnlla.runtime.inspection.v1` with redacted binding,
envelope, registry and queue-count metadata; it never includes job payloads.

## Authorization, Tenancy And Audit

`AccessControl` grants only permissions declared by an active actor's known
role. Resource authorization also requires an explicit `PolicyRegistry` entry;
`OwnershipPolicy` can enforce owner and tenant identity. `RoleAssignmentGuard`
rejects self-assignment and unknown roles. Existing Gate callbacks remain
compatible, while explicit Gate-to-permission mappings support migration.

`TenantContextManager` supports `none`, `organization` and `custom` modes.
Multi-tenant work fails without a server-resolved active actor and tenant.
Request or job payload tenant IDs never establish membership. HTTP middleware
and queue workers reset context in `finally`; workers re-resolve membership so
revoked access cannot keep running from an old envelope.

`TenantResourceScope`, `TenantCacheStore` and `TenantFilesystem` provide
explicit boundaries for business repositories, caches, files, exports and tool
arguments. They do not add a magic filter to technical tables. Audit events use
`fnlla.audit-event.v1`; the JSON-lines adapter keeps only allowlisted state,
redacts sensitive keys and applies bounded retention. See
[Security primitives](SECURITY-PRIMITIVES.md) for setup and limitations.

Actions use the same authorization and tenant context. `ActionRunner` enforces
permission/policy, validation and one managed transaction before the domain
mutation, idempotency receipt, audit and domain-event outbox records. The relay
runs after commit; rollback publishes no event. See
[Actions and domain events](ACTIONS-AND-DOMAIN-EVENTS.md) for storage migration,
retry and listener requirements.

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
