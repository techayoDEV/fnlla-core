<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA QUEUE SOURCE
File: src\Queue\QueueManager.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained file-backed queue runtime for asynchronous tasks.
*/

namespace Fnlla\Php\Queue;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Support\Logger;
use Fnlla\Php\Tenancy\TenantContextManager;
use RuntimeException;
use Throwable;

final class QueueManager
{
    private bool $stopRequested = false;

    public function __construct(
        private Container $container,
        private ?QueueStoreInterface $store = null,
        private ?DatabaseManager $database = null,
        private ?TenantContextManager $tenants = null
    )
    {
        $this->store ??= $this->resolveConfiguredStore();
        $this->container->scoped(JobContext::class, static function (): never {
            throw new RuntimeException("Job context is unavailable outside an active queue work scope.");
        });
    }

    public function push(string $jobClass, array $payload = [], array $context = []): string
    {
        if ($this->database?->hasActiveManagedTransaction()) {
            throw new RuntimeException("Queue dispatch inside a transaction must use pushAfterCommit().");
        }
        return $this->pushNow($jobClass, $payload, $context);
    }

    public function pushAfterCommit(string $jobClass, array $payload = [], array $context = []): void
    {
        if (!$this->database instanceof DatabaseManager) {
            throw new RuntimeException("pushAfterCommit requires the configured DatabaseManager.");
        }
        $this->assertDispatchCapabilities($context);
        $this->database->afterCommit(function () use ($jobClass, $payload, $context): void {
            $this->pushNow($jobClass, $payload, $context);
        });
    }

    private function pushNow(string $jobClass, array $payload, array $context): string
    {
        if (!$this->store instanceof ReliableQueueStoreInterface) {
            $this->assertDispatchCapabilities($context);
            return $this->store->push($jobClass, $payload);
        }

        [$jobType, $jobVersion] = $this->registeredJobForClass($jobClass);
        if ($this->tenants !== null && $this->tenants->mode() !== "none") {
            $authoritative = $this->tenants->requireContext();
            foreach (["tenant_id" => $authoritative->tenantId(), "actor_id" => $authoritative->actorId()] as $key => $value) {
                if (array_key_exists($key, $context) && $context[$key] !== null && (string) $context[$key] !== (string) $value) {
                    throw new RuntimeException("Queued {$key} does not match the active server context.");
                }
                $context[$key] = $value;
            }
        }
        $context["correlation_id"] ??= request_id();
        $context["idempotency_key"] ??= "dispatch-" . bin2hex(random_bytes(16));
        return $this->store->pushWithMetadata($jobClass, $payload, [
            "job_type" => $jobType,
            "job_version" => $jobVersion,
            "context" => $context,
        ]);
    }

