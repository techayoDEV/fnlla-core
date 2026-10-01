<?php

declare(strict_types=1);

use Fnlla\Php\Actions\{ActionAccess, ActionDefinition, ActionException, ActionExecutor, ActionMetadata, ActionMutation,
    ActionRegistry, ActionShape, ActionTransaction, ApplicationContext, ApplicationContextProviderInterface, ApplicationSchema};
use Fnlla\Php\Auth\{AuthManager, UserProviderInterface};
use Fnlla\Php\Auth\Authorization\{AccessControl, PolicyRegistry};
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\{Dispatcher, DomainEventBus, OutboxProcessor};
use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Session\SessionStore;
use Fnlla\Php\Tenancy\TenantContext;
use Fnlla\Php\Product\{ProductModuleActionsInterface, ProductModuleExtensionInterface, ProductModuleRegistry};

// Main suite supplies the fake PDO/receipt fixtures and Product Module fixture helpers.
$capAssertions = 0;
function cap_assert(bool $value, string $message): void
{
    $GLOBALS['capAssertions']++;
    if (!$value) { throw new RuntimeException($message); }
}
function cap_error(string $reason, callable $call): void
{
    try { $call(); } catch (ActionException $error) {
        cap_assert($reason === $error->reason, 'Unexpected safe failure: ' . $error->reason);
        return;
    }
    throw new RuntimeException('Expected capability failure: ' . $reason);
}
function cap_invalid(callable $call): void
{
    try { $call(); } catch (InvalidArgumentException|RuntimeException) { cap_assert(true, 'Rejected invalid registration.'); return; }
    throw new RuntimeException('Expected invalid registration to fail.');
}
function cap_definition(string $id, string $visibility = 'public', ?callable $handler = null, string $kind = 'query'): ActionDefinition
{
    return new ActionDefinition($id, 'fixture.read', 'fixture', static fn (array $input): array => $input,
        $handler ?? static fn (array $input): array => ['value' => $input['value'], 'secret' => 'PRIVATE'], [],
        new ActionMetadata('Synthetic capability.', [
            'type' => 'object', 'properties' => ['value' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40]], 'required' => ['value'],
        ], ['type' => 'object', 'properties' => ['value' => ['type' => 'string'], 'secret' => ['type' => 'string', 'hidden' => true]], 'required' => ['value']],
            $kind, $visibility));
}
final class CapabilityModuleFixture implements ProductModuleExtensionInterface, ProductModuleActionsInterface
{
    public function serviceBindings(): array { return []; }
    public function routeHandlers(): array { return ['orders.admin' => [ProductModuleFixtureController::class, 'index']]; }
    public function actionDefinitions(): array { return [cap_definition('orders.view')]; }
}

