<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\DomainEvent;
use Fnlla\Php\Events\OutboxProcessor;
use RuntimeException;

/** @internal Shared transaction engine; callers own authentication and authorization. */
final class ActionTransaction
{
    public function __construct(
        private Container $container,
        private DatabaseManager $database,
        private ActionStoreInterface $store,
        private OutboxProcessor $outbox
    ) {}

    public function execute(
        ActionDefinition $definition,
        array $normalized,
        ActionContext $context,
        mixed $resource = null,
        ?callable $validateOutput = null,
        string $receiptScope = '',
        ?ApplicationContext $application = null,
        ?string $resourceIdentity = null
    ): ActionResult {
        $requestHash = hash("sha256", json_encode($this->canonical($receiptScope === "" ? $normalized : ["scope" => $receiptScope, "input" => $normalized, "resource" => $resourceIdentity]), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $idempotencyKey = hash("sha256", implode("\0", [
            $definition->id,
            $receiptScope === "" ? ($context->actorId ?? "system") : $receiptScope . "\0" . ($context->actorId ?? "system"),
            $context->tenantId ?? "single",
            $context->correlationId,
        ]));

        return $this->database->transaction(function (DatabaseManager $database) use (
            $definition, $normalized, $resource, $context, $requestHash, $idempotencyKey, $validateOutput, $application
        ): ActionResult {
            $stored = $this->store->claim($idempotencyKey, $definition->id, $requestHash, $context);
            if ($stored !== null) {
                $replay = ActionResult::replay($stored);
                if ($validateOutput !== null) { $validateOutput($replay->value); }
                $database->afterCommit(fn (): int => $this->outbox->publishPending());
                return $replay;
            }

            $mutation = $this->container->call($definition->handler, array_merge([
                "input" => $normalized,
                "context" => $context,
                "resource" => $resource,
                "database" => $database,
            ], $application === null ? [] : ["application" => $application]));
            if (!$mutation instanceof ActionMutation) {
                throw new RuntimeException("Action handler must return ActionMutation.");
            }

            if ($validateOutput !== null) { $validateOutput($mutation->result); }

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
