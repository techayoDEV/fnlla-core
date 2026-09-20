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

1. Use PHP 8.3, copy `.env.example` to `.env` and review local settings.
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
