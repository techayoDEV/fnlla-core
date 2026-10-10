# Reliable outbox operations

This is an optional Core 2.4.0 capability. `ActionStoreInterface` and the
legacy receipt/outbox tables stay compatible. `ReliableOutboxStoreInterface`
adds delivery claims, ownership checks, acknowledgement, retry and status.
`DatabaseActionStore` implements it for MySQL 8+ using transactions and
`FOR UPDATE SKIP LOCKED`; custom stores must implement the same guarantees.

## Upgrade and enable

Core 2.7.0 starters default to reliable asynchronous delivery; maintained older
application configurations are not silently switched. Review
[runtime reliability](RUNTIME-RELIABILITY.md). Set
`ACTION_PUBLISH_AFTER_COMMIT=false` for supervised delivery rather than relay
work in a request. A relay failure no longer replaces a committed Action result.

1. Deploy a reviewed new immutable Core package. Stop legacy publishers and
   workers before changing delivery mode; never mix old/new publishers.
2. In an application migration, outside an active transaction, resolve
   `DatabaseActionStore` and call `installDeliverySchema()`. For new applications,
   `installSchema()` creates receipts, outbox and delivery tables together.
3. Configure `actions.delivery_table` (default `fnlla_outbox_deliveries`). Use a
   distinct table for every distinct outbox; do not share it between databases'
   logical outboxes. Existing receipt/outbox rows are not rewritten.
4. Set `actions.reliable_outbox = true` (`ACTION_RELIABLE_OUTBOX=true` in the new
   Core template). Rebuild configuration cache and restart all publishers.
5. Run `outbox:status`, then a bounded `outbox:work` invocation and verify actual
   application effects. Run it repeatedly through your process supervisor or
   scheduler. `outbox:work` is one batch, not a forever-running daemon.

The opt-in makes `OutboxProcessor` use reliable delivery and rejects direct
legacy `pending()`/`markPublished()` publishing through `DatabaseActionStore`.
Schema creation is explicit; request handling never silently migrates a database.
Disable/revert only after stopping workers and reconciling pending, failed and
leased messages; legacy mode has no equivalent quarantine/backoff enforcement.

```console
php fnlla outbox:work --limit=100 --max-seconds=30
php fnlla outbox:status
php fnlla outbox:retry message-id --confirm
```

CLI access inherits the operating-system user's authority. Do not expose these
commands as unauthenticated web endpoints. Retry targets one failed unpublished
message, uses compare-and-set, increments a retry counter and records its time.
It never resets a published message. Decide whether external effects can safely
repeat before confirming a retry; counters do not identify a human approver.

## Delivery guarantees and limits

- Claim tokens and deadlines prevent another live owner from acknowledging a
  delivery. Expired claims can be reclaimed; stale acknowledgements fail.
- Invalid JSON, invalid event structure and unknown kinds are quarantined.
  Other failures retry with capped exponential delay and jitter, then fail.
- Defaults: five attempts, 60-second lease and five-second base retry delay,
  configurable under `actions.outbox`. Attempts are clamped to 1–100; lease and
  retry delay to 1–3600 seconds. Status returns at most 100 messages through CLI.
- Status contains identifiers, state, counts, timestamps and allowlisted error
  codes. It excludes payloads, claim tokens and exception/driver text.
- Delivery runs outside application transactions. Rolled-back outbox inserts
  cannot be delivered. The CLI opens/closes a container scope per message.
- `--max-seconds` is checked between messages. It cannot interrupt a blocking
  listener; bound listener I/O and set a lease longer than maximum processing.
  This worker does not renew a lease during a synchronous callback.
- A crash after an external effect but before acknowledgement may repeat the
  effect. Listeners and audit destinations must use the message/event identity
  for idempotency. This is at-least-once delivery, not exactly-once effects.

Claiming uses the database clock. The side table retains published state; design
an application retention policy that preserves required audit/replay evidence.
The service suite checks independent and concurrent claimers, expiry, stale
tokens, poison messages, rollback, bounded retries and explicit retry.

## Redis ready and delayed work

Before upgrading the publisher, review the
[persisted-event identity requirements](AUDIT-HARDENING.md). Domain listeners now
run in a freshly authorized event context, and revoked actors block delivery.
Historical audit messages remain deliverable after revocation.

The reliable Redis queue now uses a ready list plus a delayed sorted set at
`<prefix>delayed`. Retry delays are scored by their availability timestamp. Due
work is promoted atomically; future work does not rotate through the ready list.
Legacy future entries are migrated lazily during reservation. Counts include
ready, delayed and reserved work, retaining the existing public count semantics.

Lua batches bound work inside Redis. Reservation continues across scan batches;
if its two-second scanning budget is exceeded it raises an error rather than
claiming that a nonempty ready queue is empty. Malformed entries are quarantined.
Large legacy backlogs may require repeated supervised worker invocations.

Stop all old workers before upgrading this queue format. Older versions cannot
see the delayed sorted set; rollback requires explicitly reconciling that set
with the old format. Back up queue state and keep prefixes isolated per app.
