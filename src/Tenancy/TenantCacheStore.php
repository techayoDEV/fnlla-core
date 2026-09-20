<?php

declare(strict_types=1);

namespace Fnlla\Php\Tenancy;

use Fnlla\Php\Cache\CacheStoreInterface;
use RuntimeException;

final class TenantCacheStore implements CacheStoreInterface
{
    public function __construct(private CacheStoreInterface $store, private TenantResourceScope $scope)
    {
    }

    public function get(string $key, mixed $default = null): mixed { return $this->store->get($this->scope->cacheKey($key), $default); }
    public function put(string $key, mixed $value, int $ttlSeconds = 3600): bool { return $this->store->put($this->scope->cacheKey($key), $value, $ttlSeconds); }
    public function remember(string $key, int $ttlSeconds, callable $callback): mixed { return $this->store->remember($this->scope->cacheKey($key), $ttlSeconds, $callback); }
    public function forget(string $key): bool { return $this->store->forget($this->scope->cacheKey($key)); }
    public function increment(string $key, int $value = 1, int $ttlSeconds = 3600): int { return $this->store->increment($this->scope->cacheKey($key), $value, $ttlSeconds); }
    public function decrement(string $key, int $value = 1, int $ttlSeconds = 3600): int { return $this->store->decrement($this->scope->cacheKey($key), $value, $ttlSeconds); }

    public function clear(): bool
    {
        throw new RuntimeException("Tenant cache clear is unsupported because the underlying store cannot enumerate one tenant safely.");
    }
}
