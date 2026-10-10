# Runtime reliability and upgrade contracts

These contracts are available in released Core 2.7.0, verified by exact-commit CI. Core 2.6.0 artefacts remain unchanged; upgrade the
installed package before relying on these contracts in an application.

## Commit and delivery

`DatabaseManager::transaction()` runs every independently registered
`afterCommit()` callback in registration order. Failures are collected in
`PostCommitCallbackException::failures()` using one-based callback positions;
the first original exception remains `getPrevious()`. The summary contains no
callback message. SQL is already committed: do not retry the entire mutation.
Put dependent effects in one callback and use a durable outbox for critical work.

Actions retain receipts, audit and events in their transaction. An opportunistic
outbox relay cannot replace the committed result with a delivery error. Direct
legacy `publishPending()` reports partial failure after attempting later rows;
it never acknowledges failed records. Legacy mode still has no quarantine or
fairness guarantee for a backlog larger than its batch. Migrate production
delivery using [outbox operations](OUTBOX-OPERATIONS.md).

New Core and Full starters default to reliable outbox with
`actions.publish_after_commit=false`. Install the Action schema in an application
migration and supervise `outbox:work`; otherwise pending effects are not delivered.
Existing configurations keep their mode and inline relay unless explicitly
changed. Stop publishers, install the delivery schema, configure
`ACTION_RELIABLE_OUTBOX=true` and `ACTION_PUBLISH_AFTER_COMMIT=false`, then start
the supervised worker. No request silently installs or migrates tables.

## Safe private diagnostics

Bind `Exceptions\ExceptionReporterInterface` through the existing container.
`report(Throwable, array $context = [], ?Request $request = null)` is private;
implementations must exclude messages, inputs and trace arguments. The default
reporter records class, file, line, safe trace frames and correlation. Unexpected
Action failures are reported before translation to their unchanged safe reason.
A failed custom reporter falls back to safe logging and cannot change execution.
Full supplies its tracker adapter; Core has no knowledge of commercial classes.

New failed queue records use allowlisted `last_error` codes. Their existing job ID
links to private diagnostics. The envelope and lease/idempotency format remain
unchanged. Historical error strings are not rewritten: review their access,
backups and retention because they may contain sensitive provider text.

Uncaught exceptions and shutdown failures exclude raw text. Native PHP logging
after bootstrap defaults off because it bypasses redaction. Explicit
`LOG_NATIVE_ERRORS_ENABLED=true` writes sensitive engine diagnostics to
`LOG_NATIVE_ERROR_PATH`, defaulting to private `storage/logs/native-errors.log`.
Use a separate destination, restricted access, rotation and retention; it is not
the ordinary safe application log. PHP/server failures before bootstrap still
require deployment-level configuration. An uncaught CLI failure exits nonzero.

## File-cache locks and maintenance

The default `cache.file_lock_protocol=striped` uses 256 permanent lock files,
including misses. Cache entry serialization and paths remain compatible.
Different keys can share a lock; counters remain atomic. `clear()` and expiry
pruning never unlink live locks.

**Drain HTTP requests and stop every queue/CLI worker before changing protocols
or rolling back.** Released Core 2.6.0 uses per-key locks and cannot coordinate
with striped workers. `FILE_CACHE_LOCK_PROTOCOL=legacy` lets the new runtime use
the old protocol during a controlled rollout; it retains unbounded cardinality.
Only remove historical per-key locks while all users of that directory are
stopped. A fresh private directory is also a valid cutover. Never mix protocols.

`php fnlla cache:clear --expired` removes only expired valid file-cache entries,
under their locks, without firing `cache.cleared` or deleting live generated
artefacts. The optional `PrunableCacheStoreInterface` preserves custom store
compatibility. Redis already handles TTL; stores without this capability reject
the pruning option. Schedule pruning according to directory size; this is an
explicit linear maintenance scan, not request-time housekeeping.

## Export baseline

New generators record `.fnlla/export-baseline.json`. Upstream generator adapters
can add known pristine files through `ProjectExportBaseline::record()` only at
creation time. Never refresh this receipt over a user's existing application.
It is local ownership evidence, not remote identity or an authorization token.
Upgrade planning protects actual edits, rejects corrupt/aliased receipts and
normalizes CRLF/LF only for declared text types; binary bytes remain exact.
Older projects without the receipt retain installed-template comparison and
manual conflict review. Check both direct Core and Full-adapted Core exports.
Core-to-Full upgrade retains an existing `config/actions.php` even when pristine:
delivery mode, table identities and schema migration policy belong to the app.
