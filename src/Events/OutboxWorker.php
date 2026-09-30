<?php

declare(strict_types=1);

namespace Fnlla\Php\Events;

use Fnlla\Php\Actions\ReliableOutboxStoreInterface;
use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Audit\AuditLoggerInterface;
use Fnlla\Php\Container\Container;
use RuntimeException;
use Throwable;

final class OutboxWorker
{
    public function __construct(private ReliableOutboxStoreInterface $store, private AuditLoggerInterface $audit,
        private DomainEventBus $events, private ?Container $container = null)
    {
    }

    /** @return array{published:int,retried:int,failed:int} */
    public function work(int $limit = 100, int $maxSeconds = 30): array
    {
        $report = ["published" => 0, "retried" => 0, "failed" => 0];
        $deadline = hrtime(true) + max(1, min(3600, $maxSeconds)) * 1_000_000_000;
        $attempts = max(1, min(100, (int) config("actions.outbox.max_attempts", 5)));
        $lease = max(1, min(3600, (int) config("actions.outbox.lease_seconds", 60)));
        for ($index = 0; $index < max(1, min(10000, $limit)) && hrtime(true) < $deadline; $index++) {
            $message = $this->store->claimDelivery($lease, $attempts);
            if ($message === null) { break; }
            $id = (string) $message["id"];
            $token = (string) $message["token"];
            $code = "delivery_failed";
            $retryable = true;
            try {
                if (($message["attempts"] ?? 0) > $attempts) {
                    $code = "attempts_exhausted"; $retryable = false;
                    throw new RuntimeException("Delivery attempts exhausted.");
                }
                if (!is_array($message["payload"] ?? null)) {
                    $code = "invalid_payload"; $retryable = false;
                    throw new RuntimeException("Invalid outbox payload.");
                }
                if (!in_array($message["kind"] ?? null, ["audit", "domain_event"], true)) {
                    $code = "invalid_kind"; $retryable = false;
                    throw new RuntimeException("Invalid outbox kind.");
                }
                try {
                    $event = $this->event($message);
                } catch (Throwable $error) {
                    $code = "invalid_payload"; $retryable = false;
                    throw $error;
                }
                if (!$this->store->ownsDelivery($id, $token)) { throw new RuntimeException("Outbox lease lost."); }
                $publish = function () use ($event): void {
                    if ($event instanceof DomainEvent) { $this->events->publish($event); } else { $this->audit->record($event); }
                };
                if ($this->container !== null) { $this->container->withinScope($publish); } else { $publish(); }
                $this->store->acknowledgeDelivery($id, $token);
                $report["published"]++;
            } catch (Throwable) {
                $base = max(1, min(3600, (int) config("actions.outbox.retry_seconds", 5)));
                $delay = min(3600, $base * (2 ** min(10, max(0, (int) $message["attempts"] - 1))));
                // Bounded jitter prevents synchronized retries; error messages never persist.
                $delay = min(3600, $delay + random_int(0, min(30, (int) ($delay / 4))));
                $this->store->failDelivery($id, $token, $code, $retryable, $attempts, (int) $delay);
                $report[$retryable && (int) $message["attempts"] < $attempts ? "retried" : "failed"]++;
            }
        }
        return $report;
    }

    private function event(array $message): DomainEvent|AuditEvent
    {
        $payload = $message["payload"];
        $domain = $message["kind"] === "domain_event";
        if (($payload["schema"] ?? null) !== ($domain ? "fnlla.domain-event.v1" : "fnlla.audit-event.v1")
            || !is_array($payload["subject"] ?? null) || !is_string($payload["occurred_at"] ?? null)) {
            throw new RuntimeException("Invalid outbox envelope.");
        }
        foreach (["type", "id"] as $key) {
            if (!is_string($payload["subject"][$key] ?? null)) { throw new RuntimeException("Invalid event subject."); }
        }
        $context = $domain ? ($payload["context"] ?? null) : $payload;
        if (!is_array($context) || !is_string($context["source"] ?? null) || !is_string($context["correlation_id"] ?? null)) {
            throw new RuntimeException("Invalid event context.");
        }
        $actor = $domain ? ($context["actor_id"] ?? null) : ($payload["actor"]["id"] ?? null);
        if (($actor !== null && !is_string($actor))
            || (($context["tenant_id"] ?? null) !== null && !is_string($context["tenant_id"]))) {
            throw new RuntimeException("Invalid event identity.");
        }
        if ($message["kind"] === "domain_event") {
            if (!is_string($payload["id"] ?? null) || !is_string($payload["name"] ?? null)
                || !is_int($payload["payload_version"] ?? null) || !is_array($payload["payload"] ?? null)) {
                throw new RuntimeException("Invalid domain event data.");
            }
            return DomainEvent::fromArray($payload);
        }
        if (!is_string($payload["action"] ?? null) || !is_array($payload["actor"] ?? null)
            || !is_array($payload["before"] ?? null) || !is_array($payload["after"] ?? null)) {
            throw new RuntimeException("Invalid audit event data.");
        }
        $actor = is_array($payload["actor"] ?? null) ? $payload["actor"] : [];
        $subject = is_array($payload["subject"] ?? null) ? $payload["subject"] : [];
        return new AuditEvent(
            (string) ($payload["action"] ?? ""), (string) ($payload["source"] ?? ""),
            isset($actor["id"]) ? (string) $actor["id"] : null,
            (string) ($subject["type"] ?? ""), (string) ($subject["id"] ?? ""),
            isset($payload["tenant_id"]) ? (string) $payload["tenant_id"] : null,
            (string) ($payload["correlation_id"] ?? ""), (array) ($payload["before"] ?? []),
            (array) ($payload["after"] ?? []), (string) ($payload["occurred_at"] ?? "")
        );
    }
}
