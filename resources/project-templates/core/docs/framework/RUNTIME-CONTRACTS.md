# CLI And Runtime Contracts

These contracts describe the changes prepared for FNLLA 2.2.0. They do not declare
that version published. FNLLA and FNLLA Core share these primitives;
the Developer Panel and its account store are separate from application auth.

## Application Generators

Both presets provide these offline-capable commands:

```console
php fnlla make:controller Orders
php fnlla make:middleware OrderAccess
php fnlla make:command ImportOrders
php fnlla make:factory Order
php fnlla make:seeder Order
php fnlla make:migration create_orders
php fnlla help make:controller
```

Generators create minimal PHP scaffolding, not business logic. Classes use a
single identifier (letters, digits, underscores; no leading digit). The relevant
suffix is appended once. Migrations accept letters, digits, underscores and
hyphens; filenames use UTC timestamps. Existing destinations are never overwritten,
including a same-second migration-name collision. Retry with a distinct name.
Paths, namespaces, unknown flags and extra arguments are rejected.

New exports map `App\\` to `app/`. Controllers, middleware and commands are created
there; factories and seeders use `Database\\Factories` and `Database\\Seeders`.
Existing projects without an App mapping retain their legacy `Fnlla\\Php` / `src`
destination. Add an App PSR-4 mapping and run `composer dump-autoload` to opt in;
do not move existing classes without updating their consumers. Generators refuse
destinations outside the project, symbolic-link paths and vendor/packages/public.
Do not allow untrusted users to modify the project directory while running CLI.

Register a controller explicitly in `routes/web.php`, middleware with the router,
and a generated command using `Console\\Application::register()` in application
console initialization. Generation does not register routes, run seeders or execute
migrations. Review the generated table name and implement the database operations.

## Migration CLI

```console
php fnlla migrate --connection=audit
php fnlla migrate:status --connection=audit
php fnlla migrate:rollback --connection=audit --steps=1
php fnlla migrate --connection=audit --force
```

`--connection=NAME` and `--connection NAME` are equivalent. Rollback accepts one
positive batch count, either positional or `--steps`, never both. Unknown names,
duplicate/unknown options and invalid counts fail before database resolution.
`--help`, `-h` and `help <command>` never execute the requested CLI action.

Writes require `--force` outside local/development/testing, including staging.
This is intentional protection against accidental production migration. Update
deployment scripts explicitly; never derive confirmation from user input. Status
does not run migrations but the existing Migrator may create its ledger table.
Run one migrator at a time; named connections do not provide distributed
transactions or migration serialization. Back up before destructive schema work.

## Database And Private Storage Defaults

Edition 2.2.0 creates no default accounts when `db:seed` runs. The maintainer's
example factory generates an unknown random credential with a least-privilege
role; authentication tests must explicitly supply a test-only password hash.
The factory is not exported to new projects. Keep application seeders intentional
and provision privileged identities through a reviewed application workflow.

`php fnlla db:seed [SeederClass] [--force]` accepts one optional class and uses
the same non-local confirmation boundary as migrations. Production, staging and
unknown environments require `--force`; `--help` performs no writes. Unknown,
duplicate or extra options are rejected before resolving the seeder. A custom
seeder may call external services: the flag is confirmation, not a transaction,
an idempotency guarantee or permission to provision demo users.

Published migration filenames are historical identifiers, not release labels.
Do not rename or replay them to make the database appear newer. Existing users,
sessions, queues, uploads and configuration are not reset by a version change.

`storage/.gitignore` excludes runtime files from both starter profiles, including
future module directories. It is not an access-control mechanism. Serve only
`public/`; verify private POSIX ownership/modes or Windows ACLs. Do not commit or
ship live storage, and do not erase queues or uploads as a cache-cleaning step.

## Bootstrap Cache

`config:cache` accepts finite scalar values, null and nested arrays. Objects,
closures, resources and recursive/overdeep configuration cannot be exported.
`route:cache` requires cacheable route handlers. Rebuilds stage a complete file
beside the destination and replace it without removing the working cache first.
Failed rebuilds preserve the previous file. Windows replacement retries are
bounded; persistent locks result in an error, not an unlink fallback.

New route files include their profile and schema in one atomic publication.
Legacy route files and profile sidecars remain readable. A profile mismatch loads
the configured routes instead. Config and route files are separate publications,
not one transactional release. Deploy mutually compatible code/config/routes.

Cache files can contain secrets. Keep `storage/framework/cache` outside the web
root, writable only by trusted application/deployment identities. New cache files
use private temporary-file permissions. Use the same owner for CLI and PHP or
explicitly provision the required secure group/ACL; do not make them world-readable.
Rebuild after changing configuration or routes. Reload the serving PHP processes
when using OPcache with timestamp validation disabled: CLI invalidation alone
does not establish invalidation of another SAPI's opcode cache.

## Application Identity Contract v1

`AuthManager` exposes `attempt`, `login`, `logout`, `id`, `user`, `check` and `guest`.
`UserProviderInterface` resolves identities with `findById(string|int)` and looks
up credentials with `findByCredentials(array)`. Providers return an account array
or null; they must exclude disabled accounts if disabling should revoke access.
The configured account key must be an integer or a non-empty string. Int/string
representations of the same key compare equally; arbitrary type coercion does not.

