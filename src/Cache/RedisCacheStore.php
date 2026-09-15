<?php

declare(strict_types=1);

namespace Fnlla\Php\Cache;

use Redis;
use RuntimeException;

final class RedisCacheStore implements CacheStoreInterface
{
    private Redis $redis;
    private string $prefix;

    public function __construct(array $config)
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException("Redis cache store requires the ext-redis PHP extension.");
        }

        $this->prefix = (string) ($config["prefix"] ?? "fnlla:cache:");
        $this->redis = new Redis();
        $this->redis->connect(
            (string) ($config["host"] ?? "127.0.0.1"),
            (int) ($config["port"] ?? 6379),
            (float) ($config["timeout"] ?? 1.5)
        );

        $password = (string) ($config["password"] ?? "");
        if ($password !== "") {
            $this->redis->auth($password);
        }

        $database = (int) ($config["database"] ?? 0);
        if ($database > 0) {
            $this->redis->select($database);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->redis->get($this->key($key));

        if ($payload === false) {
            return $default;
        }

        $decoded = json_decode((string) $payload, true);

        if (is_array($decoded) && array_key_exists("value", $decoded)) {
            return $decoded["value"];
        }

        return is_numeric($payload) ? (int) $payload : $default;
    }

    public function put(string $key, mixed $value, int $ttlSeconds = 3600): bool
    {
        return (bool) $this->redis->setex($this->key($key), max(1, $ttlSeconds), json_encode([
            "value" => $value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $missing = new \stdClass();
        $value = $this->get($key, $missing);

        if ($value !== $missing) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttlSeconds);

        return $value;
    }

    public function forget(string $key): bool
    {
        return $this->redis->del($this->key($key)) >= 0;
    }

    public function clear(): bool
    {
        $iterator = null;

        do {
            $keys = $this->redis->scan($iterator, $this->prefix . "*", 100);
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        } while ($iterator !== 0);

        return true;
    }

    public function increment(string $key, int $value = 1, int $ttlSeconds = 3600): int
    {
        $redisKey = $this->key($key);
        $result = (int) $this->redis->incrBy($redisKey, $value);
        $this->redis->expire($redisKey, max(1, $ttlSeconds));

        return $result;
    }

    public function decrement(string $key, int $value = 1, int $ttlSeconds = 3600): int
    {
        return $this->increment($key, -abs($value), $ttlSeconds);
    }

    private function key(string $key): string
    {
        return $this->prefix . preg_replace('/[^A-Za-z0-9_.:-]+/', ":", $key);
    }
}
