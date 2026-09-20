<?php

declare(strict_types=1);

namespace Fnlla\Php\Queue;

use RuntimeException;

final class JobContext
{
    /** @param array<string, mixed> $job */
    public function __construct(private QueueStoreInterface $store, private array $job)
    {
    }

    public function jobId(): string
    {
        return (string) $this->job["id"];
    }

    public function jobType(): string
    {
        return (string) $this->job["job_type"];
    }

    public function correlationId(): string
    {
        return (string) $this->job["context"]["correlation_id"];
    }

    public function tenantId(): ?string
    {
        $value = $this->job["context"]["tenant_id"] ?? null;
        return is_string($value) ? $value : null;
    }

    public function actorId(): ?string
    {
        $value = $this->job["context"]["actor_id"] ?? null;
        return is_string($value) ? $value : null;
    }

    public function idempotencyKey(): ?string
    {
        $value = $this->job["context"]["idempotency_key"] ?? null;
        return is_string($value) ? $value : null;
    }

    public function attempt(): int
    {
        return (int) ($this->job["attempts"] ?? 0);
    }

    public function assertLeaseOwned(): void
    {
        if (!$this->store->owns($this->job)) {
            throw new RuntimeException("Queue lease is expired, missing or owned by another worker.");
        }
    }

    public function renewLease(?int $seconds = null): void
    {
        $this->job = $this->store->renew(
            $this->job,
            $seconds ?? max(1, (int) config("queue.visibility_timeout_seconds", 300))
        );
    }
}
