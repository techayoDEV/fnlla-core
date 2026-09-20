<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Tenancy\TenantContext;
use InvalidArgumentException;

final readonly class ActionContext
{
    public const SOURCES = ["human", "system", "api", "agent"];

    public function __construct(
        public ?string $actorId,
        public ?string $tenantId,
        public string $source,
        public string $correlationId
    ) {
        if (!in_array($this->source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unsupported action source.");
        }
        if ($this->source !== "system" && $this->actorId === null) {
            throw new InvalidArgumentException("Non-system actions require an authenticated actor.");
        }
        foreach (["actor ID" => $this->actorId, "tenant ID" => $this->tenantId, "correlation ID" => $this->correlationId] as $label => $value) {
            if ($value !== null && ($value === "" || strlen($value) > 160
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@\\\\\/-]*$/D', $value) !== 1)) {
                throw new InvalidArgumentException("Invalid action {$label}.");
            }
        }
    }

    public static function fromTenant(TenantContext $tenant, string $source): self
    {
        return new self($tenant->actorId(), $tenant->tenantId(), $source, $tenant->correlationId());
    }

    /** @return array{actor_id:?string,tenant_id:?string,source:string,correlation_id:string} */
    public function toArray(): array
    {
        return [
            "actor_id" => $this->actorId,
            "tenant_id" => $this->tenantId,
            "source" => $this->source,
            "correlation_id" => $this->correlationId,
        ];
    }
}
