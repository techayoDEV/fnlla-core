<?php

declare(strict_types=1);

namespace Fnlla\Php\Queue;

use Redis;
use RuntimeException;

final class RedisQueueStore implements ReliableQueueStoreInterface
{
    private Redis $redis;
    private string $pendingKey;
    private string $failedKey;
    private string $reservedKey;
    private string $leasesKey;
    private string $delayedKey;
    private string $idempotencyPrefix;

    public function __construct(array $config)
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException("Redis queue store requires the ext-redis PHP extension.");
        }

        $prefix = (string) ($config["prefix"] ?? "fnlla:queue:");
        $this->pendingKey = $prefix . "pending";
        $this->failedKey = $prefix . "failed";
        $this->reservedKey = $prefix . "reserved";
        $this->leasesKey = $prefix . "leases";
        $this->delayedKey = $prefix . "delayed";
        $this->idempotencyPrefix = $prefix . "idempotency:";
        $this->redis = new Redis();
        $this->redis->connect((string) ($config["host"] ?? "127.0.0.1"), (int) ($config["port"] ?? 6379), (float) ($config["timeout"] ?? 1.5));

        $password = (string) ($config["password"] ?? "");
        if ($password !== "") {
            $this->redis->auth($password);
        }

        $database = (int) ($config["database"] ?? 0);
        if ($database > 0) {
            $this->redis->select($database);
        }
    }

    public function push(string $jobClass, array $payload = []): string
    {
        return $this->pushWithMetadata($jobClass, $payload);
    }

    public function pushWithMetadata(string $jobClass, array $payload = [], array $metadata = []): string
    {
        $id = gmdate("YmdHis") . "_" . bin2hex(random_bytes(8));
        $this->redis->rPush($this->pendingKey, json_encode([
            ...JobEnvelope::create($id, $jobClass, $payload, $metadata),
            "attempts" => 0,
            "max_attempts" => max(1, (int) config("queue.max_attempts", 1)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $id;
    }

    public function pop(int $poisonDepth = 0): ?array
    {
        // Use Redis time and one atomic script for recovery plus reservation.
        $deadline = hrtime(true) + 2_000_000_000;
        do {
        $payload = $this->redis->eval(<<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local due = redis.call('ZRANGEBYSCORE', KEYS[5], '-inf', now, 'LIMIT', 0, 100)
for _, raw in ipairs(due) do
    redis.call('RPUSH', KEYS[1], raw)
    redis.call('ZREM', KEYS[5], raw)
end
local expired = redis.call('ZRANGEBYSCORE', KEYS[4], '-inf', now, 'LIMIT', 0, 100)
for _, id in ipairs(expired) do
    local raw = redis.call('HGET', KEYS[3], id)
    if raw then
        local decoded, job = pcall(cjson.decode, raw)
        if not decoded or type(job) ~= 'table' or job.id ~= id
            or not tonumber(job.attempts) or not tonumber(job.max_attempts) then
            redis.call('RPUSH', KEYS[2], raw)
        else
            job.reservation = nil
            job.reserved_until = nil
            if tonumber(job.attempts) >= tonumber(job.max_attempts) then
                job.last_error = 'attempts_exhausted'
                redis.call('RPUSH', KEYS[2], cjson.encode(job))
            else
                redis.call('RPUSH', KEYS[1], cjson.encode(job))
            end
        end
        redis.call('HDEL', KEYS[3], id)
    end
    redis.call('ZREM', KEYS[4], id)
end
local count = math.min(redis.call('LLEN', KEYS[1]), 100)
for i = 1, count do
    local raw = redis.call('LINDEX', KEYS[1], 0)
    local decoded, job = pcall(cjson.decode, raw)
    local available = decoded and type(job) == 'table' and tonumber(job.available_at or 0)
    local attempts = decoded and type(job) == 'table' and tonumber(job.attempts or 0)
    local maximum = decoded and type(job) == 'table' and tonumber(job.max_attempts or ARGV[2])
    if not decoded or type(job) ~= 'table' or type(job.id) ~= 'string'
        or type(job.job) ~= 'string' or type(job.payload) ~= 'table'
        or #job.id < 1 or #job.id > 160
        or not available or not attempts or not maximum or maximum < 1
        or attempts < 0 or attempts ~= math.floor(attempts) or maximum ~= math.floor(maximum) then
        redis.call('RPUSH', KEYS[2], raw)
        redis.call('LPOP', KEYS[1])
    elseif redis.call('HEXISTS', KEYS[3], job.id) == 1 or attempts >= maximum then
        redis.call('RPUSH', KEYS[2], raw)
        redis.call('LPOP', KEYS[1])
    elseif available <= now then
        job.attempts = attempts + 1
        job.max_attempts = maximum
        job.reservation = ARGV[1]
        job.idempotency_claimed = nil
        job.reserved_until = now + tonumber(ARGV[3])
        local encoded = cjson.encode(job)
        redis.call('HSET', KEYS[3], job.id, encoded)
        redis.call('ZADD', KEYS[4], job.reserved_until, job.id)
        redis.call('LPOP', KEYS[1])
        return encoded
    else
        -- Migrate legacy delayed entries without cycling them through ready work.
        redis.call('ZADD', KEYS[5], available, raw)
        redis.call('LPOP', KEYS[1])
    end
end
if redis.call('LLEN', KEYS[1]) > 0 or redis.call('ZCOUNT', KEYS[5], '-inf', now) > 0
    or redis.call('ZCOUNT', KEYS[4], '-inf', now) > 0 then return '__fnlla_scan__' end
return false
LUA, [$this->pendingKey, $this->failedKey, $this->reservedKey, $this->leasesKey, $this->delayedKey,
            bin2hex(random_bytes(16)), max(1, (int) config("queue.max_attempts", 1)),
            max(1, (int) config("queue.visibility_timeout_seconds", 300))], 5);
            if ($payload === "__fnlla_scan__" && hrtime(true) >= $deadline) {
                throw new RuntimeException("Redis queue migration budget exhausted; retry reservation.");
            }
        } while ($payload === "__fnlla_scan__");

        if ($payload === false) {
            return null;
        }

        $decoded = json_decode((string) $payload, true);
        if (!is_array($decoded)) {
            throw new QueuePayloadException("Redis queued job payload is invalid JSON.");
        }

        $reserved = array_merge($decoded, [
            "id" => (string) ($decoded["id"] ?? ""),
            "job" => (string) ($decoded["job"] ?? ""),
            "payload" => is_array($decoded["payload"] ?? null) ? $decoded["payload"] : [],
            "source" => $payload,
        ]);
        try {
            return JobEnvelope::normalize($reserved, (bool) config("queue.accept_legacy_payloads", true));
        } catch (QueuePayloadException $exception) {
            $this->reject($reserved, $exception->getMessage());
            if ($poisonDepth >= 99) {
                throw new QueuePayloadException("Too many rejected queue payloads in one reservation cycle.", 0, $exception);
            }
            return $this->pop($poisonDepth + 1);
        }
    }

    public function complete(array $job): void
    {
        $this->settle($job, "complete");
    }

    public function fail(array $job): string
    {
        $this->settle($job, "fail");
        return "redis:" . (string) $job["id"];
    }

    public function reject(array $job, string $reason): string
    {
        $job["last_error"] = $reason;
        $this->settle($job, "reject");
        return "redis:" . (string) $job["id"];
    }

    public function owns(array $job): bool
    {
        $result = $this->redis->eval(<<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local queued = cjson.decode(raw)
local now = tonumber(redis.call('TIME')[1])
if queued.reservation ~= ARGV[2] or tonumber(queued.reserved_until or 0) <= now then return 0 end
return 1
LUA, [$this->reservedKey, (string) ($job["id"] ?? ""), (string) ($job["reservation"] ?? "")], 1);
        return $result === 1;
    }

    public function renew(array $job, int $leaseSeconds): array
    {
        $payload = $this->redis->eval(<<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return false end
local queued = cjson.decode(raw)
local now = tonumber(redis.call('TIME')[1])
if queued.reservation ~= ARGV[2] or tonumber(queued.reserved_until or 0) <= now then return false end
local expiry = math.max(tonumber(queued.reserved_until), now + tonumber(ARGV[3]))
if queued.idempotency_claimed then
    if redis.call('GET', KEYS[3]) ~= 'processing:' .. ARGV[2] then return false end
    redis.call('EXPIRE', KEYS[3], expiry - now)
end
queued.reserved_until = expiry
local encoded = cjson.encode(queued)
redis.call('HSET', KEYS[1], ARGV[1], encoded)
redis.call('ZADD', KEYS[2], queued.reserved_until, ARGV[1])
return encoded
LUA, [$this->reservedKey, $this->leasesKey, $this->idempotencyKey($job) ?? $this->reservedKey,
            (string) ($job["id"] ?? ""), (string) ($job["reservation"] ?? ""), max(1, $leaseSeconds)], 3);
        if (!is_string($payload) || $payload === "") {
            throw new RuntimeException("Queue lease is expired, missing or owned by another worker.");
        }
        $renewed = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($renewed)) {
            throw new RuntimeException("Renewed queue lease payload is invalid.");
        }
        return JobEnvelope::normalize(array_merge($renewed, ["source" => $payload]), false);
    }

    public function beginIdempotent(array $job): string
    {
        $key = $this->idempotencyKey($job);
        if ($key === null) {
            return "untracked";
        }
        $result = $this->redis->eval(<<<'LUA'
local raw = redis.call('HGET', KEYS[2], ARGV[2])
if not raw then return 'busy' end
local queued = cjson.decode(raw)
local now = tonumber(redis.call('TIME')[1])
if queued.reservation ~= ARGV[1] or tonumber(queued.reserved_until or 0) <= now then return 'busy' end
local current = redis.call('GET', KEYS[1])
if current == 'completed' then return 'completed' end
if current then return 'busy' end
queued.idempotency_claimed = true
local encoded = cjson.encode(queued)
redis.call('SETEX', KEYS[1], tonumber(queued.reserved_until) - now, 'processing:' .. ARGV[1])
redis.call('HSET', KEYS[2], ARGV[2], encoded)
return 'claimed'
LUA, [$key, $this->reservedKey, (string) ($job["reservation"] ?? ""), (string) ($job["id"] ?? "")], 2);
        return in_array($result, ["claimed", "busy", "completed"], true) ? $result : "busy";
    }

    public function completeIdempotent(array $job): void
    {
        $key = $this->idempotencyKey($job);
        if ($key === null) {
            return;
        }
        $result = $this->redis->eval(<<<'LUA'
local raw = redis.call('HGET', KEYS[2], ARGV[3])
if not raw then return 0 end
local queued = cjson.decode(raw)
local now = tonumber(redis.call('TIME')[1])
if queued.reservation ~= ARGV[1] or tonumber(queued.reserved_until or 0) <= now then return 0 end
local expected = 'processing:' .. ARGV[1]
if redis.call('GET', KEYS[1]) ~= expected then return 0 end
redis.call('SETEX', KEYS[1], tonumber(ARGV[2]), 'completed')
return 1
LUA, [$key, $this->reservedKey, (string) ($job["reservation"] ?? ""),
            max(1, (int) config("queue.idempotency_ttl_seconds", 86400)), (string) ($job["id"] ?? "")], 2);
        if ($result !== 1) {
            throw new RuntimeException("Queue idempotency claim is missing or owned by another worker.");
        }
    }

    public function releaseIdempotent(array $job): void
    {
        $key = $this->idempotencyKey($job);
        if ($key === null) {
            return;
        }
        $this->redis->eval(<<<'LUA'
local expected = 'processing:' .. ARGV[1]
if redis.call('GET', KEYS[1]) == expected then return redis.call('DEL', KEYS[1]) end
return 0
LUA, [$key, (string) ($job["reservation"] ?? "")], 1);
    }

    public function pendingCount(): int
    {
        return (int) $this->redis->lLen($this->pendingKey) + (int) $this->redis->hLen($this->reservedKey)
            + (int) $this->redis->zCard($this->delayedKey);
    }

    public function failedCount(): int
    {
        return (int) $this->redis->lLen($this->failedKey);
    }

    private function settle(array $job, string $mode): void
    {
        $result = $this->redis->eval(<<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local raw = redis.call('HGET', KEYS[3], ARGV[1])
if not raw then return 0 end
local job = cjson.decode(raw)
if job.reservation ~= ARGV[2] or job.reserved_until <= now then return 0 end
if ARGV[3] == 'fail' or ARGV[3] == 'reject' then
    job.reservation = nil
    job.reserved_until = nil
    job.last_error = ARGV[4]
    job.available_at = now + tonumber(ARGV[5])
    if ARGV[3] == 'reject' or job.attempts >= job.max_attempts then
        redis.call('RPUSH', KEYS[2], cjson.encode(job))
    else
        redis.call('ZADD', KEYS[5], job.available_at, cjson.encode(job))
    end
end
redis.call('HDEL', KEYS[3], ARGV[1])
redis.call('ZREM', KEYS[4], ARGV[1])
return 1
LUA, [$this->pendingKey, $this->failedKey, $this->reservedKey, $this->leasesKey, $this->delayedKey,
            (string) ($job["id"] ?? ""), (string) ($job["reservation"] ?? ""), $mode,
            QueueFailure::code((string) ($job["last_error"] ?? 'job_failed')),
            max(1, (int) config("queue.retry_backoff_seconds", 30))], 5);
        if ($result !== 1) {
            throw new RuntimeException("Queue reservation is missing, expired or owned by another worker.");
        }
    }

    private function idempotencyKey(array $job): ?string
    {
        $value = $job["context"]["idempotency_key"] ?? null;
        if (!is_string($value) || $value === "") {
            return null;
        }
        return $this->idempotencyPrefix . hash("sha256", implode("\0", [
            (string) ($job["job_type"] ?? ""),
            (string) ($job["job_version"] ?? ""),
            (string) ($job["context"]["tenant_id"] ?? ""),
            $value,
        ]));
    }
}
