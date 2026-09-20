<?php

declare(strict_types=1);

use Fnlla\Php\Container\Container;
use Fnlla\Php\Queue\JobEnvelope;
use Fnlla\Php\Queue\QueueManager;
use Fnlla\Php\Queue\QueueStoreInterface;

final class LegacyCapabilityStore implements QueueStoreInterface
{
    /** @var list<array<string, mixed>> */
    public array $pending = [];
    public int $completed = 0;
    public int $failed = 0;
    public bool $retryFailed = false;

    public function push(string $jobClass, array $payload = []): string
    {
        $id = "legacy-" . (count($this->pending) + 1);
        $this->pending[] = ["id" => $id, "job" => $jobClass, "payload" => $payload];
        return $id;
    }

    public function pop(): ?array { return array_shift($this->pending); }
    public function complete(array $job): void { $this->completed++; }

    public function fail(array $job): string
    {
        $this->failed++;
        if ($this->retryFailed && $this->failed === 1) {
            $this->pending[] = $job;
        }
        return "memory://failed/" . (string) ($job["id"] ?? "unknown");
    }

    public function pendingCount(): int { return count($this->pending); }
    public function failedCount(): int { return $this->failed; }
}

final class LegacyCapabilityJob
{
    public static int $handled = 0;
    public static int $externalEffects = 0;

    public function handle(): void
    {
        self::$handled++;
        self::$externalEffects++;
    }
}

function legacy_capability_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . " Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ".");
    }
}

$previousQueue = (array) config("queue", []);
config_set("queue", array_replace($previousQueue, [
    "worker_max_seconds" => 5,
    "job_types" => [
        "legacy-capability" => ["class" => LegacyCapabilityJob::class, "version" => JobEnvelope::VERSION],
    ],
]));

$advanced = new LegacyCapabilityStore();
$advanced->retryFailed = true;
$advanced->pending[] = [
    "id" => "advanced-through-legacy",
    "job" => LegacyCapabilityJob::class,
    "payload" => [],
    "schema" => JobEnvelope::SCHEMA,
    "job_type" => "legacy-capability",
    "job_version" => JobEnvelope::VERSION,
    "context" => ["tenant_id" => "tenant-a", "actor_id" => "revoked-actor", "idempotency_key" => "replayed"],
    "reservation" => ["token" => "expired"],
];
$advancedManager = new QueueManager(new Container(), $advanced);
legacy_capability_assert_same(0, $advancedManager->work(2, 5), "A versioned record was reported as successful by a legacy store.");
legacy_capability_assert_same(0, LegacyCapabilityJob::$handled, "A versioned record executed through a legacy store.");
legacy_capability_assert_same(0, LegacyCapabilityJob::$externalEffects, "A forbidden external effect ran through a legacy store.");
legacy_capability_assert_same(0, $advanced->completed, "A rejected versioned record was acknowledged as successful.");
legacy_capability_assert_same(2, $advanced->failed, "A retried versioned record did not fail closed on every read.");

config_set("queue.job_types", []);
$basic = new LegacyCapabilityStore();
$basicManager = new QueueManager(new Container(), $basic);
$basicManager->push(LegacyCapabilityJob::class, ["historical" => true]);
legacy_capability_assert_same(1, $basicManager->work(1, 5), "A historically supported basic legacy job stopped working.");
legacy_capability_assert_same(1, LegacyCapabilityJob::$handled, "The basic legacy handler did not run.");
legacy_capability_assert_same(1, LegacyCapabilityJob::$externalEffects, "The basic legacy handler result was not observed.");
legacy_capability_assert_same(1, $basic->completed, "The basic legacy job was not acknowledged.");
legacy_capability_assert_same(0, $basic->failed, "The basic legacy job was incorrectly failed.");

config_set("queue", $previousQueue);
fwrite(STDOUT, "Legacy queue capability boundary tests passed." . PHP_EOL);
