<?php

declare(strict_types=1);

namespace Fnlla\Php\Auth\Authorization;

use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Tenancy\TenantContext;
use InvalidArgumentException;

final class AccessControl
{
    public function __construct(private AuthManager $auth, private PolicyRegistry $policies)
    {
    }

    public function allows(
        string $permission,
        mixed $resource = null,
        ?array $actor = null,
        ?TenantContext $tenant = null
    ): bool {
        self::permission($permission);
        $actor ??= $this->auth->user();
        if ($actor === null || ($actor["active"] ?? true) !== true || !empty($actor["revoked_at"])) {
            return false;
        }

        $granted = [];
        $roles = $this->actorRoles($actor);
        $definitions = config("security.authorization.roles", []);
        if (!is_array($definitions)) {
            return false;
        }
        foreach ($roles as $role) {
            $definition = $definitions[$role] ?? null;
            if (!is_array($definition) || !is_array($definition["permissions"] ?? null)) {
                continue;
            }
            foreach ($definition["permissions"] as $candidate) {
                if (is_string($candidate)) {
                    $granted[$candidate] = true;
                }
            }
        }
        if (!isset($granted[$permission]) && !isset($granted["*"])) {
            return false;
        }
        return $resource === null || $this->policies->allows($permission, $actor, $resource, $tenant);
    }

    public function authorize(
        string $permission,
        mixed $resource = null,
        ?array $actor = null,
        ?TenantContext $tenant = null
    ): void {
        if (!$this->allows($permission, $resource, $actor, $tenant)) {
            throw new AuthorizationException("This action is unauthorized.");
        }
    }

    /** @return list<string> */
    private function actorRoles(array $actor): array
    {
        $field = (string) config("security.authorization.role_field", "role");
        $roles = $actor["roles"] ?? ($actor[$field] ?? []);
        if (is_string($roles)) {
            $roles = [$roles];
        }
        if (!is_array($roles)) {
            return [];
        }
        $roles = array_values(array_unique(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $role) === 1
        )));
        sort($roles, SORT_STRING);
        return $roles;
    }

    private static function permission(string $permission): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $permission) !== 1 && $permission !== "*") {
            throw new InvalidArgumentException("Invalid permission identifier.");
        }
    }
}
