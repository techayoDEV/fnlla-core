# {{APP_NAME}}

This is a minimal public application built on FNLLA Core. It starts with a
small server-rendered homepage, routes, config, tests and the core CLI surface.
The full FNLLA platform is available separately from `techayoDEV/fnlla`; this
project intentionally stays focused on the open core runtime.

## Local Development

1. Copy `.env.example` to `.env` and set the application name and database credentials.
2. Run `composer install`. Core is a local `packages/fnlla-core` path package;
   the default install stays small and the fallback bootstrap works offline
   before Composer installation.
3. Run `php scripts/test.php`, `php scripts/lint.php` and `php fnlla route:list`.
4. Start `php -S 127.0.0.1:8080 -t public public/router.php` using an available port.

Application code belongs in `app/` (`App\`), routes in `routes/`, templates in `views/`.
The core (`Fnlla\Php\`) is a separate Composer library. Do not modify `vendor/`.
Database access is lazy: the homepage and `/api/health` do not require a database.
The health endpoint is liveness only, not database or deployment readiness.
Composer metadata, `.env.example`, `phpunit.xml`, `phpstan.neon`, `README.md`,
`LICENSE.md` and the `fnlla` launcher remain at root because common PHP tooling
discovers them there by default.

After installation, `php scripts/test.php` runs the bundled smoke harness.
`composer analyse` runs the dependency-light baseline unless the project adds
deeper tools later. Commit `composer.lock`; add heavier development tools only
when the project needs them.

## Product Declaration Validation

FNLLA Core includes the neutral `fnlla.product.v1` and `fnlla.module.v1`
declaration contracts in the bundled package. Validate local declarations with:

```powershell
php fnlla product:validate path/to/product.json --module=path/to/module.json
```

The command prints `fnlla.product-validation-report.v1` JSON and exits non-zero
for schema, type, duplicate, reference, dependency-cycle, capability or
workflow errors. A valid declaration does not prove runtime implementation and
does not install, enable or register modules.

## Product Module Lifecycle

Configure the Product Specification, complete manifest list and trusted
application extension classes in `config/product_modules.php`, then use:

```powershell
php fnlla module:validate
php fnlla module:list
php fnlla module:inspect module-id
php fnlla module:enable module-id
php fnlla module:disable module-id
```

Enable resolves dependencies. Disable refuses an active dependency and is not
uninstall: it preserves data and assets while removing the module's routes from
the live registry. JSON manifests cannot name executable providers, commands,
migrations or destructive removal steps.

## Security Primitives

The generated Core application has neutral role/permission/policy, tenant and
audit services without requiring the full FNLLA product. Single-tenant mode is
the default. Before changing `TENANCY_MODE` to `organization` or `custom`, add
`tenant` middleware to every tenant-owned route and scope repositories, cache,
files, exports and tools with the supplied tenancy adapters. The server-side
user provider, never request or job payload data, establishes membership.

Audit JSON Lines are written below `storage/` by default with allowlisted
before/after fields, redaction and bounded retention. Treat them as
application-owned operational records, not an immutable compliance store.

## Actions And Domain Events

For state-changing application commands, register an `ActionDefinition` and use
`ActionRunner` inside the authenticated tenant scope. The runner enforces
permission/policy and validation, then stores the domain mutation, idempotency
receipt, audit and versioned event outbox in one transaction. Install the two
InnoDB tables from the bundled `resources/events/mysql-action-outbox.sql.example` through
a reviewed migration; Core does not migrate the application implicitly.

Outbox relay runs after commit, so rollback publishes no success. Queued event
listeners inherit actor, tenant, correlation and an event idempotency key.
Delivery remains at-least-once; external effects must use the domain event ID as
a provider/business idempotency key rather than claiming exactly-once behavior.

## Core Updates

The core package is bundled locally under `packages/fnlla-core`. For reviewed
updates, use GitHub or fnlla.com to obtain a newer FNLLA Core source, replace
the local package, update the compatible version constraint in `composer.json`,
then run:

```powershell
composer update techayodev/fnlla-core
```

Commit `composer.lock` and run project tests. Keep the previous deployment for
rollback. When a project needs the full FNLLA platform, start from
`techayoDEV/fnlla` or migrate deliberately after reviewing the product boundary.

## Production

Point the web server document root at `public/`, never the project root. Set
`APP_ENV=production`, `APP_DEBUG=false`, configure HTTPS and `SESSION_SECURE=true`.
Keep `.env`, storage and database backups private. Run migrations explicitly with
`php fnlla migrate`; rollback requires reviewing each migration's `down()` behavior.
Install locked dependencies with `composer install --no-dev --optimize-autoloader`.
The local smoke-test runner has a limited PHPUnit-compatible API, not full PHPUnit.

Register every asynchronous job in `config/queue.php`. Run bounded workers with
`php fnlla queue:work [max-jobs] [max-seconds]` and inspect redacted local state
with `php fnlla runtime:inspect`. Jobs are at-least-once and must recheck their
lease and permission immediately before an external effect; exactly-once delivery
is not promised. Defer queue, event and mail effects from database work until the
outer commit by using the supplied `*AfterCommit` methods.
