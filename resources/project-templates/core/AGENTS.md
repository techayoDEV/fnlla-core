# {{APP_NAME}} product guidance

This repository is an application built on FNLLA Core. This file does not grant
access, approve releases, authorize production actions or replace application
authentication and authorization.

## What

Product code belongs in `app/`, routes in `routes/`, configuration in `config/`
and private runtime data below `storage/`. `packages/fnlla-core` and `vendor/`
are dependency code, not application-owned customization points.

## Why

Keep product intent and implementation reviewable while preserving the Core
package integrity and explicit application ownership.

## Quick start

1. Use PHP and extensions required by `composer.json`; copy `.env.example` to
   `.env` when it does not exist and review local settings.
2. Run `composer install` and commit the resulting `composer.lock`.
3. Run `php scripts/test.php`, `php scripts/lint.php` and `php fnlla route:list`.
4. Start the local server described in `README.md`; `/api/health` is liveness,
   not proof that a database, queue, mail or external provider is ready.

## Real example

For a tenant-owned service request, resolve the actor and tenant server-side,
authorize a declared Action through a policy, persist its event and audit trail,
then test wrong-role, wrong-tenant, stale-version and replay denial.

## Security

- Never trust a submitted role, tenant, owner or approval identifier.
- Do not ship developer or demo credentials.
- Keep secrets, customer data and private context outside source control and AI.
- Client acceptance is not release, deploy, migration or payment approval.

## AI guidance

AI is optional. Prefer versioned local source, `docs/framework/`, application
tests and `php fnlla runtime:inspect`. AI output grants no identity, permission,
tool authority or permission to export private tenant context.

## Common mistakes

- Editing `packages/fnlla-core`, `vendor/`, generated caches or dependency locks
  instead of using application-owned files and reviewed extension points.
- Treating a redirect, queued job or empty test as completion evidence.
- Describing a local fixture as live AI, payment, deployment or recovery.

## Ownership and tests

Declare application Actions and policies explicitly. Test positive access and
negative authorization boundaries. Core has no Developer Panel or the full
FNLLA project-identity and delivery-acceptance command surface.

## Understand the installed engine

This is an application repository, not the Core maintainer workspace. Read
`composer.json`, `composer.lock` when present, `README.md`, the relevant local
contract and its actual implementation before proposing an API. The installed
package version determines available features; a newer upstream document does
not upgrade this application. Do not assume Laravel or full-FNLLA APIs exist.

| Application surface | Where to work |
| --- | --- |
| Request entry | `public/index.php`, `bootstrap/app.php`, `bootstrap/router.php` |
| Product behavior | `app/`, `routes/`, application services and policies |
| Configuration and extension registration | `config/`; use existing container/provider bindings |
| Private runtime state | `storage/`; exclude secrets and customer data from source and agent context |
| Framework contracts | `docs/framework/`, installed `Fnlla\Php\` classes; inspect without patching dependencies |
| Verification | Application `tests/`, `php scripts/test.php`, `php scripts/lint.php` |

`bootstrap/common.php` loads the installed engine; the application root and
engine root can differ. Extend application-owned services and policies instead
of copying runtime classes. Upgrade dependencies through Composer and the
documented upgrade path, preserving immutable bundled package contents.

## Runtime boundaries

- Resolve actor and tenant server-side and check resource ownership in policies.
  Enabling tenancy does not automatically scope arbitrary SQL queries.
- Keep Action receipt, audit and outbox writes within their transaction. Use
  after-commit delivery; a rollback must not produce external side effects.
- Queue work can repeat. Make handlers idempotent, use only registered job types
  and preserve lease ownership. Do not assume exactly-once delivery.
- Preserve upload validation, safe paths and web-server execution denial.
- Product/module JSON declares contracts; it does not authorize actors or execute
  arbitrary class names. Register trusted extensions in application configuration.
- Use an installed capability only after checking its contract. In particular,
  custom queue/cache adapters must satisfy the features the application uses.

## Working agreement for coding assistants

Before editing, run `php fnlla runtime:inspect` to read the installed route,
contract and version map. `runtime:doctor` probes configured services with time
limits; `openapi:export` documents only explicitly declared route contracts.
Read the installed Core `docs/framework/DEVELOPER-WORKFLOW.md` and
`OUTBOX-OPERATIONS.md` before changing delivery or API contracts. Diagnostics do
not grant authorization, approve a change or prove business correctness.

`AGENTS.md` is the shared project guidance. `CLAUDE.md` imports it; the Copilot
bridge refers to it. Keep business-specific instructions here or in scoped
application instructions, and keep tool entrypoints thin.

Inspect the current diff and preserve unrelated work. Continue local edits and
tests within the requested task; never infer publication or production approval.
For each change, inspect callers, update application docs when behavior changes,
and run the relevant checks. Report the installed version, checks actually run,
skips and limitations. A fixture or liveness endpoint is not integration proof.
