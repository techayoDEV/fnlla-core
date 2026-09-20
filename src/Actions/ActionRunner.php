<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\AccessControl;
use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\DomainEvent;
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

        $this->access->authorize($definition->permission, $resource, $actor, $tenant);
        $normalized = $this->container->call($definition->validator, [
            "input" => $input,
            "context" => $context,
            "resource" => $resource,
        ]);
        if (!is_array($normalized)) {
            throw new RuntimeException("Action validator must return a normalized array.");
        }
        $requestHash = hash("sha256", json_encode($this->canonical($normalized), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $idempotencyKey = hash("sha256", implode("\0", [
            $definition->id,
            $context->actorId ?? "system",
            $context->tenantId ?? "single",
            $context->correlationId,
        ]));

        return $this->database->transaction(function (DatabaseManager $database) use (
            $definition, $normalized, $resource, $context, $requestHash, $idempotencyKey
        ): ActionResult {
            $stored = $this->store->claim($idempotencyKey, $definition->id, $requestHash, $context);
            if ($stored !== null) {
                $database->afterCommit(fn (): int => $this->outbox->publishPending());
                return ActionResult::replay($stored);
            }

            $mutation = $this->container->call($definition->handler, [
                "input" => $normalized,
                "context" => $context,
                "resource" => $resource,
                "database" => $database,
            ]);
            if (!$mutation instanceof ActionMutation) {
                throw new RuntimeException("Action handler must return ActionMutation.");
            }

            $occurredAt = gmdate(DATE_ATOM);
            $eventIds = [];
            foreach ($mutation->events as $index => $event) {
                $name = (string) $event["name"];
                if (!in_array($name, $definition->events, true)) {
                    throw new RuntimeException("Action emitted an undeclared domain event: {$name}.");
                }
                $eventId = "evt-" . hash("sha256", $idempotencyKey . "\0" . $name . "\0" . $index);
                $domainEvent = new DomainEvent(
                    $eventId,
                    $name,
                    (int) $event["payload_version"],
                    $occurredAt,
                    $definition->subjectType,
                    $mutation->subjectId,
                    $context,
                    (array) $event["payload"]
                );
                $this->store->append($eventId, "domain_event", $name, $domainEvent->toArray());
                $eventIds[] = $eventId;
            }

            $auditId = "audit-" . hash("sha256", $idempotencyKey);
            $audit = new AuditEvent(
                $definition->id,
                $context->source,
                $context->actorId,
                $definition->subjectType,
                $mutation->subjectId,
                $context->tenantId,
                $context->correlationId,
                $mutation->before,
                $mutation->after,
                $occurredAt
            );
            $this->store->append($auditId, "audit", $definition->id, $audit->toArray());
            $result = new ActionResult($definition->id, $mutation->subjectId, $mutation->result, $eventIds);
            $this->store->complete($idempotencyKey, $result->toArray());
            $database->afterCommit(fn (): int => $this->outbox->publishPending());
            return $result;
        });
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

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }
}
