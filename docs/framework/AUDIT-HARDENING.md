# Runtime hardening and upgrade notes

These changes ship in Core 2.5.0. They extend the Core runtime without adding a
transport dependency or requiring FNLLA Full. Existing release artifacts remain
immutable. Consumers must install a new verified package to receive the fixes.

## Authentication and deferred event identity

`ActorStatus::active()` is shared by authentication, authorization and tenancy.
Actors without an `active` field remain compatible. An explicit active value
must be `true`, `1` or `"1"`; null, false, zero and arbitrary strings are rejected.
`revoked_at` must be absent, null or the empty string. A revoked/disabled session
is removed when resolving its user. `login()` rejects inactive identities and
`attempt()` returns false. `id()` reads session state and is not an authorization
check. Custom user providers must return the requested identity, not a fallback.

`DomainEventBus::publish()` restores the event's actor, tenant and correlation
through `TenantContextManager::runForIdentity()`. The identity is loaded afresh,
its status and tenant membership checked, and the previous context restored in
`finally`. This applies to direct, legacy outbox and reliable outbox publication,
including queued listeners running without an HTTP session. Caller bypass state
is never inherited. The manager must be registered in the event bus container
for actor-bearing or tenant-bearing events; shared Core bootstrap does this.
`Container::bound()` distinguishes an explicit binding from an autoloadable class.

Listeners use the event and tenant context, not the ambient HTTP session, to
identify the actor. They still own resource permissions and effect idempotency.
Revocation or tenant membership removal blocks domain delivery and uses the
existing retry/quarantine policy. Operators must inspect failed deliveries and
resolve them explicitly. Audit records remain deliverable after actor revocation;
they describe historical facts and do not grant actor privileges.

## Transactions and external effects

`hasActiveTransaction()` checks the existing PDO connection without opening one.
Immediate mail/queue delivery is rejected during managed or externally owned
transactions. `afterCommit()` and deferred delivery also reject a transaction
started directly on PDO because Core cannot observe its eventual commit.
Use `DatabaseManager::transaction()` for work requiring deferred effects.

Rollback failures always unwind callback frames and managed depth. The connection
is invalidated, parent commits are blocked, and callers must explicitly `purge()`
before reconnecting. Do not purge an active managed transaction. An independently
held PDO reference remains the application's responsibility. Named managers own
their own transaction boundaries; this is not a distributed transaction facility.

## HTTP mail

Automatic redirects are disabled, including same-host redirects. Configure the
final approved endpoint. Endpoint user information and URL fragments are rejected.
The configured host allowlist is checked before adding credentials. Responses
larger than 1 MiB fail with a safe error; response bodies are never logged.
A failure response or interrupted connection does not prove the remote service
performed no work. Use application/provider idempotency when retrying delivery.

## Validation and capability shapes

Validation definitions are checked before any nullable shortcut. Unknown rules,
unexpected parameters and invalid numeric bounds throw `InvalidArgumentException`
(a developer configuration error). Supported rules remain required, nullable,
string, array, email, integer, numeric, URL, min, max, boolean, confirmed, file and
in. Required rejects null, empty strings and empty arrays even with nullable.
An explicit `string` rule makes min/max count characters, including numeric text;
numeric rules keep numeric bounds. Rules for omitted non-nullable fields retain
their previous behavior. Review application rules for previously ignored typos.

Capability arrays have at most 4096 items. Registration rejects min/max bounds
above that ceiling. Schema projections include the effective default `maxItems`
and string `maxLength`. String lengths count Unicode characters, with an additional
1 MiB UTF-8 byte safety limit; executor payload limits apply independently to the
whole encoded input. Schema bounds do not waive these resource budgets. Generated
SDKs must continue treating server validation as authoritative.

## Module state and logs

Module state readers share the writer's lock. A completed staging file replaces
the old file with `rename()`; the old state is never unlinked first. Failed
replacement preserves the prior snapshot. A process killed before publication
may leave an unused staging file; readers ignore it. The JSON format is unchanged.
Filesystem durability across power loss is not guaranteed by this protocol.

Exception logging records type, code, source location and bounded trace frames,
without raw exception messages, previous exception text or trace arguments.
Keep correlation IDs and application-defined safe diagnostic codes in context.
General log strings are bounded and common bearer/basic, URL credential and
key/value secrets are redacted, including encoded delimiters. Default sensitive
context keys remain protected even when custom redaction configuration is empty.
Text filtering cannot identify every unlabelled secret: do not pass credentials,
request bodies or arbitrary provider responses to general logging methods.

## Regression gates

`scripts/test.php` includes authentication, outbox context, transaction failure,
validation, schema, logging and subprocess module-publication regressions.
SQLite transaction checks also run when the driver is available. Run
`python scripts/test-mail-http.py` for actual loopback delivery, five redirect
codes, transport errors and response limits. The quality workflow runs this test
on Windows and Linux. Existing MySQL/Redis, export, lint and type-analysis gates
remain required before package promotion.
