<?php
declare(strict_types=1);
namespace App\Policies;
use Fnlla\Php\Auth\Authorization\OwnershipPolicy;
use Fnlla\Php\Tenancy\TenantContext;
final class WorkOrderPolicy {
    public function __invoke(array $actor, mixed $resource, string $permission, ?TenantContext $tenant): bool {
        return $permission === "work-orders.update" && $tenant?->mode() === "organization"
            && ($actor["active"] ?? true) === true && empty($actor["revoked_at"])
            && (new OwnershipPolicy())($actor, $resource, $permission, $tenant);
    }
}
