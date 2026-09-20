<?php

declare(strict_types=1);

namespace Fnlla\Php\Auth\Authorization;

use Fnlla\Php\Tenancy\TenantContext;

final readonly class OwnershipPolicy
{
    public function __construct(
        private string $actorKey = "id",
        private string $ownerKey = "owner_id",
        private string $tenantKey = "tenant_id"
    ) {
    }

    public function __invoke(array $actor, mixed $resource, string $permission, ?TenantContext $tenant): bool
    {
        if (!is_array($resource)) {
            return false;
        }
        $actorId = $actor[$this->actorKey] ?? null;
        $ownerId = $resource[$this->ownerKey] ?? null;
        if ((!is_string($actorId) && !is_int($actorId)) || (string) $actorId !== (string) $ownerId) {
            return false;
        }
        if ($tenant === null || $tenant->mode() === "none") {
            return true;
        }
        return is_string($resource[$this->tenantKey] ?? null)
            && hash_equals((string) $tenant->tenantId(), (string) $resource[$this->tenantKey]);
    }
}
