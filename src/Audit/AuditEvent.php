<?php

declare(strict_types=1);

namespace Fnlla\Php\Audit;

use InvalidArgumentException;

final readonly class AuditEvent
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function __construct(
        public string $action,
        public string $source,
        public ?string $actorId,
        public string $subjectType,
        public string $subjectId,
        public ?string $tenantId,
        public string $correlationId,
        public array $before = [],
        public array $after = [],
        public ?string $occurredAt = null
    ) {
        self::identifier($this->action, "action");
        self::identifier($this->source, "source");
        self::identifier($this->subjectType, "subject type");
        self::identifier($this->subjectId, "subject ID", 160);
        self::identifier($this->correlationId, "correlation ID", 160);
        if ($this->actorId !== null) {
            self::identifier($this->actorId, "actor ID", 160);
        }
        if ($this->tenantId !== null) {
            self::identifier($this->tenantId, "tenant ID", 160);
        }
        if ($this->occurredAt !== null && strtotime($this->occurredAt) === false) {
            throw new InvalidArgumentException("Invalid audit occurrence timestamp.");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            "schema" => "fnlla.audit-event.v1",
            "occurred_at" => $this->occurredAt ?? gmdate(DATE_ATOM),
            "action" => $this->action,
            "source" => $this->source,
            "actor" => ["id" => $this->actorId],
            "subject" => ["type" => $this->subjectType, "id" => $this->subjectId],
            "tenant_id" => $this->tenantId,
            "correlation_id" => $this->correlationId,
            "before" => $this->before,
            "after" => $this->after,
        ];
    }

    private static function identifier(string $value, string $label, int $maximum = 128): void
    {
        if ($value === "" || strlen($value) > $maximum
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@\\\\\/-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid audit {$label}.");
        }
    }
}
