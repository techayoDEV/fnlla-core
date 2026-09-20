<?php

declare(strict_types=1);

namespace Fnlla\Php\Auth\Authorization;

use Fnlla\Php\Tenancy\TenantContext;
use InvalidArgumentException;

final class PolicyRegistry
{
    /** @var array<string, callable> */
    private array $policies = [];

    public function define(string $resourceType, callable $policy): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:\\\\-]{0,159}$/D', $resourceType) !== 1) {
            throw new InvalidArgumentException("Invalid policy resource type.");
        }
        $this->policies[$resourceType] = $policy;
    }

    public function allows(string $permission, array $actor, mixed $resource, ?TenantContext $tenant = null): bool
    {
        $type = $this->resourceType($resource);
        $policy = $type !== null ? ($this->policies[$type] ?? null) : null;
        return $policy !== null && (bool) $policy($actor, $resource, $permission, $tenant);
    }

    private function resourceType(mixed $resource): ?string
    {
        if (is_object($resource)) {
            return $resource::class;
        }
        if (is_array($resource) && is_string($resource["__type"] ?? null)) {
            return $resource["__type"];
        }
        return null;
    }
}
