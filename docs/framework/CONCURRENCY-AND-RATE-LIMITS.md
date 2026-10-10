# Queue, Cache And Request Concurrency

These changes ship in Core 2.4.0; older immutable packages remain unchanged.

## Long-running jobs

The built-in file and Redis queue stores renew the processing idempotency marker
with the job reservation. The marker initially expires with the actual lease,
and renewal never shortens an existing lease or its processing marker,
including a lease renewed before `beginIdempotent()`. Renewal and completion
reject a missing, expired or differently owned processing marker. An expired
worker cannot recreate its marker by renewing the lease.

Redis updates the lease and marker in one Lua execution. The file store holds its
queue lock across both writes and persists the marker first. An interrupted file
write can delay another attempt until expiry; it must not extend a live lease
without its duplicate guard. This remains at-least-once delivery. External
effects still need business or provider idempotency keys.

Drain or restart workers when upgrading. Call `JobContext::renewLease()` before
expiry and `assertLeaseOwned()` before external effects. The public queue-store
interfaces and job envelope version are unchanged.

## Atomic request admission

`RateLimiter::acquire($key, $maximum, $seconds)` returns:

```php
['allowed' => true, 'attempts' => 1, 'retry_after' => 60]
```

`ThrottleRequests` uses this operation to decide admission and set response
headers. The first admitted request starts a fixed window. Denied requests do
not increase its counter or extend its expiry. Zero or negative limits deny all
requests. Storage failures throw before the application handler runs.

The additive `RateLimitStoreInterface::consume()` capability is implemented by
the file and Redis stores. `TenantCacheStore` delegates it through the same tenant
key scope and rejects an underlying store without that capability.
`CacheStoreInterface` is unchanged: existing custom cache implementations still
work for caching. A custom store used for HTTP throttling must implement the new
atomic capability; there is no non-atomic fallback.

The existing `hit()`, `attempts()`, `tooManyAttempts()` and `availableIn()` methods
retain their legacy counter behavior. A check followed by `hit()` is not atomic
admission. New admission counters use the `rate-limit:` namespace; do not mix the
legacy methods with `acquire()` for one decision. `clear()` clears both kinds of
counter. During upgrade, new admission windows start fresh; configure edge
limits if preserving the previous window is required.

## File-cache persistence

Core 2.6.0 uses stable per-key locks. Core 2.7.0 uses a fixed
256-lock pool by default; drain all old workers before switching protocols.
See [runtime reliability](RUNTIME-RELIABILITY.md) for legacy rollout and pruning.
Reads, writes, increments, expiry deletion and explicit deletion share locks.
Writes stage a complete entry in the cache directory and rename
it over the old entry. Readers cannot observe a partially written value. Missing
locks, failed writes and unreadable entries raise exceptions. Corrupt numeric or
admission counters fail closed; ordinary cache reads retain cache-miss handling
for invalid serialization. Legacy PHP serialization remains readable without
object hydration.

Keep the cache directory private and writable by the application. Cache entry
and lock symlinks are rejected. `clear()` locks each entry individually and leaves
lock files in place, so it is not a transaction over every key. Remove stale lock
files only with workers stopped. Multiple hosts must use Redis or another shared
store implementing the atomic contract.

## Verification

- `php tests/ConcurrencyHardeningTest.php` exercises multiple PHP processes,
  exact admission counts, large concurrent reads/writes, failed persistence and
  long-job renewal.
- `php tests/ServiceIntegrationTest.php` requires isolated MySQL and Redis
  services. `--redis-only` explicitly runs the Redis subset, including competing
  processes, counter TTLs and long-job duplicate suppression.
- `python3 scripts/test-upload-http.py resources/project-templates/core/public`
  requires Apache with mod_php. It proves PHP execution in a control file,
  denial of active uploads including PATH_INFO, and access to safe files.
- `FNLLA_REQUIRE_SYMLINK_TESTS=1 php scripts/test.php` requires symlink fixtures.
  Without the flag, unavailable symlink tests print explicit `SKIP` messages.

CI runs the service and Apache checks as separate test jobs. A local
run without those services does not establish their results.
