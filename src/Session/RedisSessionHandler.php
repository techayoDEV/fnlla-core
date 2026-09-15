<?php

declare(strict_types=1);

namespace Fnlla\Php\Session;

use Redis;
use RuntimeException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

final class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private Redis $redis;
    private string $prefix;
    private int $ttlSeconds;
    private int $lockTtlMilliseconds;
    private int $lockWaitMilliseconds;
    private ?string $lockedId = null;
    private ?string $lockToken = null;

    public function __construct(array $config, int $ttlSeconds)
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException("Redis session handler requires the ext-redis PHP extension.");
        }

        $this->prefix = (string) ($config["prefix"] ?? "fnlla:session:");
        $this->ttlSeconds = max(1, $ttlSeconds);
        $this->lockTtlMilliseconds = max(1000, (int) ($config["lock_ttl_seconds"] ?? 60) * 1000);
        $this->lockWaitMilliseconds = max(0, min(30000, (int) ($config["lock_wait_milliseconds"] ?? 2000)));
        $this->redis = new Redis();
        $timeout = max(0.1, (float) ($config["timeout"] ?? 1.5));
        if (!$this->redis->connect((string) ($config["host"] ?? "127.0.0.1"), (int) ($config["port"] ?? 6379), $timeout)) {
            throw new RuntimeException("Cannot connect to Redis session storage.");
        }
        $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout);

        $password = (string) ($config["password"] ?? "");
        if ($password !== "") {
            if (!$this->redis->auth($password)) { throw new RuntimeException("Redis session authentication failed."); }
        }

        $database = (int) ($config["database"] ?? 0);
        if (!$this->redis->select($database)) {
            throw new RuntimeException("Cannot select Redis session database.");
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();
        return true;
    }

    public function read(string $id): string
    {
        $this->acquireLock($id);
        $value = $this->guarded($id, 'return redis.call("GET", KEYS[2]) or ""');
        if (!is_string($value)) { throw new RuntimeException("Invalid Redis session payload."); }
        return $value;
    }

    public function write(string $id, string $data): bool
    {
        $this->acquireLock($id);
        return $this->guarded($id, 'redis.call("SETEX", KEYS[2], ARGV[2], ARGV[3]); return 1', [$this->ttlSeconds, $data]) === 1;
    }

    public function destroy(string $id): bool
    {
        $this->acquireLock($id);
        $this->guarded($id, 'redis.call("DEL", KEYS[2]); return 1');
        $this->releaseLock();
        return true;
    }

    public function validateId(string $id): bool
    {
        if (!$this->validId($id)) { return false; }
        // Hold the same lock across validation and read to prevent deletion races.
        $this->acquireLock($id);
        if ($this->guarded($id, 'return redis.call("EXISTS", KEYS[2])') === 1) { return true; }
        $this->releaseLock();
        return false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        // PHP lazy writes may refresh TTL, but must not recreate a deleted session.
        $this->acquireLock($id);
        return $this->guarded($id, 'return redis.call("EXPIRE", KEYS[2], ARGV[2])', [$this->ttlSeconds]) === 1;
    }

    public function gc(int $max_lifetime): int
    {
        // Redis expires payloads independently; scanning keys here would block requests.
        return 0;
    }

    private function key(string $id): string
    {
        if (!$this->validId($id)) { throw new RuntimeException("Invalid session identifier."); }
        return $this->prefix . $id;
    }

    private function validId(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9,-]{1,256}\z/D', $id) === 1;
    }

    private function lockKey(string $id): string
    {
        return $this->prefix . "lock:" . $id;
    }

    private function acquireLock(string $id): void
    {
        $this->key($id);
        // Never reacquire a lost lease for the same request: stale writes must fail.
        if ($this->lockedId === $id) { return; }
        $this->releaseLock();
        $token = bin2hex(random_bytes(32));
        $deadline = hrtime(true) + $this->lockWaitMilliseconds * 1000000;
        do {
            if ($this->redis->set($this->lockKey($id), $token, ["NX", "PX" => $this->lockTtlMilliseconds])) {
                $this->lockedId = $id;
                $this->lockToken = $token;
                return;
            }
            if (hrtime(true) >= $deadline) { break; }
            usleep(10000);
        } while (true);
        throw new RuntimeException("Timed out waiting for the Redis session lock.");
    }

    private function guarded(string $id, string $operation, array $arguments = []): mixed
    {
        // Checking ownership and mutating data must be one Redis operation.
        $script = 'if redis.call("GET", KEYS[1]) ~= ARGV[1] then return {0} end; return {1, (function() ' . $operation . ' end)()}';
        $result = $this->redis->eval($script, [$this->lockKey($id), $this->key($id), $this->lockToken, ...$arguments], 2);
        if (!is_array($result) || ($result[0] ?? null) !== 1) {
            throw new RuntimeException("Redis session lock lost; stale session operation rejected.");
        }
        return $result[1] ?? null;
    }

    private function releaseLock(): void
    {
        if ($this->lockedId === null) { return; }
        $id = $this->lockedId;
        $token = $this->lockToken;
        $this->lockedId = null;
        $this->lockToken = null;
        // An expired lease may belong to another worker; only remove our token.
        $this->redis->eval('if redis.call("GET", KEYS[1]) == ARGV[1] then return redis.call("DEL", KEYS[1]) end; return 0', [$this->lockKey($id), $token], 1);
    }
}
