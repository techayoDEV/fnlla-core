<?php

declare(strict_types=1);

namespace Fnlla\Php\Tenancy;

interface TenantIdentityResolverInterface
{
    public function findActor(string $actorId): ?array;

    public function resolveTenant(array $actor, string $mode, ?string $serverTenantId = null): ?string;
}