    public function work(int $maxJobs = 50, ?int $maxSeconds = null): int
    {
        if (!$this->store instanceof ReliableQueueStoreInterface) {
            return $this->workLegacy($maxJobs, $maxSeconds);
        }

        $processed = 0;
        $startedAt = microtime(true);
        $maximumSeconds = max(1, $maxSeconds ?? (int) config("queue.worker_max_seconds", 300));

        for ($index = 0; $index < max(1, $maxJobs); $index++) {
            if ($this->stopRequested || microtime(true) - $startedAt >= $maximumSeconds) {
                break;
            }
            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
                if ($this->stopRequested) {
                    break;
                }
            }
            $queuedJob = $this->store->pop();

            if ($queuedJob === null) {
                break;
            }

            try {
                $this->container->withinScope(function (Container $scope) use ($queuedJob): void {
                    $this->assertRegisteredEnvelope($queuedJob);
                    $context = new JobContext($this->store, $queuedJob);
                    $scope->scopedInstance(JobContext::class, $context);
                    $context->assertLeaseOwned();

                    $idempotency = $this->store->beginIdempotent($queuedJob);
                    if ($idempotency === "completed") {
                        return;
                    }
                    if ($idempotency === "busy") {
                        throw new RuntimeException("Queue idempotency key is currently owned by another worker.");
                    }

                    $execute = function () use ($scope, $context, $queuedJob): void {
                        $jobClass = (string) $queuedJob["job"];
                        $parameters = (array) $queuedJob["payload"];
                        if (!class_exists($jobClass)) {
                            throw new QueuePayloadException("Queued job class is invalid: " . $jobClass);
                        }

                        $job = $scope->make($jobClass, $parameters);
                        if (!method_exists($job, "handle")) {
                            throw new QueuePayloadException("Queued job must define a handle method: " . $jobClass);
                        }

                        // The handler may inject JobContext and recheck the lease immediately
                        // before each external effect. The worker checks both sides as a floor.
                        $context->assertLeaseOwned();
                        $scope->call([$job, "handle"]);
                        $context->assertLeaseOwned();
                        $this->store->completeIdempotent($queuedJob);
                    };

                    try {
                        if ($this->tenants !== null && $this->tenants->mode() !== "none") {
                            $this->tenants->runForJob($context, $execute);
                        } else {
                            $execute();
                        }
                    } catch (Throwable $exception) {
                        $this->store->releaseIdempotent($queuedJob);
                        throw $exception;
                    }
                });
            } catch (QueuePayloadException $exception) {
                $failedPath = "lease-lost";
                try {
                    if ($this->store->owns($queuedJob)) {
                        $failedPath = $this->store->reject($queuedJob, $exception->getMessage());
                    }
                } catch (Throwable $settlement) {
                    Logger::exception($settlement, ["queue_job_id" => $queuedJob["id"]]);
                }
                Logger::exception($exception, [
                    "queue_job_id" => $queuedJob["id"],
                    "queue_failed_job_file" => $failedPath,
                ]);
                continue;
            } catch (Throwable $exception) {
                try {
                    $this->store->releaseIdempotent($queuedJob);
                } catch (Throwable $settlement) {
                    Logger::exception($settlement, ["queue_job_id" => $queuedJob["id"]]);
                }
                $failedPath = "lease-lost";
                try {
                    if ($this->store->owns($queuedJob)) {
                        $queuedJob["last_error"] = $exception->getMessage();
                        $failedPath = $this->store->fail($queuedJob);
                    }
                } catch (Throwable $settlement) {
                    Logger::exception($settlement, ["queue_job_id" => $queuedJob["id"]]);
                }

                Logger::exception($exception, [
                    "queue_job_id" => $queuedJob["id"],
                    "queue_failed_job_file" => $failedPath,
                ]);
                continue;
            }
            try {
                // Idempotency is marked before acknowledgement. A crash between an
                // external effect and that mark may still repeat the effect; FNLLA
                // therefore promises at-least-once handling, never exactly-once.
                $this->store->complete($queuedJob);
                $processed++;
            } catch (Throwable $exception) {
                Logger::exception($exception, ["queue_job_id" => $queuedJob["id"]]);
            }
        }

        return $processed;
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    private function workLegacy(int $maxJobs, ?int $maxSeconds): int
    {
        if ($this->tenants !== null && $this->tenants->mode() !== "none") {
            throw new RuntimeException(
                "Tenant-aware queue work requires ReliableQueueStoreInterface; no job was reserved."
            );
        }

        $processed = 0;
        $startedAt = microtime(true);
        $maximumSeconds = max(1, $maxSeconds ?? (int) config("queue.worker_max_seconds", 300));

        for ($index = 0; $index < max(1, $maxJobs); $index++) {
            if ($this->stopRequested || microtime(true) - $startedAt >= $maximumSeconds) {
                break;
            }

            $queuedJob = $this->store->pop();
            if ($queuedJob === null) {
                break;
            }

            try {
                $this->container->withinScope(function (Container $scope) use ($queuedJob): void {
                    $jobClass = $queuedJob["job"] ?? null;
                    $parameters = $queuedJob["payload"] ?? null;
                    if (!is_string($jobClass) || !class_exists($jobClass) || !is_array($parameters)) {
                        throw new QueuePayloadException("Queued legacy job class or payload is invalid.");
                    }
                    $this->assertLegacyCapabilities($queuedJob, $jobClass);

                    $job = $scope->make($jobClass, $parameters);
                    if (!method_exists($job, "handle")) {
                        throw new QueuePayloadException("Queued job must define a handle method: " . $jobClass);
                    }
                    $scope->call([$job, "handle"]);
                });
            } catch (Throwable $exception) {
                $queuedJob["last_error"] = $exception->getMessage();
                $failedPath = $this->store->fail($queuedJob);
                Logger::exception($exception, [
                    "queue_job_id" => $queuedJob["id"] ?? "unknown",
                    "queue_failed_job_file" => $failedPath,
                ]);
                continue;
            }

            try {
                $this->store->complete($queuedJob);
                $processed++;
            } catch (Throwable $exception) {
                Logger::exception($exception, ["queue_job_id" => $queuedJob["id"] ?? "unknown"]);
            }
        }

        return $processed;
    }

