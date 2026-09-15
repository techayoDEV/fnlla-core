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

    public function hit(string $key, int $decaySeconds = 60): int
    {
        $this->cache->put($key . ":timer", time() + $decaySeconds, $decaySeconds);

        return $this->cache->increment($key, 1, $decaySeconds);
    }

    public function clear(string $key): void
    {
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
