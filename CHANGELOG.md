# FNLLA Core Changelog

## 2.3.0

### Release Summary

- Promote the exact validated RC.2 runtime and public API to stable without
  behavioral changes.
- Preserve Core package bytes and manifests through direct and Framework
  exports, including clean Composer consumers.
- Retain the compatible legacy queue-store contract while reliable envelopes
  fail closed unless the explicit reliable capability is implemented.
- Keep long-lived HTTP workers unsupported and production RPO/RTO unclaimed.

## 2.3.0-rc.2 — Local candidate

- Preserve package bytes and manifest integrity when the Core exporter
  customizes application-owned templates.
- Generate directly installable prerelease projects with an exact bundled Core
  version and matching Composer stability policy.
- Reject registered or versioned reliable envelopes when a six-method legacy
  queue store reads or retries them, before the handler or any external effect.
- Export Core-specific product guidance containing only commands available in
  the Core profile.
- Keep this candidate local-only with `release_approved=false`; no artifact,
  tag, package or official channel is published by this change.

## 2.3.0-rc.1 — Local candidate

- Restore the published Core v2.2.4 six-method `QueueStoreInterface` contract
  for third-party stores while retaining metadata, reservation ownership,
  lease renewal and idempotency through the explicit
  `ReliableQueueStoreInterface` capability.
- Add a separate-process v2.2.4 consumer fixture proving the two-argument
  legacy push path and fail-before-mutation behavior for unsupported context.
- Keep this candidate local-only with `release_approved=false`; publication,
  official-channel installation and external exact-commit CI remain separate
  gates.

## 2.3.0-alpha.9 — Unreleased candidate

- Add server-derived `ActionContext`, a permission-first Action registry/runner,
  transactional idempotency receipts and audit/domain-event outbox storage.
- Add `fnlla.domain-event.v1`, synchronous/queued versioned listener registration
  and post-commit relay with explicit at-least-once/idempotency limits.
- Export the action configuration, reference MySQL DDL and public event schema;
  no schema is installed implicitly.

## 2.3.0-alpha.7 — Unreleased candidate

- Add Core-only deny-by-default roles, permissions, resource policies and
  guarded role assignment while preserving existing Gate callbacks through an
  explicit migration map.
- Add server-resolved `none` / `organization` / `custom` tenant context,
  revalidated queue context and explicit repository/cache/file/export/tool
  isolation adapters with reset after every HTTP or job work unit.
- Add the versioned `fnlla.audit-event.v1` contract and a locked,
  retention-bounded JSON adapter with before/after allowlists and sensitive-key
  redaction.

## 2.3.0-alpha.5 — Unreleased candidate

- Add the Core-owned `ProductModuleRegistry` and explicit
  `module:list|inspect|validate|enable|disable` lifecycle commands on the same
  `fnlla.module.v1` contract used by `product:validate`.
- Resolve dependencies, fail on service/route collisions, keep Product Modules
  separate from Developer Panel flags and preserve data/assets across
  disable/re-enable. Module routes are deliberately excluded from the
  application route cache so a disabled privileged endpoint cannot remain live.

## 2.3.0-alpha.4 — Unreleased candidate

- Add the standalone `product:validate` command and Core-owned
  `ProductValidator` with deterministic `fnlla.product-validation-report.v1`
  output.
- Validate `fnlla.product.v1` and declarative `fnlla.module.v1` inputs,
  including types, references, duplicates, dependency cycles, capabilities and
  workflow transitions without implementing module lifecycle behavior.

## 2.3.0-alpha.3 — Unreleased candidate

- Add the neutral `fnlla.product.v1` intent contract, separate implementation-
  facts, drift and derived-graph schemas, explicit historical-blueprint
  migration and a synthetic two-tenant property-maintenance example.

## 2.3.0-alpha.2 — Unreleased candidate

- Add scoped DI lifetimes, versioned queue envelopes, per-job context reset,
  lease renewal, durable idempotency and bounded graceful workers.
- Add post-commit queue/event/mail boundaries and redacted runtime inspection.

## 2.3.0-alpha.1 — Unreleased candidate

This local candidate establishes FNLLA Core as the single editable runtime
source consumed by FNLLA Framework. It adds runtime identity configuration,
SemVer-aware project export and deterministic package metadata, provenance,
manifest and ZIP generation.

The candidate is not release-approved or published. The previously published
local tag baseline remains `v2.2.4` until the Core release owner approves an
exact clean commit and immutable artefact.

### Environment

- PHP `^8.3`;
- required extensions: fileinfo, JSON, mbstring, PDO and session;
- PDO MySQL is required for the maintained MySQL connection;
- Redis is required only for Redis cache, queue or session drivers.

### Distribution

Local artefacts set `release_approved=false`. They include
`FNLLA-PACKAGE.json`, `FNLLA-PROVENANCE.json`, `FNLLA-MANIFEST.sha256`, a
reproducible ZIP and an archive checksum. Publication remains a separate action.

## 2.2.4

Published baseline before the K-02 single-Core candidate.
