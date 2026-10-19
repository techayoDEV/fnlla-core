# Upgrading an existing Core application

Core is an independently versioned dependency. Updating Core is different from
`fnlla:upgrade`, which converts a Core application into the commercial FNLLA
Framework. A Framework application must use the Core version accepted by its
Framework release; do not bypass its canonical Composer package definition or
patch the installed `vendor/` or offline package tree.

## Select and inspect the target

The Core 3 source candidate has an explicit configuration migration. Read
[3.0.0-rc.1 migration and limits](../releases/3.0.0-rc.1.md) before testing it;
the candidate does not replace published stable Core 2.8.1.

Choose an immutable, approved Core release and read every intervening release's
compatibility notes. Start with the installed `composer.lock`, not the latest
online documentation. Check the target PHP/extensions, custom adapter contracts,
application bootstrap and optional features. A source checkout with unreleased
changes is not an available package upgrade.

| Starting point | Upgrade boundary |
| --- | --- |
| Core 2.2.4 basic queue adapters | The original six-method interface remains supported for basic queue use. Reliable leases, tenant/actor context and advanced metadata require the optional reliable-store contract; they must fail before mutation on an old adapter. |
| Core 2.3.1 persistent work | Preserve receipts, published outbox state, pending jobs and delayed work. The maintained service drill verifies this published baseline through current source, crash recovery and reconciled rollback. |
| Core 2.4.0 and later delayed queues | Preserve sorted-set delayed work and restart coordinated workers. Rollback must retain the original store format rather than unconditionally converting it to the older list format. |
| Opt-in reliable outbox and snapshots | Read the corresponding schema/operation contracts before activation. Installing new code does not enable a feature, create permissions or establish recovery readiness. |
| Core 2.8.1 | Its published maintenance patch retains runtime contracts from 2.8.0. New source-only fixes need a separate release; do not rewrite 2.8.1. |
| Unknown or untracked engine copy | Recover its original package/source identity first. Do not invent a baseline from modified application files or assume that every `src/` file belongs to Core. |

This is a compatibility contract, not a claim that arbitrary application code,
third-party adapters or every historical deployment has been tested. Each new
Core release must preserve the published interfaces or declare a major migration.
Framework Workspace naming and account/data migration belong to Framework.

## Rehearse the update on an isolated copy

1. Record the old and target package identities, source commits and checksums.
   Retain `composer.json`, `composer.lock`, reviewed offline packages and the
   application's own changes. Check requirements without changing the system's
   global PHP or a running application's dependency tree.
2. Stop and drain HTTP writers, queue workers, schedules and external writers.
   Capture a compatible backup of code, configuration, database, uploads,
   private files, encryption keys and external queue/session state. Verify restore.
3. Stage the reviewed Composer dependency change in a separate release directory.
   Resolve only intended dependencies, review the lock diff, then use
   `composer install` for repeatable deployment. Preserve application routes,
   providers, policies and configuration; do not regenerate over the application.
4. Apply only required, reviewed schema/data migrations. Reliable-outbox side
   tables and optional adapters have their own activation contracts. Never clear
   pending work, receipts or audit history as upgrade housekeeping.
5. Invalidate generated bootstrap caches and rebuild them with the new code and
   deployment configuration. Keep application cache, sessions, queues and logs
   unless a specific documented migration requires controlled conversion or
   session invalidation. Recheck credential rotation and revocation with cached
   configuration.
6. Run application tests, PHP lint/static analysis and configured service checks.
   Exercise custom adapters, authorization/tenant denials, pending/delayed jobs,
   outbox idempotency and recovery. `/api/health` alone is insufficient.
7. Switch the release, restart long-running workers/OPcache and verify the
   application's critical operations before restoring writers. Retain source-bound
   results and a tested rollback route outside the product tree.

## Recovery and rollback

A failed code-only update must restore the previous dependency set and matching
lock. After schema or storage-format changes, use a compatible data snapshot or
the reviewed inverse migration as well. Restoring an old ZIP does not undo a
database migration or an external effect.

Never restore old pending outbox state over effects already delivered. Preserve
publication markers and reconcile the actual delayed-queue format while workers
are stopped. Deny stale reservations and verify that recovered work executes
once per idempotency contract; external delivery remains at-least-once.

For interrupted coordinated updates use the retained recovery capsule before
another update. Keep traffic blocked if restore or verification fails.

## Maintainer regression gate

`php scripts/test.php` exercises published queue compatibility, cached credential
rotation/revocation, path ownership and transactional recovery. Linux must run
with `FNLLA_REQUIRE_SYMLINK_TESTS=1`; report Windows privilege skips honestly.

With dedicated MySQL/Redis configured as documented in the service workflow:

```sh
python scripts/test-upgrade.py
```

The drill verifies checksum-pinned original **2.3.1 and 2.8.1** packages through
current source, interruption/recovery and rollback. Both are required; a missing
download or failed phase fails the gate. Each attempt uses a random database/
Redis prefix and cleans only its synthetic records. It never targets customer
services. Retain these anchors as releases advance and add an anchor when a
new persistence boundary is introduced.

See [runtime contracts](RUNTIME-CONTRACTS.md),
[outbox operations](OUTBOX-OPERATIONS.md),
[concurrency and rate limits](CONCURRENCY-AND-RATE-LIMITS.md) and
[application snapshots](APPLICATION-SNAPSHOTS.md) for feature-specific limits.
