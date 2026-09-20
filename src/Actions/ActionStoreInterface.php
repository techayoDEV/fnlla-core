<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

interface ActionStoreInterface
{
    /**
     * Claim an idempotency key in the current transaction.
     * Returns a completed prior result, or null when this transaction owns a new claim.
     * @return array<string, mixed>|null
     */
    public function claim(string $idempotencyKey, string $actionId, string $requestHash, ActionContext $context): ?array;

    /** @param array<string, mixed> $result */
    public function complete(string $idempotencyKey, array $result): void;

    /** @param array<string, mixed> $payload */
    public function append(string $id, string $kind, string $name, array $payload): void;

    /** @return list<array{id:string,kind:string,name:string,payload:array<string,mixed>}> */
    public function pending(int $limit = 100): array;

    public function markPublished(string $id): void;
}
