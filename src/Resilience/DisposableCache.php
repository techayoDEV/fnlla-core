<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Closure;
use Fnlla\Php\Cache\CacheStoreInterface;
use Throwable;

/** Explicitly disposable read data only. NEVER bind as the global/security cache. */
final class DisposableCache
{
    public function __construct(private Closure $primary, private ?Closure $fallback, private ResilienceEvents $events) {}

    public function get(string $key, mixed $default = null): mixed
    {
        try { return $this->primaryStore()->get($key, $default); } catch (Throwable) {
            $this->events->emit('cache_fallback_used', ['cache' => 'disposable']);
            try {
                $fallback = $this->fallbackStore();
                return $fallback === null ? $default : $fallback->get($key, $default);
            } catch (Throwable) { return $default; }
        }
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        $saved = false;
        try { $saved = $this->primaryStore()->put($key, $value, $ttl); } catch (Throwable) {}
        if (!$saved) { $this->events->emit('cache_fallback_used', ['cache' => 'disposable']); }
        // Mirror successful data so a primary outage can use its last valid value.
        try { $saved = ($this->fallbackStore()?->put($key, $value, $ttl) ?? false) || $saved; } catch (Throwable) {}
        if (!$saved) { $this->events->emit('cache_fallback_used', ['cache' => 'bypass']); }
        return $saved;
    }

    public function remember(string $key, int $ttl, callable $read): mixed
    {
        $missing = new \stdClass();
        $value = $this->get($key, $missing);
        if ($value !== $missing) { return $value; }
        $value = $read(); // Never swallow/retry a failed application callback.
        $this->put($key, $value, $ttl);
        return $value;
    }

    private function primaryStore(): CacheStoreInterface { return ($this->primary)(); }
    private function fallbackStore(): ?CacheStoreInterface { return $this->fallback === null ? null : ($this->fallback)(); }
}