$capConfig = config('security', []);
$capTemp = sys_get_temp_dir() . '/fnlla-capabilities-' . bin2hex(random_bytes(8));
mkdir($capTemp, 0700, true);
try {
    config_set('security.authorization.roles', ['reader' => ['permissions' => ['fixture.read']], 'denied' => ['permissions' => []]]);
    $actor = ['id' => 'actor-one', 'active' => true, 'role' => 'reader'];
    $context = new ApplicationContext($actor, new TenantContext('organization', 'tenant-one', 'actor-one', 'capability-one'));
    $contexts = new class($context) implements ApplicationContextProviderInterface {
        public function __construct(public ApplicationContext $value) {}
        public function current(): ApplicationContext { return $this->value; }
    };
    $users = new class implements UserProviderInterface {
        public function findById(string|int $id): ?array { return null; }
        public function findByCredentials(array $credentials): ?array { return null; }
    };
    $auth = new AuthManager(new SessionStore(), $users, new Hasher());
    $policies = new PolicyRegistry();
    $access = new ActionAccess($contexts, new AccessControl($auth, $policies));
    $container = new Container();
    $registry = new ActionRegistry();
    $pdo = new ActionEventTestPdo();
    $database = DatabaseManager::using($pdo);
    $events = new Dispatcher($container, $database);
    $executor = new ActionExecutor($container, $registry, $access, $events, $database);
    $schema = new ApplicationSchema($registry, $access);
    $registry->register(cap_definition('fixture.read'));
    $registry->register(cap_definition('fixture.private', 'internal'));
    cap_invalid(fn () => $registry->register(cap_definition('fixture.read')));
    cap_invalid(fn () => $registry->registerMany([cap_definition('fixture.batch'), cap_definition('fixture.read')]));
    cap_invalid(fn () => new ActionDefinition('fixture.legacy-resource', 'fixture.read', 'fixture',
        static fn (array $input): array => $input, static fn (): array => [], resourceResolver: static fn (): array => ['id' => 'one']));
    cap_error('unavailable', fn () => $executor->execute('fixture.batch', ['value' => 'one'], $context));
    cap_assert(count($registry->definitions()) === 2, 'Batch registration was not atomic.');
    cap_assert(count($registry->inspect()[0]) === 4, 'Legacy inspect shape changed.');
    $observed = [];
    foreach (['started', 'succeeded', 'failed'] as $stage) {
        $events->listen('capability.' . $stage, static function (string $capability, string $correlation_id, ?string $reason) use (&$observed, $stage): void {
            $observed[] = [$stage, $capability, $correlation_id, $reason];
        });
    }
    $result = $executor->execute('fixture.read', ['value' => 'one'], $context);
    cap_assert($result->value === ['value' => 'one'] && $result->eventIds === [], 'Query result projection failed.');
    cap_assert($pdo->calls === [], 'Query acquired a mutation transaction.');
    cap_assert(array_column($observed, 0) === ['started', 'succeeded'], 'Lifecycle hooks did not execute.');
    $events->listen('capability.succeeded', static function (): never { throw new RuntimeException('Observer failure'); });
    cap_assert($executor->execute('fixture.read', ['value' => 'two'], $context)->value === ['value' => 'two'], 'Observer replaced successful outcome.');
    foreach ([[], ['value' => 42], ['value' => ''], ['value' => 'one', 'role' => 'admin']] as $invalid) {
        cap_error('invalid_input', fn () => $executor->execute('fixture.read', $invalid, $context));
    }
    cap_error('unavailable', fn () => $executor->execute('not.registered', [], $context));
    cap_error('unavailable', fn () => $executor->execute('fixture.private', ['value' => 'one'], $context));
    $forged = new ApplicationContext($actor + ['roles' => ['admin']], $context->tenant);
    cap_error('unauthorized', fn () => $executor->execute('fixture.read', ['value' => 'one'], $forged));
    $foreign = new ApplicationContext($actor, new TenantContext('organization', 'tenant-two', 'actor-one', 'capability-one'));
    cap_error('unauthorized', fn () => $executor->execute('fixture.read', ['value' => 'one'], $foreign));
    $contexts->value = new ApplicationContext(array_replace($actor, ['active' => false]), $context->tenant);
    cap_error('unauthorized', fn () => $executor->execute('fixture.read', ['value' => 'one'], $contexts->value));
    cap_assert($schema->describe($contexts->value)['capabilities'] === [], 'Revoked principal can discover capabilities.');
    $contexts->value = new ApplicationContext(array_replace($actor, ['role' => 'denied']), $context->tenant);
    cap_error('unauthorized', fn () => $executor->execute('fixture.read', ['value' => 'one'], $contexts->value));
    $contexts->value = new ApplicationContext($actor, $context->tenant, 'developer');
    cap_error('unauthorized', fn () => $executor->execute('fixture.read', ['value' => 'one'], $contexts->value));
    $contexts->value = $context;
    $public = $schema->describe($context);
    cap_assert(array_column($public['capabilities'], 'id') === ['fixture.read'], 'Internal metadata leaked.');
    $encoded = json_encode($public, JSON_THROW_ON_ERROR);
    cap_assert(!str_contains($encoded, 'secret') && !str_contains($encoded, 'handler') && !str_contains($encoded, 'PRIVATE'), 'Hidden metadata leaked.');
    cap_assert($encoded === json_encode($schema->describe($context), JSON_THROW_ON_ERROR), 'Schema projection is not deterministic.');
    cap_assert(count($schema->inspect()['capabilities']) === 2, 'Local inspect omitted internal operation.');
    cap_error('unauthorized', fn () => $schema->describe($forged));
    $contexts->value = new ApplicationContext($actor, $context->tenant, audience: 'internal');
    cap_assert($executor->execute('fixture.private', ['value' => 'one'], $contexts->value)->value === ['value' => 'one'], 'Trusted internal call failed.');
    $contexts->value = $context;
    $registry->register(cap_definition('fixture.failure', handler: static function (): never { throw new RuntimeException('PASSWORD=PRIVATE'); }));
    cap_error('execution_failed', fn () => $executor->execute('fixture.failure', ['value' => 'one'], $context));
    cap_assert(!str_contains(json_encode($observed), 'PASSWORD'), 'Hooks leaked exception details.');
    $registry->register(cap_definition('fixture.bad-output', handler: static fn (): array => ['unknown' => 'one']));
    cap_error('invalid_output', fn () => $executor->execute('fixture.bad-output', ['value' => 'one'], $context));

    foreach ([['type' => 'unknown'], ['type' => 'string', 'pattern' => '.*'], ['type' => 'string', 'minLength' => 2, 'maxLength' => 1],
        ['type' => 'object', 'properties' => [], 'required' => ['missing']]] as $badShape) {
        cap_invalid(fn () => ActionShape::assertDefinition($badShape));
    }
    $nested = ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'items' => [
        'type' => 'object', 'properties' => ['visible' => ['type' => 'integer'], 'hidden' => ['type' => 'string', 'hidden' => true]],
    ]]]];
    cap_assert(ActionShape::project($nested, ['rows' => [['visible' => 3, 'hidden' => 'PRIVATE']]]) === ['rows' => [['visible' => 3]]], 'Nested output projection leaked.');
    cap_error('invalid_input', fn () => ActionShape::validate($nested, ['rows' => [['visible' => 3, 'hidden' => 'PRIVATE']]], true));
    $hiddenMatrix = ['type' => 'object', 'properties' => ['matrix' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => [
        'type' => 'object', 'hidden' => true, 'properties' => ['private_name' => ['type' => 'string']],
    ]]]]];
    cap_assert(!str_contains(json_encode(ActionShape::describe($hiddenMatrix)), 'private_name'), 'Hidden nested array item leaked its schema.');
    cap_assert(ActionShape::project($hiddenMatrix, ['matrix' => [[['private_name' => 'PRIVATE']]]]) === [], 'Hidden nested array property leaked.');
    cap_invalid(fn () => new ActionMetadata('Invalid.', ['type' => 'object', 'properties' => ['hidden' => ['type' => 'string', 'hidden' => true]], 'required' => ['hidden']], ['type' => 'object', 'properties' => []], visibility: 'public'));

    // Commands reuse the same transaction/outbox engine as legacy ActionRunner.
    $store = new ActionEventMemoryStore();
    $audit = new class implements \Fnlla\Php\Audit\AuditLoggerInterface {
        public function record(\Fnlla\Php\Audit\AuditEvent $event): void {}
    };
    $queue = new \Fnlla\Php\Queue\QueueManager($container, new \Fnlla\Php\Queue\FileQueueStore($capTemp . '/queue'), $database);
    $outbox = new OutboxProcessor($store, $audit, new DomainEventBus($container, $events, $queue));
    $container->instance(ActionTransaction::class, new ActionTransaction($container, $database, $store, $outbox));
    $writes = 0;
    $registry->register(cap_definition('fixture.write', kind: 'command', handler: static function (array $input) use (&$writes): ActionMutation {
        $writes++;
        return new ActionMutation('fixture-one', ['value' => $input['value'], 'secret' => 'PRIVATE']);
    }));
    $first = $executor->execute('fixture.write', ['value' => 'one'], $context);
    $second = $executor->execute('fixture.write', ['value' => 'one'], $context);
    cap_assert(!$first->replayed && $second->replayed && $writes === 1, 'Command retry repeated mutation.');
    cap_assert($first->value === $second->value && !isset($second->value['secret']), 'Replay bypassed projection.');
    cap_error('execution_failed', fn () => $executor->execute('fixture.write', ['value' => 'two'], $context));
    $registry->register(cap_definition('fixture.bad-command', kind: 'command', handler: static fn (): ActionMutation => new ActionMutation('fixture-two', ['value' => 7])));
    cap_error('invalid_output', fn () => $executor->execute('fixture.bad-command', ['value' => 'one'], $context));
    cap_assert(end($pdo->calls) === 'ROLLBACK', 'Output violation did not roll back transaction.');

    $policies->define('fixture', static fn (array $actor, array $resource, string $permission, ?TenantContext $tenant): bool => $resource['tenant_id'] === $tenant?->tenantId());
    $resource = ['id' => 'resource-one', '__type' => 'fixture', 'tenant_id' => 'tenant-one'];
    $resourceDefinition = cap_definition('fixture.scoped', kind: 'command', handler: static fn (array $input): ActionMutation => new ActionMutation('scoped', ['value' => $input['value']]));
    $registry->register(new ActionDefinition($resourceDefinition->id, $resourceDefinition->permission, $resourceDefinition->subjectType,
        $resourceDefinition->validator, $resourceDefinition->handler, [], new ActionMetadata('Scoped synthetic mutation.',
            $resourceDefinition->metadata->input, $resourceDefinition->metadata->output, visibility: 'public', resourceRequired: true),
        static function () use (&$resource): array { return $resource; }));
    $executor->execute('fixture.scoped', ['value' => 'one'], $context);
    $resource['id'] = 'resource-two';
    cap_error('execution_failed', fn () => $executor->execute('fixture.scoped', ['value' => 'one'], $context));
    $resource['tenant_id'] = 'tenant-two';
    cap_error('unauthorized', fn () => $executor->execute('fixture.scoped', ['value' => 'one'], $context));
    $eventsBefore = count($observed);
    try {
        $database->transaction(function () use ($executor, $context): void {
            $executor->execute('fixture.read', ['value' => 'nested'], $context);
            throw new RuntimeException('Outer rollback');
        });
    } catch (RuntimeException) {}
    cap_assert(count($observed) === $eventsBefore, 'Rolled-back outer transaction emitted success hook.');

    $moduleFixture = pm_write_fixture($capTemp);
    $moduleContainer = new Container();
    $moduleContainer->singleton(ActionRegistry::class);
    $moduleFixture['extensions']['orders'] = CapabilityModuleFixture::class;
    $modules = pm_registry($moduleContainer, $moduleFixture);
    $modules->registerActions();
    cap_assert($moduleContainer->make(ActionRegistry::class)->definitions() === [], 'Disabled plugin registered actions.');
    cap_assert(!isset($modules->enabledExtensions()['orders']), 'Disabled extension leaked.');
    $modules->enable('orders');
    cap_assert($modules->enabledExtensions()['orders'] instanceof CapabilityModuleFixture, 'Enabled extension discovery failed.');
    cap_assert($modules->enabledExtensions()['orders'] === $modules->enabledExtensions()['orders'], 'Extension was constructed twice.');
    $modules->registerActions();
    $modules->registerActions();
    cap_assert(count($moduleContainer->make(ActionRegistry::class)->definitions()) === 1, 'Plugin registration failed or repeated.');
    $pluginExecutor = new ActionExecutor($moduleContainer, $moduleContainer->make(ActionRegistry::class), $access, $events, $database);
    cap_assert($pluginExecutor->execute('orders.view', ['value' => 'plugin'], $context)->value === ['value' => 'plugin'], 'Plugin cannot execute.');
    $modules->disable('orders');
    cap_assert(!isset($modules->enabledExtensions()['orders']), 'Disabled extension survived discovery.');
    cap_error('unavailable', fn () => $pluginExecutor->execute('orders.view', ['value' => 'plugin'], $context));
    $modules->registerActions();
    cap_assert($moduleContainer->make(ActionRegistry::class)->definitions() === [], 'Disabled action survived registration.');
} finally {
    config_set('security', $capConfig);
    pm_remove_directory($capTemp);
}
echo "Capability architecture checks passed ({$capAssertions} assertions).\n";
