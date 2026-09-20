<?php

declare(strict_types=1);

namespace Fnlla\Php\Tenancy;

use Fnlla\Php\Auth\UserProviderInterface;

final class UserProviderTenantIdentityResolver implements TenantIdentityResolverInterface
{
    public function __construct(private UserProviderInterface $users)
    {
    }

    public function findActor(string $actorId): ?array
    {
        $actor = $this->users->findById($actorId);
        return $this->active($actor) ? $actor : null;
    }

    public function resolveTenant(array $actor, string $mode, ?string $serverTenantId = null): ?string
    {
        if (!$this->active($actor)) {
            return null;
        }
        if ($mode === "none") {
            return null;
        }
        if (!in_array($mode, ["organization", "custom"], true)) {
            return null;
        }

        $field = $mode === "organization"
            ? (string) config("security.tenancy.organization_field", "organization_id")
            : (string) config("security.tenancy.custom_field", "tenant_id");
        $primary = $actor[$field] ?? null;
        $available = [];
        if (is_string($primary) && $this->validId($primary)) {
            $available[] = $primary;
        }
        $additionalField = (string) config("security.tenancy.additional_field", "tenant_ids");
        foreach ((array) ($actor[$additionalField] ?? []) as $tenantId) {
            if (is_string($tenantId) && $this->validId($tenantId)) {
                $available[] = $tenantId;
            }
        }
        $available = array_values(array_unique($available));
        if ($serverTenantId === null) {
            return $available[0] ?? null;
        }
        return $this->validId($serverTenantId) && in_array($serverTenantId, $available, true)
            ? $serverTenantId
            : null;
    }

    private function active(?array $actor): bool
    {
        return $actor !== null && ($actor["active"] ?? true) === true && empty($actor["revoked_at"]);
    }

    private function validId(string $value): bool
    {
        return $value !== "" && strlen($value) <= 160
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@\\\\\/-]*$/D', $value) === 1;
    }
}
