# Resilience design and current-state review

## A. Current state

The inventory covers the HTTP kernel, routing/pipeline, container/providers,
bootstrap, views, database/cache/session, logging/events, console, tests and
Core/Full export composition. Core 2.5.0 owns these runtime contracts; Full
3.4.0 and the website's Full 3.3.0 consume immutable Core 2.5.0. Existing dirty
navigation, documentation and website changes are outside this work.

`Application::handle` already catches dispatch and serialization errors, adds
request IDs, suppresses HEAD bodies and isolates request observer failures.
`ExceptionHandler` redacts ordinary production exceptions and survives failed
error views, but HTTP exception messages are currently exposed and a failed
error view changes the original status to 500. Bootstrap/provider exceptions
occur before this boundary. Providers register in two passes; they have no
declared optional failure boundary. Database connections are lazy, but connection
failures are generic RuntimeExceptions. SQL errors must remain defects rather
than indiscriminately becoming dependency outages.

File cache has locking, atomic staging, JSON serialization and symlink defenses.
Redis supports atomic rate-limit admission. These are also security stores:
changing the global cache to fail open would weaken throttling and revocation.
Session, authentication, Actions, audit and outbox must retain fail-closed
semantics. Views already clean output buffers on exceptions. There is no public
LKG response store, generic dependency circuit or read retry policy.

`RuntimeDoctor` already performs isolated, time-bounded read-only readiness
probes. `RuntimeInspector`, Dispatcher, Logger and RequestLifecycleObserver are
existing extension points. Full adds request metrics, authenticated operations
health, password-protected maintenance and an update read gate. Those controls
must not be bypassed by page fallback. Core starters support Apache; deployment
does not presently supply a PHP-upstream emergency fallback. DNS, host, proxy
and network availability cannot be implemented in PHP.

## B. Proposed architecture

Core owns provider-neutral dependency classification, request-local degraded
state, structured Logger/Dispatcher events, bounded explicit read retries,
file-locked circuits, optional provider isolation, a separate disposable-cache
failover wrapper, conservative public-page middleware and bounded readiness
checks. Reuse RuntimeDoctor for active health probes; do not probe dependencies
on ordinary requests. Full owns compatibility adapters and emergency entry/CLI
wiring. Applications choose public routes and author approved static emergency
copy. No provider SDK, parallel registry or new runtime dependency is needed.

```text
trusted host / CORS / maintenance / authorization boundary
  -> explicit public page allowlist + anonymous GET/HEAD eligibility
  -> fresh public response OR normal route render
  -> successful explicitly public stateless HTML => atomically replace LKG
  -> typed dependency failure / 502,503,504 => bounded LKG OR original error
  -> request ID / instrumentation / HEAD finalization

PHP cannot boot => application entry catches failure => static generic 503
PHP cannot execute => configured nginx/Apache/CDN => emergency artifact
```

Public caching is opt-in, paths exact, queries/cookies/Authorization/ranges
excluded, response must explicitly declare public safety, status 200 HTML with
no Set-Cookie/private/no-store/Vary/nonce or active session. Cache only a narrow
safe header list. POST, CSRF forms, login, finance, personalized pages, package
downloads and health are never eligible. A route declaration is a developer
security contract, not an automatic proof of public content. No synchronous
failure may overwrite LKG. Refresh is synchronous after fresh TTL; no SWR is
claimed without a supported worker. Stale responses carry Age and degraded
metadata, remain no-store at the edge and do not turn failed transactions into
successful responses.

Circuits use a short file-lock state transition on a shared filesystem (local
single-node default), release the lock before I/O, and lease one half-open
probe. A failed circuit store rejects calls conservatively. Retries require an
explicit idempotent-read declaration, bounded attempts and total sleep budget;
never wrap ActionExecutor, mail, queue delivery or payments automatically.

Liveness checks only the process. Readiness uses the existing isolated doctor
with a brief snapshot and declared critical/degradable/optional services. Public
responses expose only healthy/degraded/unavailable and use 503 for unavailable.
CLI can show safe diagnostic codes, never credentials/hosts/exception text.

## C. Implementation phases

1. Typed failure/state/events, safe error rendering, provider boundaries and
   health based on existing RuntimeDoctor. Keep production debug disabled.
2. Explicit disposable-cache failover and anonymous public-page LKG middleware,
   opt-in configuration, security exclusions and atomic persisted snapshots.
3. Shared-file circuit leases, bounded read retries and component callback
   boundaries with developer-defined fallback; deterministic failure tests.
4. Static export from already approved public LKG, generic emergency/maintenance
   artifacts, early entry/CLI integration, nginx/Apache/CDN examples, consumer
   and starter tests, documentation and final evidence.

## D. Risks and mitigations

* Privacy/cache poisoning: opt-in routes and public response marker, strict
  anonymous/stateless checks, no query/header-dependent variants, no cache of
  auth/forms/prices, trusted-host middleware before cache, no active session.
* Security-store failover: separate disposable wrapper, never rebind global
  authentication/rate-limit/cache state; failure is not permission.
* Hidden defects: only explicitly typed dependency failures are component
  fallback candidates; generic programming errors propagate normally.
* Bootstrap rollback: isolate explicitly optional providers after normal
  provider boot, restore container bindings on their failure. External effects
  cannot be undone; provider initialization must be side-effect-free/lazy.
* Load/performance: no remote probes on traffic paths, bounded sleeps/leases,
  one LKG lookup on eligible paths, no successful-event logging by default.
* Compatibility: default public caching/health interception disabled; immutable
  dependencies remain unchanged. New Full adapters are capability-gated;
  source-candidate integration is test evidence, not a published upgrade.
* Operations: stale content expires, emergency artifacts require regeneration
  and trusted deployment, filesystem circuits only coordinate nodes sharing
  that filesystem, server examples need environment-specific validation.
* Availability: cold cache cannot restore unknown content; early generic 503
  and static emergency content remain possible. PHP cannot cover DNS/host loss.

Implementation is authorized by the supplied task after this analysis; release,
push, deployment and live commercial changes remain separate operations.
