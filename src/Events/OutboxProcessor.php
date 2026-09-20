<?php

declare(strict_types=1);

namespace Fnlla\Php\Events;

use Fnlla\Php\Actions\ActionStoreInterface;
use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Audit\AuditLoggerInterface;
use RuntimeException;

final class OutboxProcessor
{
    public function __construct(
        private ActionStoreInterface $store,
        private AuditLoggerInterface $audit,
        private DomainEventBus $events
    ) {
    }

    public function publishPending(int $limit = 100): int
    {
        $published = 0;
        foreach ($this->store->pending($limit) as $message) {
            if ($message["kind"] === "audit") {
                $this->audit->record($this->auditEvent($message["payload"]));
            } elseif ($message["kind"] === "domain_event") {
                $this->events->publish(DomainEvent::fromArray($message["payload"]));
            } else {
                throw new RuntimeException("Unknown outbox message kind.");
            }
            $this->store->markPublished($message["id"]);
            $published++;
        }
        return $published;
    }

    /** @param array<string, mixed> $payload */
    private function auditEvent(array $payload): AuditEvent
    {
        $actor = is_array($payload["actor"] ?? null) ? $payload["actor"] : [];
        $subject = is_array($payload["subject"] ?? null) ? $payload["subject"] : [];
        return new AuditEvent(
            (string) ($payload["action"] ?? ""),
            (string) ($payload["source"] ?? ""),
            isset($actor["id"]) ? (string) $actor["id"] : null,
            (string) ($subject["type"] ?? ""),
            (string) ($subject["id"] ?? ""),
            isset($payload["tenant_id"]) ? (string) $payload["tenant_id"] : null,
            (string) ($payload["correlation_id"] ?? ""),
            is_array($payload["before"] ?? null) ? $payload["before"] : [],
            is_array($payload["after"] ?? null) ? $payload["after"] : [],
            (string) ($payload["occurred_at"] ?? "")
        );
    }
}
