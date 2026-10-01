# Operating and upgrading Core applications

[Documentation index](../README.md) · Applies to Core 2.5.0

This guide describes application operations. Maintainers publishing the Core
package use the separate [release procedure](../RELEASING.md).

## Before deployment

1. Install the reviewed dependency lock and verify the selected artifact.
   Record application and Core versions; keep the previous deployment available.
2. Configure the web document root as `public/`. Keep `.env`, logs, session state
   and application storage private. Configure writable paths for the runtime user.
3. Configure HTTPS, trusted hosts, proxy handling and secure sessions for the
   deployment. Keep production debug output disabled.
4. Enforce non-executable upload storage at the web server. The included Apache
   and development-router protections require equivalent rules on other servers.
5. Review application migrations, permissions, tenant policies and feature flags.
   Run tests and actual service checks against isolated representative services.
6. Configure supervised workers and application-owned recovery procedures when
   using queues or the outbox. Preserve receipts and delivery markers across restarts.

## Know what each diagnostic establishes

| Check | Establishes | Does not establish |
| --- | --- | --- |
| `/api/health` in the generated project | The application answers a liveness request | Database, queue or delivery readiness |
| `runtime:inspect` | Installed source/configuration metadata and local capability projection | Permission to publish that metadata or correct business behavior |
| `runtime:doctor --timeout=2` | Bounded checks for supported configured services | Migrations, end-to-end writes or external delivery |
| Application tests | The exercised cases meet their assertions | All production configurations and failure modes |
| Package manifest verification | Files match the selected immutable artifact | Application approval or deployment readiness |

Review diagnostics before sharing them with a person, agent or external system.
Do not add credentials or customer identifiers to schema descriptions or route
declarations. Correlation IDs, safe failure codes and structured logs support
investigation without exposing raw exception messages.

## Command failures and recovery

| Core reason | Meaning | Application response |
| --- | --- | --- |
| `unavailable` | Missing definition, legacy-only operation or unavailable audience | Verify registration/exposure locally; do not reveal hidden operations |
| `unauthorized` | Context, identity, permission or resource access rejected | Reauthenticate or correct authorized policy; never bypass the check |
| `invalid_input` | Input or business validation failed | Correct input; avoid blind retries |
| `invalid_output` | Handler output violates its contract | Fix the handler; command validation occurs before its commit |
| `execution_failed` | Execution could not complete | Investigate safe diagnostics and application state before retrying |
| `post_commit_failed` | Mutation committed; an after-commit callback failed | Recover delivery or retry the same trusted correlation; do not create a new mutation blindly |

These are `ActionException::reason` values. HTTP status mapping belongs to a
transport. Code that obtains a context can fail before entering the executor;
its adapter must also handle authentication failures safely.

## Queues and domain delivery

Queue delivery can repeat. Handlers must recheck authorization and lease ownership
before external effects and use business/provider idempotency where available.
Successful database commit does not prove a webhook, email or remote write
completed. Outbox quarantine and retry tools are described in
[outbox operations](OUTBOX-OPERATIONS.md).

Use Core-managed transactions for deferred effects. An externally managed PDO
transaction cannot safely schedule `afterCommit()` callbacks. After rollback
failure, the connection is invalidated; investigate and purge it explicitly
before reuse. Do not catch the failure and continue a partially failed unit of work.

## Upgrade from 2.4.0 to 2.5.0

Read the [release-specific upgrade notes](../releases/2.5.0.md) and
[hardening contract](AUDIT-HARDENING.md). In particular, review:

- inactive/revoked user handling and custom provider identity consistency;
- trusted tenant restoration for domain event listeners;
- custom validation rule names, parameters and string length behavior;
- transaction ownership and post-commit recovery;
- module-state directory/lock permissions;
- strict capability shapes and public/hidden field declarations.

Back up application data and delivery state, stop affected workers, update the
dependency through Composer and verify the installed package. No new database
migration or queue format conversion is required specifically from 2.4.0 to
2.5.0. Applications enabling command receipts or reliable outbox for the first
time still need their documented schemas.

Resume workers after application, authentication and service checks pass.
Rollback must restore compatible application code and dependency together;
older Core cannot execute newly adopted capability contracts. Returning to 2.4.0
also restores its audited vulnerabilities. Review unresolved external effects
before replaying work.

## Supported boundaries

Core does not provide an application backup policy, hosting configuration,
OAuth/API-token issuer or Developer Panel. Tenant isolation is explicit at data
boundaries. Arbitrary long-lived HTTP workers need separate lifecycle validation.
Service availability, recovery objectives and legal retention policies are
application responsibilities. See [support](SUPPORT.md) and
[security reporting](../../SECURITY.md).
