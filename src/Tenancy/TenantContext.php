<?php

declare(strict_types=1);

namespace Fnlla\Php\Tenancy;

use InvalidArgumentException;

final readonly class TenantContext
{
    public function __construct(
        private string $mode,
        private ?string $tenantId,
        private ?string $actorId,
        private string $correlationId,
        private bool $bypass = false
    ) {
        if (!in_array($this->mode, ["none", "organization", "custom"], true)) {
            throw new InvalidArgumentException("Unsupported tenant mode.");
        }
        if ($this->mode !== "none" && $this->tenantId === null) {
            throw new InvalidArgumentException("A tenant ID is required in multi-tenant mode.");
        }
        foreach (["tenant ID" => $this->tenantId, "actor ID" => $this->actorId, "correlation ID" => $this->correlationId] as $label => $value) {
            if ($value !== null && ($value === "" || strlen($value) > 160
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@\\\\\/-]*$/D', $value) !== 1)) {
                throw new InvalidArgumentException("Invalid {$label}.");
            }
        }
    }

    public function mode(): string { return $this->mode; }
    public function tenantId(): ?string { return $this->tenantId; }
    public function actorId(): ?string { return $this->actorId; }
    public function correlationId(): string { return $this->correlationId; }
    public function isBypass(): bool { return $this->bypass; }
}
