<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Queue\JobEnvelope;
use Fnlla\Php\Queue\QueueStoreInterface;
use Throwable;

final class RuntimeInspector
{
    public function __construct(private Container $container, private ?QueueStoreInterface $queue = null)
    {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $types = [];
        foreach ((array) config("queue.job_types", []) as $type => $definition) {
            if (is_string($type) && is_array($definition)) {
                $types[] = [
                    "type" => $type,
                    "version" => is_int($definition["version"] ?? null) ? $definition["version"] : null,
                    "class" => is_string($definition["class"] ?? null) ? $definition["class"] : null,
                ];
            }
        }
        usort($types, static fn (array $left, array $right): int => $left["type"] <=> $right["type"]);

        $counts = ["status" => "unavailable", "pending" => null, "failed" => null];
        if ($this->queue instanceof QueueStoreInterface) {
            try {
                $counts = [
                    "status" => "available",
                    "pending" => $this->queue->pendingCount(),
                    "failed" => $this->queue->failedCount(),
                ];
            } catch (Throwable $exception) {
                $counts["status"] = "error:" . $exception::class;
            }
        }

        $versionPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "VERSION";
        return [
            "schema" => "fnlla.runtime.inspection.v1",
            "runtime" => [
                "name" => RuntimeIdentity::get("name"),
                "version" => is_file($versionPath) ? trim((string) file_get_contents($versionPath)) : "unknown",
            ],
            "container" => [
                "active_scope" => $this->container->hasActiveScope(),
                "bindings" => $this->container->inspectBindings(),
            ],
            "queue" => [
                "backend" => (string) config("queue.default", "file"),
                "envelope_schema" => JobEnvelope::SCHEMA,
                "envelope_version" => JobEnvelope::VERSION,
                "registered_types" => $types,
                "legacy_payloads" => (bool) config("queue.accept_legacy_payloads", true),
                "legacy_sunset" => (string) config("queue.legacy_payload_sunset", "unplanned"),
                "worker_max_seconds" => max(1, (int) config("queue.worker_max_seconds", 300)),
                "idempotency_ttl_seconds" => max(1, (int) config("queue.idempotency_ttl_seconds", 86400)),
                "counts" => $counts,
            ],
        ];
    }
}
