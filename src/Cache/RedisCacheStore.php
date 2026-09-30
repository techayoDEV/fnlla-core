<?php

declare(strict_types=1);

namespace Fnlla\Php\Cache;

use Redis;
use RuntimeException;

final class RedisCacheStore implements CacheStoreInterface, RateLimitStoreInterface
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
        $result = $this->redis->eval(<<<'LUA'
local result = redis.call('INCRBY', KEYS[1], ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
return result
LUA, [$this->key($key), $value, max(1, $ttlSeconds)], 1);
        if (!is_int($result)) {
            throw new RuntimeException("Cannot increment Redis cache counter.");
        }
        return $result;
    }

    public function consume(string $key, int $limit, int $decaySeconds): array
    {
        $result = $this->redis->eval(<<<'LUA'
local raw = redis.call('GET', KEYS[1])
local count = raw and tonumber(raw) or 0
local ttl = redis.call('PTTL', KEYS[1])
if raw and (not tonumber(raw) or count < 0 or count ~= math.floor(count) or ttl < 0) then
    return redis.error_reply('Invalid rate limit counter or expiry')
end
if count >= tonumber(ARGV[1]) then return {0, count, math.max(0, math.ceil(ttl / 1000))} end
count = redis.call('INCR', KEYS[1])
if not raw then
    ttl = tonumber(ARGV[2]) * 1000
    redis.call('PEXPIRE', KEYS[1], ttl)
end
return {1, count, math.max(0, math.ceil(ttl / 1000))}
LUA, [$this->key($key), max(0, $limit), max(1, $decaySeconds)], 1);
        if (!is_array($result) || count($result) !== 3) {
            throw new RuntimeException("Cannot acquire Redis rate limit slot.");
        }
        return ["allowed" => $result[0] === 1, "attempts" => (int) $result[1], "retry_after" => (int) $result[2]];
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
