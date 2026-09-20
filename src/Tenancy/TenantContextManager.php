<?php

declare(strict_types=1);

namespace Fnlla\Php\Tenancy;

use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Audit\AuditLoggerInterface;
use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\AccessControl;
use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Queue\JobContext;
use RuntimeException;

final class TenantContextManager
{
    private ?TenantContext $current = null;

    public function __construct(
        private AuthManager $auth,
        private TenantIdentityResolverInterface $identities,
        private AccessControl $access,
        private ?AuditLoggerInterface $audit = null
    ) {
    }

    public function mode(): string
    {
        $mode = (string) config("security.tenancy.mode", "none");
        if (!in_array($mode, ["none", "organization", "custom"], true)) {
            throw new RuntimeException("Unsupported tenant mode: " . $mode);
        }
        return $mode;
    }

    public function current(): ?TenantContext
    {
        return $this->current;
    }

    public function requireContext(): TenantContext
    {
        if ($this->current !== null) {
            return $this->current;
        }
        if ($this->mode() === "none") {
            return new TenantContext("none", null, null, request_id());
        }
        throw new AuthorizationException("Tenant context is required.");
    }

    public function runForAuthenticated(callable $callback, ?string $serverTenantId = null): mixed
    {
        $actor = $this->auth->user();
        if ($actor === null || ($actor["active"] ?? true) !== true || !empty($actor["revoked_at"])) {
            throw new AuthorizationException("An active authenticated actor is required.");
        }
        $actorId = $this->actorId($actor);
        $mode = $this->mode();
        $tenantId = $this->identities->resolveTenant($actor, $mode, $serverTenantId);
        if ($mode !== "none" && $tenantId === null) {
            throw new AuthorizationException("The server could not resolve an authorized tenant.");
        }
        return $this->within(new TenantContext($mode, $tenantId, $actorId, request_id()), $callback);
    }

    public function runForJob(JobContext $job, callable $callback): mixed
    {
        $mode = $this->mode();
        if ($mode === "none") {
            return $this->within(new TenantContext("none", null, $job->actorId(), $job->correlationId()), $callback);
        }
        $actorId = $job->actorId();
        $tenantId = $job->tenantId();
        if ($actorId === null || $tenantId === null) {
            throw new AuthorizationException("Multi-tenant jobs require an actor and tenant context.");
        }
        $actor = $this->identities->findActor($actorId);
        if ($actor === null || $this->identities->resolveTenant($actor, $mode, $tenantId) !== $tenantId) {
            throw new AuthorizationException("Queued tenant context is no longer authorized.");
        }
        return $this->within(new TenantContext($mode, $tenantId, $actorId, $job->correlationId()), $callback);
    }

    public function runBypass(string $tenantId, string $reason, callable $callback, string $source = "system"): mixed
    {
        if ($this->mode() === "none") {
            throw new AuthorizationException("Tenant bypass is unavailable in single-tenant mode.");
        }
        $actor = $this->auth->user();
        $actorId = $actor !== null ? $this->actorId($actor) : null;
        $correlationId = request_id();
        if ($actor === null || !$this->access->allows("tenancy.bypass", null, $actor)) {
            $this->record("tenancy.bypass.denied", $source, $actorId, $tenantId, $correlationId, ["reason" => $reason]);
            throw new AuthorizationException("Tenant bypass is unauthorized.");
        }
        if (trim($reason) === "") {
            throw new AuthorizationException("Tenant bypass requires an audit reason.");
        }
        $context = new TenantContext($this->mode(), $tenantId, $actorId, $correlationId, true);
        $this->record("tenancy.bypass.used", $source, $actorId, $tenantId, $correlationId, ["reason" => $reason]);
        return $this->within($context, $callback);
    }

    public function within(TenantContext $context, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $context;
        try {
            return $callback($context);
        } finally {
            $this->current = $previous;
        }
    }

    private function actorId(array $actor): string
    {
        $key = (string) config("auth.providers.users.key", "id");
        $id = $actor[$key] ?? null;
        if (!is_string($id) && !is_int($id)) {
            throw new AuthorizationException("Authenticated actor has no valid identity.");
        }
        return (string) $id;
    }

    /** @param array<string, mixed> $after */
    private function record(string $action, string $source, ?string $actorId, string $tenantId, string $correlationId, array $after): void
    {
        $this->audit?->record(new AuditEvent(
            $action, $source, $actorId, "tenant", $tenantId, $tenantId, $correlationId, [], $after
        ));
    }
}
