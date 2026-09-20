<?php

declare(strict_types=1);

namespace Fnlla\Php\Auth\Authorization;

use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Audit\AuditLoggerInterface;

final class RoleAssignmentGuard
{
    public function __construct(private AccessControl $access, private ?AuditLoggerInterface $audit = null)
    {
    }

    public function authorize(array $actor, string|int $targetActorId, string $role, string $source = "system"): void
    {
        $actorKey = (string) config("auth.providers.users.key", "id");
        $actorId = $actor[$actorKey] ?? null;
        $actorId = is_string($actorId) || is_int($actorId) ? (string) $actorId : null;
        $target = (string) $targetActorId;
        $allowedRoles = config("security.authorization.assignable_roles", []);
        $authorized = $actorId !== null && !hash_equals($actorId, $target)
            && is_array($allowedRoles) && in_array($role, $allowedRoles, true)
            && $this->access->allows("roles.assign", null, $actor);
        $this->audit?->record(new AuditEvent(
            $authorized ? "role.assignment.authorized" : "role.assignment.denied",
            $source,
            $actorId,
            "actor",
            $target,
            null,
            request_id(),
            [],
            ["role" => $role, "outcome" => $authorized ? "allowed" : "denied"]
        ));
        if (!$authorized) {
            throw new AuthorizationException("Role assignment is unauthorized.");
        }
    }
}
