<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

/** Optional delivery capability. Existing ActionStoreInterface adapters remain valid. */
interface ReliableOutboxStoreInterface extends ActionStoreInterface
{
    /** Claim one committed, due or expired message exclusively; null means none.
     * Attempts advance durably on claim, including crashed/expired deliveries.
     * @return array{id:string,kind:string,name:string,payload:?array,token:string,attempts:int}|null
     */
    public function claimDelivery(int $leaseSeconds, int $maximumAttempts): ?array;
    public function ownsDelivery(string $id, string $token): bool;
    /** Reject expired/stale tokens; atomically settle state and mark the outbox published. */
    public function acknowledgeDelivery(string $id, string $token): void;
    public function failDelivery(string $id, string $token, string $code, bool $retryable, int $maximumAttempts, int $delaySeconds): void;
    /** Bounded metadata only, without payloads, claim tokens or raw exceptions.
     * @return list<array<string, mixed>>
     */
    public function deliveryStatus(int $limit = 100): array;
    /** Compare-and-set failed/unpublished to pending; record the explicit retry. */
    public function retryDelivery(string $id): bool;
}
