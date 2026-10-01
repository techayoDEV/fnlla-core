<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Database\PostCommitCallbackException;
use Fnlla\Php\Events\Dispatcher;
use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Validation\ValidationException;
use Throwable;

final class ActionExecutor
{
    public function __construct(
        private Container $container,
        private ActionRegistry $registry,
        private ActionAccess $access,
        private Dispatcher $events,
        private DatabaseManager $database
    ) {}

    public function execute(string $capability, array $input, ApplicationContext $context): ActionResult
    {
        try {
            $this->access->assertContext($context);
            try { $definition = $this->registry->get($capability); }
            catch (\RuntimeException) { throw new ActionException('unavailable'); }
            $metadata = $definition->metadata;
            if ($metadata === null || ($context->audience === 'public' && $metadata->visibility !== 'public')) {
                throw new ActionException('unavailable');
            }
            if (!$this->access->allows($definition, $context)) { throw new ActionException('unauthorized'); }
            self::boundedValue($input, 'invalid_input');
            ActionShape::validate($metadata->input, $input, $context->audience === 'public');
            $resource = $definition->resourceResolver === null ? null : $this->container->call($definition->resourceResolver, [
                'input' => $input, 'application' => $context, 'context' => $context->actionContext(),
            ]);
            if (($definition->resourceResolver !== null && $resource === null) || !$this->access->allows($definition, $context, $resource)) {
                throw new ActionException('unauthorized');
            }
            try {
                $normalized = $this->container->call($definition->validator, [
                    'input' => $input, 'application' => $context, 'context' => $context->actionContext(), 'resource' => $resource,
                ]);
            } catch (ValidationException) { throw new ActionException('invalid_input'); }
            self::boundedValue($normalized, 'invalid_input');
            // Revalidation prevents normalization from silently violating declared types.
            ActionShape::validate($metadata->input, $normalized);
            if ($definition->resourceResolver !== null) {
                $resource = $this->container->call($definition->resourceResolver, [
                    'input' => $normalized, 'application' => $context, 'context' => $context->actionContext(),
                ]);
                if ($resource === null || !$this->access->allows($definition, $context, $resource)) { throw new ActionException('unauthorized'); }
            }
            $resourceIdentity = null;
            if ($resource !== null) {
                // Stable scoped identity is part of the request hash; changing it is not a replay.
                $resourceIdentity = is_array($resource) ? ($resource['id'] ?? null) : null;
                if ((!is_int($resourceIdentity) && !is_string($resourceIdentity)) || (string) $resourceIdentity === ''
                    || strlen((string) $resourceIdentity) > 160) { throw new ActionException('execution_failed'); }
                $resourceIdentity = (string) $resourceIdentity;
            }
            $this->hook('capability.started', $definition->id, $context);
            $validateOutput = static function (array $value) use ($metadata): void {
                self::boundedValue($value, 'invalid_output');
                ActionShape::validate($metadata->output, $value, false, 'invalid_output');
            };
            if ($metadata->kind === 'command') {
                // Explicit scope keeps new receipts separate from published legacy receipt keys.
                $scope = 'capability-v1:' . $context->identityDomain . ':' . $metadata->version;
                $result = $this->container->make(ActionTransaction::class)->execute(
                    $definition, $normalized, $context->actionContext(), $resource, $validateOutput, $scope, $context, $resourceIdentity
                );
            } else {
                $value = $this->container->call($definition->handler, [
                    'input' => $normalized, 'application' => $context, 'context' => $context->actionContext(), 'resource' => $resource,
                ]);
                if (!is_array($value)) { throw new ActionException('invalid_output'); }
                $validateOutput($value);
                $result = new ActionResult($definition->id, '', $value, []);
            }
            $this->hook('capability.succeeded', $definition->id, $context);
            return new ActionResult($result->actionId, $result->subjectId,
                ActionShape::project($metadata->output, $result->value), $result->eventIds, $result->replayed);
        } catch (Throwable $error) {
            $failure = match (true) {
                $error instanceof ActionException => $error,
                $error instanceof AuthorizationException => new ActionException('unauthorized'),
                $error instanceof ValidationException => new ActionException('invalid_input'),
                $error instanceof PostCommitCallbackException => new ActionException('post_commit_failed'),
                default => new ActionException('execution_failed'),
            };
            // Do not echo attacker-selected identifiers or private input in hook metadata.
            if (isset($definition, $metadata) && $metadata !== null) {
                $this->hook('capability.failed', $definition->id, $context, $failure->reason);
            }
            throw $failure;
        }
    }

    private static function boundedValue(mixed $value, string $failure): void
    {
        try { $encoded = json_encode($value, JSON_THROW_ON_ERROR, 32); }
        catch (Throwable) { throw new ActionException($failure); }
        if (!is_array($value) || strlen($encoded) > 1048576) { throw new ActionException($failure); }
    }

    /** Best-effort observation only. Mutation events use the transactional outbox. */
    private function hook(string $name, string $id, ApplicationContext $context, ?string $reason = null): void
    {
        $payload = ['capability' => $id, 'correlation_id' => $context->tenant->correlationId(), 'reason' => $reason];
        $notify = function () use ($name, $payload): void {
            try { $this->events->dispatch($name, $payload); } catch (Throwable) { /* Observers never change the operation outcome. */ }
        };
        // Success inside an outer transaction is observed only after its commit.
        if ($this->database->hasActiveManagedTransaction()) { $this->database->afterCommit($notify); }
        else { $notify(); }
    }
}