`id()` reads and validates the session reference; it does not query the account
store and is not an authorization check. `user()` and `check()` resolve the provider
on every call, observe current privileges and forget missing/mismatched identities.
Corrupt session identity values fail closed. Provider failures propagate to the
application exception boundary rather than authenticating a cached account.

`login()` validates identity and rotates an active session before storing the new
identity. It does not verify a password; call it only after trusted authentication.
`attempt()` verifies the supplied password through the configured provider/hasher.
`logout()` removes application identity and rotates the session, preserving unrelated
session data. `SessionStore::invalidate()` clears all session data and rotates;
`regenerate()` preserves data and reports an active-session rotation failure.
Plain CLI uses in-memory session state unless a PHP session was explicitly started.

Application, developer and customer authentication are different access domains.
Logging into the application must not grant panel privileges. Refreshing developer
settings preserves the current named account; removal of that account locks the
session instead of switching to the first account.

Persistent HTTP sessions enforce timestamp-based idle expiry using
`SESSION_LIFETIME_MINUTES` (120 by default) and absolute expiry using
`SESSION_ABSOLUTE_LIFETIME_MINUTES` (720 by default). Activity refreshes the idle
timestamp, never the absolute start. Expiry or corrupt/future timestamps discard
all session domains, flash data, cart and CSRF state, and rotate the identifier.
Legacy sessions without an activity timestamp use their last rotation as a bounded
fallback; missing or invalid start metadata requires a fresh login. Cookie expiry
and PHP garbage collection are not the authorization boundary. See
[PHP session security](https://www.php.net/manual/en/features.session.security.management.php).

Session storage failures and starting a session after response output fail rather
than pretending to establish persistent authentication. CLI-only in-memory tests
do not establish HTTP guarantees. `tests/SessionHttpTest.php` exercises actual file
sessions, cookies, domain separation, revocation, rotation and expiry across HTTP
requests. Redis implements PHP's strict-ID validation and lazy-write timestamp
contract. A random-token lock is held across validation, read and write. Waiting
is bounded by `REDIS_SESSION_LOCK_WAIT_MILLISECONDS` (2000); leases expire after
`REDIS_SESSION_LOCK_TTL_SECONDS` (60). Reads, writes, deletion and TTL refresh verify
ownership atomically in Lua. A stale process cannot overwrite data or release a
successor's lock. Lock loss/timeouts and storage failures are errors, not fallback
authentication. Keep the lease longer than the maximum session-holding request,
and close sessions before long-running work. Use a dedicated, non-evicting Redis
instance/database and a unique application prefix. This adapter targets one Redis
primary, not Redis Cluster or lossless failover; it cannot guarantee business
transaction consistency across Redis/database failure. See [Redis lock semantics](https://redis.io/docs/latest/develop/clients/patterns/distributed-locks/).

`tests/Integration/RedisSessionTest.php` covers real Redis contention, stale owners,
process termination, concurrent increments and the same HTTP identity contract.
Local acceptance used PHP 8.4, phpredis 6.3 and a Redis 7.4 Windows port; the Linux
CI matrix is separate evidence. File sessions require shared storage or explicit
session affinity when deploying multiple application instances.
Long-lived HTTP workers remain unsupported. Use normal isolated PHP requests.

## Proxy And Request Boundaries

Only explicitly configured `TRUSTED_PROXIES` may supply forwarded identity. The
X-Forwarded-For chain is walked from the nearest hop toward the client, stopping
at the first untrusted address. This prevents a client-supplied left prefix from
overriding the trust boundary. Malformed nearest hops and oversized chains are
rejected as identity sources. Configure ingress to concatenate duplicate XFF
headers consistently. See the [MDN trust-boundary guidance](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For).

Trusted ingress must overwrite X-Forwarded-Proto with exactly `http` or `https`.
Comma-separated or unknown schemes do not establish a secure request. Block direct
untrusted access to the application upstream and strip client-supplied forwarded
headers at your edge. Test your actual reverse-proxy/SAPI topology before release.

Request capture prechecks Content-Length and reads at most the configured body
limit plus one byte before rejecting oversized input with 413. Invalid lengths
produce 400 when they reach PHP; a server may reject framing before application
execution. Configure body limits in the web server/PHP as well: application
capture cannot prevent upstream buffering or multipart processing by the SAPI.

## Queue Capability Boundary

The six-method `QueueStoreInterface` remains compatible with basic historical
jobs only. Registered/versioned jobs, tenant or actor context, `JobContext`,
leases and idempotency require `ReliableQueueStoreInterface`. A legacy worker
rejects reserved versioned-envelope fields and jobs registered by trusted queue
configuration before the handler runs; it never strips those requirements and
marks the record successful.

## Upgrade Checklist

1. Back up code, configuration and data; compare framework-owned file hashes.
2. Preserve `.env`, application routes/classes/views, uploads and database data.
3. Install the matching bootstrap, Console base classes, generators/stubs and
   PhpArrayCache implementation together. Do not cherry-pick only a launcher.
4. Add `--force` to reviewed non-local migration automation and configure canonical
   forwarded protocol headers. Ensure the CLI and serving PHP can read cache files.
5. Rebuild caches, reload PHP where needed, and test application login, removed
   accounts, panel separation, first-run setup and request limits on staging.
6. Validate both installed dependencies and an offline export. Local checks do not
   replace the supported remote PHP/OS/MySQL/Redis matrix or published upgrade tests.
