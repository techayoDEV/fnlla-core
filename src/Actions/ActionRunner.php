<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\AccessControl;
use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\OutboxProcessor;
use Fnlla\Php\Tenancy\TenantContext;
use Fnlla\Php\Tenancy\TenantContextManager;
use RuntimeException;

final class ActionRunner
{
    public function __construct(
        private Container $container,
        private DatabaseManager $database,
        private AuthManager $auth,
        private AccessControl $access,
        private TenantContextManager $tenants,
        private ActionRegistry $registry,
        private ActionStoreInterface $store,
        private OutboxProcessor $outbox
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * Source describes origin only. Actor and tenant always come from the active server context.
     */
    public function run(string $actionId, array $input, string $source = "human", mixed $resource = null): ActionResult
    {
        $tenant = $this->tenants->requireContext();
        $actor = $this->auth->user();
        $context = ActionContext::fromTenant($tenant, $source);
        $this->assertTrusted($context, $tenant, $actor);
        $definition = $this->registry->get($actionId);
        if ($definition->metadata !== null) {
            throw new RuntimeException('Metadata capabilities must execute through ActionExecutor.');
        }

        $this->access->authorize($definition->permission, $resource, $actor, $tenant);
        $normalized = $this->container->call($definition->validator, [
            "input" => $input,
            "context" => $context,
            "resource" => $resource,
        ]);
        if (!is_array($normalized)) {
            throw new RuntimeException("Action validator must return a normalized array.");
        }
        return (new ActionTransaction($this->container, $this->database, $this->store, $this->outbox))
            ->execute($definition, $normalized, $context, $resource);
    }

    private function assertTrusted(ActionContext $context, TenantContext $tenant, ?array $actor): void
    {
        $key = (string) config("auth.providers.users.key", "id");
        $actorId = $actor[$key] ?? null;
        if ($actor === null || (!is_string($actorId) && !is_int($actorId))
            || (string) $actorId !== (string) $context->actorId
            || $tenant->tenantId() !== $context->tenantId
            || $tenant->correlationId() !== $context->correlationId) {
            throw new AuthorizationException("Action context does not match the active server identity.");
        }
    }

}
