<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CACHE SOURCE
File: src\Cache\FileCacheStore.php
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

use RuntimeException;

final class FileCacheStore implements CacheStoreInterface, RateLimitStoreInterface
{
    public function __construct(
        private string $directory,
        private ?CacheSerializerInterface $serializer = null,
        private ?CacheSerializerInterface $legacySerializer = null
    )
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Cannot create cache directory.");
        }
        $this->directory = (string) realpath($this->directory);

        $this->serializer ??= new JsonCacheSerializer();
        $this->legacySerializer ??= new PhpCacheSerializer();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->locked($key, fn (): ?array => $this->read($key));

        return $payload["value"] ?? $default;
    }

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): bool
    {
        return $this->locked($key, fn (): bool => $this->write($key, [
            "expires_at" => time() + max(1, $ttlSeconds),
            "value" => $value,
        ]));
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $value = $this->get($key, null);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttlSeconds);

        return $value;
    }

    public function forget(string $key): bool
    {
        return $this->locked($key, fn (): bool => $this->remove($this->path($key)));
    }

    public function clear(): bool
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . "*.cache");

        if ($files === false) {
            return true;
        }

        foreach ($files as $file) {
            $hash = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^[a-f0-9]{40}$/D', $hash) === 1) {
                $this->lockedHash($hash, fn (): bool => $this->remove($file));
            }
        }

        return true;
    }

    public function increment(string $key, int $value = 1, int $ttlSeconds = 3600): int
    {
        return $this->locked($key, function () use ($key, $value, $ttlSeconds): int {
            $current = (int) ($this->read($key, true)["value"] ?? 0);
            $current += $value;
            $this->write($key, ["value" => $current, "expires_at" => time() + max(1, $ttlSeconds)]);

            return $current;
        });
    }

    public function decrement(string $key, int $value = 1, int $ttlSeconds = 3600): int
    {
        return $this->increment($key, $value * -1, $ttlSeconds);
    }

    public function consume(string $key, int $limit, int $decaySeconds): array
    {
        return $this->locked($key, function () use ($key, $limit, $decaySeconds): array {
            $payload = $this->read($key, true);
            $now = time();
            $count = $payload["value"] ?? 0;
            if (!is_int($count) || $count < 0) {
                throw new RuntimeException("Invalid rate limit counter.");
            }
            $expires = $payload["expires_at"] ?? ($now + max(1, $decaySeconds));
            $allowed = $count < max(0, $limit);
            if ($allowed) {
                $count++;
                $this->write($key, ["value" => $count, "expires_at" => $expires]);
            }
            return ["allowed" => $allowed, "attempts" => $count, "retry_after" => max(0, $expires - $now)];
        });
    }

    private function read(string $key, bool $strict = false): ?array
    {
        $path = $this->path($key);
        if (is_link($path)) {
            throw new RuntimeException("Cache entries cannot be symbolic links.");
        }

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if (!is_string($contents)) {
            throw new RuntimeException("Cannot read cache entry.");
        }

        $payload = $this->serializer?->unserialize($contents);

        /*
        JSON is the forward format. The legacy PHP serializer remains readable
        so existing deployments can upgrade without manually clearing cache
        files, but object hydration is still explicitly blocked there.
        */
        if (!is_array($payload)) {
            $payload = $this->legacySerializer?->unserialize($contents);
        }

        if (!is_array($payload) || !is_int($payload["expires_at"] ?? null) || !array_key_exists("value", $payload)) {
            if ($strict) {
                throw new RuntimeException("Cache counter is corrupt.");
            }
            return null;
        }

        if ($payload["expires_at"] <= time()) {
            $this->remove($path);

            return null;
        }

        return $payload;
    }

    private function write(string $key, array $payload): bool
    {
        $path = $this->path($key);
        if (is_link($path)) {
            throw new RuntimeException("Cache entries cannot be symbolic links.");
        }
        $contents = $this->serializer?->serialize($payload) ?? "";
        $temporary = @tempnam($this->directory, ".cache-");
        if ($temporary === false) {
            throw new RuntimeException("Cannot stage cache entry.");
        }
        try {
            if (realpath(dirname($temporary)) !== $this->directory
                || @file_put_contents($temporary, $contents) !== strlen($contents)
                || !@rename($temporary, $path)) {
                throw new RuntimeException("Cannot persist cache entry.");
            }
            return true;
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    private function remove(string $path): bool
    {
        if (is_link($path) || (file_exists($path) && !@unlink($path))) {
            throw new RuntimeException("Cannot remove cache entry.");
        }
        return true;
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . sha1($key) . ".cache";
    }

    private function locked(string $key, callable $callback): mixed
    {
        return $this->lockedHash(sha1($key), $callback);
    }

    private function lockedHash(string $hash, callable $callback): mixed
    {
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . $hash . ".lock";
        clearstatcache();
        if (is_link($lockPath)) {
            throw new RuntimeException("Cache locks cannot be symbolic links.");
        }
        $handle = @fopen($lockPath, "c+b");

        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot open cache lock.");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Cannot acquire cache lock.");
            }
            clearstatcache();

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
