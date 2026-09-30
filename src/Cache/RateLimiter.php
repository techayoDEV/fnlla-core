<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CACHE SOURCE
File: src\Cache\RateLimiter.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained cache and rate-limiting primitives for the framework runtime.
*/

namespace Fnlla\Php\Cache;

final class RateLimiter
{
    public function __construct(private CacheStoreInterface $cache)
    {
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /** @return array{allowed: bool, attempts: int, retry_after: int} */
    public function acquire(string $key, int $maxAttempts, int $decaySeconds = 60): array
    {
        if (!$this->cache instanceof RateLimitStoreInterface) {
            throw new \RuntimeException("Request throttling requires an atomic RateLimitStoreInterface store.");
        }
        return $this->cache->consume("rate-limit:" . $key, max(0, $maxAttempts), max(1, $decaySeconds));
    }

    public function hit(string $key, int $decaySeconds = 60): int
    {
        $this->cache->put($key . ":timer", time() + $decaySeconds, $decaySeconds);

        return $this->cache->increment($key, 1, $decaySeconds);
    }

    public function clear(string $key): void
    {
        $this->cache->forget("rate-limit:" . $key);
        $this->cache->forget($key);
        $this->cache->forget($key . ":timer");
    }

    public function attempts(string $key): int
    {
        return (int) $this->cache->get($key, 0);
    }

    public function availableIn(string $key): int
    {
        $availableAt = (int) $this->cache->get($key . ":timer", 0);

        return max(0, $availableAt - time());
    }
}
