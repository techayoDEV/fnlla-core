<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA QUEUE SOURCE
File: src\Queue\QueueStoreInterface.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Defines the queue storage boundary so FNLLA can keep file queues as the
  default while allowing distributed backends later.
*/

namespace Fnlla\Php\Queue;

interface QueueStoreInterface
{
    /** @param array<string, mixed> $metadata */
    public function push(string $jobClass, array $payload = [], array $metadata = []): string;

    /**
     * Reserve, do not destructively dequeue. Built-in stores recover expired leases.
     * @return array{id:string,job:string,payload:array,source:mixed}|null
     */
    public function pop(): ?array;

    public function complete(array $job): void;

    public function fail(array $job): string;

    public function reject(array $job, string $reason): string;

    public function owns(array $job): bool;

    /** @return array<string, mixed> */
    public function renew(array $job, int $leaseSeconds): array;

    /** @return 'untracked'|'claimed'|'busy'|'completed' */
    public function beginIdempotent(array $job): string;

    public function completeIdempotent(array $job): void;

    public function releaseIdempotent(array $job): void;

    public function pendingCount(): int;

    public function failedCount(): int;
}
