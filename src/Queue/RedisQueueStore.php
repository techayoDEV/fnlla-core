<?php

declare(strict_types=1);

namespace Fnlla\Php\Queue;

use Redis;
use RuntimeException;

final class RedisQueueStore implements QueueStoreInterface
{
    private Redis $redis;
    private string $pendingKey;
    private string $failedKey;
    private string $reservedKey;
    private string $leasesKey;

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
        $id = gmdate("YmdHis") . "_" . bin2hex(random_bytes(8));
        $this->redis->rPush($this->pendingKey, json_encode([
            "id" => $id,
            "job" => $jobClass,
            "payload" => $payload,
            "attempts" => 0,
            "max_attempts" => max(1, (int) config("queue.max_attempts", 1)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $id;
    }

    public function pop(): ?array
    {
        // Use Redis time and one atomic script for recovery plus reservation.
        $payload = $this->redis->eval(<<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local expired = redis.call('ZRANGEBYSCORE', KEYS[4], '-inf', now, 'LIMIT', 0, 100)
for _, id in ipairs(expired) do
    local raw = redis.call('HGET', KEYS[3], id)
    if raw then
        local job = cjson.decode(raw)
        job.reservation = nil
        job.reserved_until = nil
        if job.attempts >= job.max_attempts then
            job.last_error = 'Reservation expired after the final attempt.'
            redis.call('RPUSH', KEYS[2], cjson.encode(job))
        else
            redis.call('RPUSH', KEYS[1], cjson.encode(job))
        end
        redis.call('HDEL', KEYS[3], id)
    end
    redis.call('ZREM', KEYS[4], id)
end
local count = math.min(redis.call('LLEN', KEYS[1]), 100)
for i = 1, count do
    local raw = redis.call('LINDEX', KEYS[1], 0)
    local job = cjson.decode(raw)
    if type(job.id) ~= 'string' or type(job.job) ~= 'string' or type(job.payload) ~= 'table' then
        return redis.error_reply('Invalid queued job; queue was not consumed')
    end
    if tonumber(job.available_at or 0) <= now then
        job.attempts = tonumber(job.attempts or 0) + 1
        job.max_attempts = tonumber(job.max_attempts or ARGV[2])
        job.reservation = ARGV[1]
        job.reserved_until = now + tonumber(ARGV[3])
        local encoded = cjson.encode(job)
        redis.call('HSET', KEYS[3], job.id, encoded)
        redis.call('ZADD', KEYS[4], job.reserved_until, job.id)
        redis.call('LPOP', KEYS[1])
        return encoded
    end
    redis.call('RPOPLPUSH', KEYS[1], KEYS[1])
end
return false
LUA, [$this->pendingKey, $this->failedKey, $this->reservedKey, $this->leasesKey,
            bin2hex(random_bytes(16)), max(1, (int) config("queue.max_attempts", 1)),
            max(1, (int) config("queue.visibility_timeout_seconds", 300))], 4);

        if ($payload === false) {
            return null;
        }

        $decoded = json_decode((string) $payload, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Redis queued job payload is invalid JSON.");
        }

        return array_merge($decoded, [
            "id" => (string) ($decoded["id"] ?? ""),
            "job" => (string) ($decoded["job"] ?? ""),
            "payload" => is_array($decoded["payload"] ?? null) ? $decoded["payload"] : [],
            "source" => $payload,
        ]);
    }

    public function complete(array $job): void
    {
        $this->settle($job, false);
    }

    public function fail(array $job): string
    {
        $this->settle($job, true);
        return "redis:" . (string) $job["id"];
    }

    public function pendingCount(): int
    {
        return (int) $this->redis->lLen($this->pendingKey) + (int) $this->redis->hLen($this->reservedKey);
    }

    public function failedCount(): int
    {
        return (int) $this->redis->lLen($this->failedKey);
    }

    private function settle(array $job, bool $failed): void
    {
        $result = $this->redis->eval(<<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local raw = redis.call('HGET', KEYS[3], ARGV[1])
if not raw then return 0 end
local job = cjson.decode(raw)
if job.reservation ~= ARGV[2] or job.reserved_until <= now then return 0 end
if ARGV[3] == 'fail' then
    job.reservation = nil
    job.reserved_until = nil
    job.last_error = ARGV[4]
    job.available_at = now + tonumber(ARGV[5])
    local destination = KEYS[1]
    if job.attempts >= job.max_attempts then destination = KEYS[2] end
    redis.call('RPUSH', destination, cjson.encode(job))
end
redis.call('HDEL', KEYS[3], ARGV[1])
redis.call('ZREM', KEYS[4], ARGV[1])
return 1
LUA, [$this->pendingKey, $this->failedKey, $this->reservedKey, $this->leasesKey,
            (string) ($job["id"] ?? ""), (string) ($job["reservation"] ?? ""), $failed ? "fail" : "complete",
            substr((string) ($job["last_error"] ?? "Job failed."), 0, 4000),
            max(1, (int) config("queue.retry_backoff_seconds", 30))], 4);
        if ($result !== 1) {
            throw new RuntimeException("Queue reservation is missing, expired or owned by another worker.");
        }
    }
}
