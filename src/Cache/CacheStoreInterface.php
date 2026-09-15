<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CACHE SOURCE
File: src\Cache\CacheStoreInterface.php
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

interface CacheStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): bool;

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed;

    public function forget(string $key): bool;

    public function clear(): bool;

    public function increment(string $key, int $value = 1, int $ttlSeconds = 3600): int;

    public function decrement(string $key, int $value = 1, int $ttlSeconds = 3600): int;
}
