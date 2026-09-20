<?php

declare(strict_types=1);

namespace Fnlla\Php\Events;

use Fnlla\Php\Actions\ActionContext;
use InvalidArgumentException;

final readonly class DomainEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $name,
        public int $payloadVersion,
        public string $occurredAt,
        public string $subjectType,
        public string $subjectId,
        public ActionContext $context,
        public array $payload
    ) {
        foreach (["event ID" => $this->id, "event name" => $this->name, "subject type" => $this->subjectType, "subject ID" => $this->subjectId] as $label => $value) {
            if ($value === "" || strlen($value) > 160
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@\\\\\/-]*$/D', $value) !== 1) {
                throw new InvalidArgumentException("Invalid domain {$label}.");
            }
        }
        if ($this->payloadVersion < 1 || strtotime($this->occurredAt) === false) {
            throw new InvalidArgumentException("Invalid domain event version or timestamp.");
        }
        json_encode($this->payload, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            "schema" => "fnlla.domain-event.v1",
            "id" => $this->id,
            "name" => $this->name,
            "payload_version" => $this->payloadVersion,
            "occurred_at" => $this->occurredAt,
            "subject" => ["type" => $this->subjectType, "id" => $this->subjectId],
            "context" => $this->context->toArray(),
            "payload" => $this->payload,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $context = is_array($payload["context"] ?? null) ? $payload["context"] : [];
        $subject = is_array($payload["subject"] ?? null) ? $payload["subject"] : [];
        return new self(
            (string) ($payload["id"] ?? ""),
            (string) ($payload["name"] ?? ""),
            (int) ($payload["payload_version"] ?? 0),
            (string) ($payload["occurred_at"] ?? ""),
            (string) ($subject["type"] ?? ""),
            (string) ($subject["id"] ?? ""),
            new ActionContext(
                isset($context["actor_id"]) ? (string) $context["actor_id"] : null,
                isset($context["tenant_id"]) ? (string) $context["tenant_id"] : null,
                (string) ($context["source"] ?? ""),
                (string) ($context["correlation_id"] ?? "")
            ),
            is_array($payload["payload"] ?? null) ? $payload["payload"] : []
        );
    }
}
