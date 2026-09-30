<?php

declare(strict_types=1);

namespace Fnlla\Php\Testing;

use Fnlla\Php\Cache\CacheStoreInterface;
use Fnlla\Php\Cache\RateLimitStoreInterface;
use Fnlla\Php\Queue\ReliableQueueStoreInterface;
use RuntimeException;
use Throwable;

/** Consumer-facing conformance checks. Factories MUST target isolated test storage. */
final class AdapterContractSuite
{
    /** @param callable():CacheStoreInterface $factory @return list<string> */
    public static function cache(callable $factory): array
    {
        $first = $factory(); $second = $factory();
        $prefix = "fnlla-contract-" . bin2hex(random_bytes(12));
        try {
            self::check($first->get($prefix, "missing") === "missing", "cache.missing_default");
            self::check($first->put($prefix, ["number" => 1, "flag" => false], 30), "cache.persist");
            self::check($second->get($prefix) === ["number" => 1, "flag" => false], "cache.cross_client_roundtrip");
            $first->put($prefix . "-counter", 0, 30);
            self::check($first->increment($prefix . "-counter", 2, 30) === 2, "cache.increment");
            self::check($second->decrement($prefix . "-counter", 1, 30) === 1, "cache.decrement");
            $first->forget($prefix);
            self::check($second->get($prefix, "missing") === "missing", "cache.forget");
            $checks = ["cache.missing_default", "cache.persist", "cache.cross_client_roundtrip",
                "cache.increment", "cache.decrement", "cache.forget"];
            if ($first instanceof RateLimitStoreInterface && $second instanceof RateLimitStoreInterface) {
                self::check($first->consume($prefix . "-rate", 1, 30)["allowed"], "rate.first_admitted");
                $denied = $second->consume($prefix . "-rate", 1, 30);
                self::check(!$denied["allowed"] && $denied["retry_after"] > 0 && $denied["attempts"] === 1, "rate.cross_client_denial");
                $checks[] = "rate.first_admitted"; $checks[] = "rate.cross_client_denial";
            }
            return $checks;
        } finally {
            foreach ([$prefix, $prefix . "-counter", $prefix . "-rate"] as $key) { $first->forget($key); }
        }
    }

    /** @param callable():ReliableQueueStoreInterface $factory @return list<string> */
    public static function reliableQueue(callable $factory): array
    {
        $first = $factory(); $second = $factory();
        self::check($first->pendingCount() === 0 && $first->failedCount() === 0, "queue.requires_empty_isolated_store");
        $metadata = ["job_type" => "contract.job", "job_version" => 1,
            "context" => ["correlation_id" => "contract-" . bin2hex(random_bytes(8)), "idempotency_key" => "same-effect"]];
        $id = $first->pushWithMetadata("AdapterContractJob", ["number" => 1], $metadata);
        $job = $second->pop();
        self::check($job !== null && $job["id"] === $id && $job["payload"] === ["number" => 1], "queue.roundtrip");
        self::check($first->pop() === null, "queue.exclusive_reservation");
        $stale = $job; $stale["reservation"] = "not-the-owner";
        self::check(!$first->owns($stale), "queue.stale_owner_denied");
        self::rejects(static fn () => $first->complete($stale), "queue.stale_ack_denied");
        self::check($second->beginIdempotent($job) === "claimed", "queue.claim");
        self::check($first->beginIdempotent($job) === "busy", "queue.exclusive_claim");
        $renewed = $second->renew($job, 60);
        self::check($first->owns($renewed), "queue.renewal");
        $second->completeIdempotent($renewed);
        $second->complete($renewed);
        $first->pushWithMetadata("AdapterContractJob", [], $metadata);
        $replay = $second->pop();
        self::check($replay !== null && $first->beginIdempotent($replay) === "completed", "queue.durable_replay");
        $second->complete($replay);
        self::check($first->pendingCount() === 0, "queue.settlement");
        return ["queue.roundtrip", "queue.exclusive_reservation", "queue.stale_owner_denied", "queue.stale_ack_denied",
            "queue.claim", "queue.exclusive_claim", "queue.renewal", "queue.durable_replay", "queue.settlement"];
    }

    private static function check(bool $condition, string $invariant): void
    {
        if (!$condition) { throw new RuntimeException("Adapter violates " . $invariant . "."); }
    }

    private static function rejects(callable $operation, string $invariant): void
    {
        try { $operation(); } catch (Throwable) { return; }
        throw new RuntimeException("Adapter violates " . $invariant . ".");
    }
}
