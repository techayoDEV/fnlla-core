<?php

declare(strict_types=1);

namespace Fnlla\Php\Queue;

/**
 * Opt-in queue capabilities added after the published v2.2.4 store contract.
 *
 * Legacy QueueStoreInterface implementations remain valid for basic push/work
 * flows. Context-aware dispatch and JobContext require this explicit contract.
 */
interface ReliableQueueStoreInterface extends QueueStoreInterface
{
    /** @param array<string, mixed> $metadata */
    public function pushWithMetadata(string $jobClass, array $payload = [], array $metadata = []): string;

    public function reject(array $job, string $reason): string;

    public function owns(array $job): bool;

    /** @return array<string, mixed> */
    public function renew(array $job, int $leaseSeconds): array;

    /** @return 'untracked'|'claimed'|'busy'|'completed' */
    public function beginIdempotent(array $job): string;

    public function completeIdempotent(array $job): void;

    public function releaseIdempotent(array $job): void;
}
