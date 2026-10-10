<?php

declare(strict_types=1);

namespace Fnlla\Php\Events;

use Fnlla\Php\Actions\ActionStoreInterface;
use Fnlla\Php\Actions\ReliableOutboxStoreInterface;
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
        if ($this->store instanceof ReliableOutboxStoreInterface && (bool) config("actions.reliable_outbox", false)) {
            return (new OutboxWorker($this->store, $this->audit, $this->events))->work($limit)["published"];
        }
        $published = 0;
        $failures = 0;
        foreach ($this->store->pending($limit) as $message) {
            try {
            if ($message["kind"] === "audit") {
                $this->audit->record($this->auditEvent($message["payload"]));
            } elseif ($message["kind"] === "domain_event") {
                $this->events->publish(DomainEvent::fromArray($message["payload"]));
            } else {
                throw new RuntimeException("Unknown outbox message kind.");
            }
            $this->store->markPublished($message["id"]);
            $published++;
            } catch (\Throwable $error) {
                $failures++;
                \Fnlla\Php\Exceptions\ExceptionReporting::report($error, ['outbox_message_id' => $message['id'] ?? 'unknown']);
            }
        }
        if ($failures > 0) { throw new RuntimeException('Legacy outbox delivery failed for ' . $failures . ' message(s); migrate to reliable delivery for quarantine and retry.'); }
        return $published;
    }

    /** Durable records remain pending; delivery failure cannot undo a committed Action. */
    public function publishAfterCommit(): void
    {
        try { $this->publishPending(); }
        catch (\Throwable $error) { \Fnlla\Php\Exceptions\ExceptionReporting::report($error, ['phase' => 'outbox_after_commit']); }
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
