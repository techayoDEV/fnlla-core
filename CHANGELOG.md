# FNLLA Core Changelog

## Unreleased

## 2.4.0 — 2026-10-01

- Require clean committed sources for stable packages, build from Git blobs and
  verify deterministic archives plus fresh Composer consumers on Windows/Linux.
  Add an exact-commit CI gate and a released 2.3.1 upgrade/crash/rollback drill.
- Extend `runtime:inspect` with versioned routes, source provenance, public
  contract signatures, dependency versions and configuration origins. CLI
  inspection no longer connects a queue for counts. Add bounded, redacted
  `runtime:doctor` probes and optional explicit `openapi:export` (OpenAPI 3.1.1).
- Add optional leased MySQL outbox delivery, poison quarantine, bounded retry,
  `outbox:work`, `outbox:status` and explicit `outbox:retry`. Enabling reliable
  delivery requires a side-table migration and coordinated publisher restart.
- Separate Redis ready and delayed work, migrate legacy delayed entries during
  reservation and reject false-empty scans. Stop old workers before upgrading;
  older versions cannot consume the new delayed sorted set.
- Publish cache/reliable-queue adapter conformance checks; add Windows quality
  jobs, real concurrent outbox checks and a synthetic four-task agent evaluator.
  See `docs/framework/DEVELOPER-WORKFLOW.md` and `OUTBOX-OPERATIONS.md` for limits.
- Expand shared maintainer/application agent guidance with ownership, runtime
  boundaries and verification paths. Add Claude Code and Copilot entrypoints to
  the repository and new Core applications; no runtime API changes.
- Renew queue processing idempotency markers with their leases, rejecting lost
  claims; add long-running job regression coverage for file and Redis stores.
- Add optional atomic fixed-window rate-limit admission and use it in HTTP
  throttling. Preserve the existing cache interface and legacy counter methods.
- Lock file-cache reads and mutations consistently, publish complete entries
  atomically, and fail closed on counter corruption or persistence failure.
- Exercise Apache/PHP upload denial including PATH_INFO, require symlink coverage
  in Linux CI, and expose an explicitly unapproved local-review artifact mode.
  See `docs/framework/CONCURRENCY-AND-RATE-LIMITS.md` for upgrade impact.
- Derive public upload names from detected MIME types, validate direct disk
  uploads, and reject nested symlinks; generated projects deny active upload
  paths through Apache and the PHP development router. See
  `docs/HTTP-UPLOAD-VALIDATION.md` for compatibility and deployment impact.
- Quarantine malformed Redis pending jobs and expired leases without blocking
  valid work queued behind them.
- Add development-only PHPStan analysis and an isolated MySQL/Redis CI gate,
  including concurrent process reservation and durable idempotency checks.
- Keep the reliable queue store type explicit inside the worker and exclude
  generated analysis cache files from PHP lint.
- Preserve Core exception logging when the optional full-FNLLA issue tracker is
  absent, and report a clear error if panel branding is requested without its
  optional extension.
- Document 2.4.0 public contracts, compatibility and operational upgrade steps.

## 2.3.1

### Release Summary

- Recognize pristine Core-owned template files during `fnlla:upgrade` so a
  freshly generated Core project can migrate to the full Framework without
  false project-content conflicts.
- Preserve application-owned paths and fail visibly when a Core-owned target
  was modified locally.
- Keep upgrade application inside the existing transactional rollback and
  recovery boundary.

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