    /** @param array<string, mixed> $context */
    private function assertDispatchCapabilities(array $context): void
    {
        if ($this->store instanceof ReliableQueueStoreInterface) {
            return;
        }
        if ($context !== [] || ($this->tenants !== null && $this->tenants->mode() !== "none")) {
            throw new RuntimeException(
                "Context-aware queue dispatch requires ReliableQueueStoreInterface; no job was queued."
            );
        }
    }

    private function assertLegacyCapabilities(array $queuedJob, string $jobClass): void
    {
        foreach ($this->jobTypes() as $definition) {
            if ($definition["class"] === $jobClass) {
                throw new QueuePayloadException(
                    "Registered queue jobs require ReliableQueueStoreInterface; the legacy record was rejected."
                );
            }
        }

        // Reserved envelope fields are never downgraded to the six-method legacy
        // contract. An untrusted record can cause a fail-closed rejection, but it
        // cannot opt a handler out of trusted registry or reliable-store policy.
        foreach (["schema", "job_type", "job_version", "context", "reservation", "reserved_by", "reserved_until", "lease_token"] as $field) {
            if (array_key_exists($field, $queuedJob)) {
                throw new QueuePayloadException(
                    "Versioned or context-aware queue records require ReliableQueueStoreInterface; the legacy record was rejected."
                );
            }
        }
    }

    /** @return array{0:string,1:int} */
    private function registeredJobForClass(string $jobClass): array
    {
        foreach ($this->jobTypes() as $type => $definition) {
            if ($definition["class"] === $jobClass) {
                return [$type, $definition["version"]];
            }
        }
        throw new QueuePayloadException("Queued job class is not registered: " . $jobClass);
    }

    private function assertRegisteredEnvelope(array $job): void
    {
        $type = (string) ($job["job_type"] ?? "");
        $definition = $this->jobTypes()[$type] ?? null;
        if ($definition === null || $definition["class"] !== ($job["job"] ?? null)
            || $definition["version"] !== ($job["job_version"] ?? null)) {
            throw new QueuePayloadException("Queued job type or version is not registered.");
        }
    }

    /** @return array<string, array{class:string,version:int}> */
    private function jobTypes(): array
    {
        $configured = config("queue.job_types", []);
        if (!is_array($configured)) {
            throw new QueuePayloadException("Queue job type registry must be an array.");
        }
        $result = [];
        foreach ($configured as $type => $definition) {
            if (!is_string($type) || preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $type) !== 1
                || !is_array($definition) || !is_string($definition["class"] ?? null)
                || ($definition["version"] ?? null) !== JobEnvelope::VERSION) {
                throw new QueuePayloadException("Invalid queue job type registration.");
            }
            $result[$type] = ["class" => $definition["class"], "version" => $definition["version"]];
        }
        return $result;
    }

    private function resolveConfiguredStore(): QueueStoreInterface
    {
        if (app()->has(QueueStoreInterface::class)) {
            return app(QueueStoreInterface::class);
        }
        if ((string) config("queue.default", "file") !== "file") {
            throw new RuntimeException("Configured queue store is not registered. Refusing to silently fall back to local files.");
        }
        return new FileQueueStore(storage_path((string) config("queue.connections.file.path", "framework/queue")));
    }
}
