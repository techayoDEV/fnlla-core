<?php

declare(strict_types=1);

if (!function_exists('config')) { require_once dirname(__DIR__) . '/vendor/autoload.php'; }
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }

use Fnlla\Php\Actions\{ActionContext, ActionShape, ReliableOutboxStoreInterface};
use Fnlla\Php\Audit\{AuditEvent, AuditLoggerInterface};
use Fnlla\Php\Auth\{ActorStatus, AuthManager, UserProviderInterface};
use Fnlla\Php\Auth\Authorization\{AccessControl, PolicyRegistry};
use Fnlla\Php\Auth\Middleware\Authenticate;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\{Dispatcher, DomainEvent, DomainEventBus, OutboxProcessor, OutboxWorker};
use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Http\{Request, Response};
use Fnlla\Php\Mail\Mailer;
use Fnlla\Php\Queue\{FileQueueStore, QueueManager};
use Fnlla\Php\Session\SessionStore;
use Fnlla\Php\Support\Logger;
use Fnlla\Php\Tenancy\{TenantContext, TenantContextManager, UserProviderTenantIdentityResolver};
use Fnlla\Php\Validation\{Validator, ValidationException};

function hardening_check(bool $condition, string $message): void
{
    $GLOBALS['hardening_assertions']++;
    if (!$condition) { throw new RuntimeException($message); }
}
function hardening_reject(callable $callback, string $type = RuntimeException::class): void
{
    try { $callback(); } catch (Throwable $error) {
        hardening_check($error instanceof $type, 'Unexpected rejection: ' . $error::class);
        return;
    }
    throw new RuntimeException('Unsafe operation was accepted.');
}

final class AuditHardeningPdo extends PDO
{
    public bool $active = false;
    public bool $failRollback = false;
    public int $commits = 0;
    public function __construct() {}
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
    public function beginTransaction(): bool { return $this->active = true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { $this->commits++; $this->active = false; return true; }
    public function rollBack(): bool
    {
        $this->active = false;
        if ($this->failRollback) { throw new RuntimeException('Synthetic connection loss'); }
        return true;
    }
    public function exec(string $statement): int|false
    {
        if ($this->failRollback && str_starts_with($statement, 'ROLLBACK')) { throw new RuntimeException('Synthetic savepoint failure'); }
        return 0;
    }
}

final class AuditHardeningOutbox implements ReliableOutboxStoreInterface
{
    public bool $taken = false;
    public bool $published = false;
    public ?string $error = null;
    public function __construct(public DomainEvent|AuditEvent $event) {}
    public function claim(string $idempotencyKey, string $actionId, string $requestHash, ActionContext $context): ?array { return null; }
    public function complete(string $idempotencyKey, array $result): void {}
    public function append(string $id, string $kind, string $name, array $payload): void {}
    public function pending(int $limit = 100): array
    {
        return $this->published ? [] : [['id' => 'test-delivery', 'kind' => $this->event instanceof DomainEvent ? 'domain_event' : 'audit',
            'payload' => $this->event->toArray(), 'token' => 'test-owner', 'attempts' => 1]];
    }
    public function markPublished(string $id): void { $this->published = true; }
    public function claimDelivery(int $leaseSeconds, int $maximumAttempts): ?array
    {
        if ($this->taken) { return null; }
        $this->taken = true;
        return $this->pending()[0] ?? null;
    }
    public function ownsDelivery(string $id, string $token): bool { return true; }
    public function acknowledgeDelivery(string $id, string $token): void { $this->published = true; }
    public function failDelivery(string $id, string $token, string $code, bool $retryable, int $maximumAttempts, int $delaySeconds): void { $this->error = $code; }
    public function deliveryStatus(int $limit = 100): array { return []; }
    public function retryDelivery(string $id): bool { return false; }
}
final class AuditHardeningJob { public function handle(): void {} }

$GLOBALS['hardening_assertions'] = 0;
$hardeningConfig = $GLOBALS['fnlla_config'] ?? [];
$hardeningSession = $_SESSION ?? [];
$hardeningDirectory = sys_get_temp_dir() . '/fnlla-audit-hardening-' . bin2hex(random_bytes(6));
mkdir($hardeningDirectory, 0700, true);
try {
    // Both a simulated broken connection and a real SQLite transaction when available.
    $connections = [new AuditHardeningPdo()];
    if (in_array('sqlite', PDO::getAvailableDrivers(), true)) { $connections[] = new PDO('sqlite::memory:'); }
    foreach ($connections as $pdo) {
        $db = DatabaseManager::using($pdo); $effect = 0;
        $pdo->beginTransaction();
        hardening_reject(fn () => $db->afterCommit(function () use (&$effect): void { $effect++; }));
        hardening_reject(fn () => (new Mailer($db))->send('test@example.com', 'test', 'test'));
        hardening_reject(fn () => (new Mailer($db))->sendAfterCommit('test@example.com', 'test', 'test'));
        $queue = new QueueManager(new Container(), new FileQueueStore($hardeningDirectory . '/queue'), $db);
        hardening_reject(fn () => $queue->push(AuditHardeningJob::class));
        hardening_reject(fn () => $queue->pushAfterCommit(AuditHardeningJob::class));
        $pdo->rollBack();
        hardening_check($effect === 0 && !$db->hasActiveTransaction(), 'External rollback emitted effects.');
    }
    $pdo = new AuditHardeningPdo(); $pdo->failRollback = true; $db = DatabaseManager::using($pdo);
    hardening_reject(fn () => $db->transaction(fn () => throw new RuntimeException('handler')));
    hardening_check(!$db->hasActiveManagedTransaction(), 'Rollback failure poisoned managed depth.');
    hardening_reject(fn () => $db->connection());
    hardening_reject(fn () => $db->afterCommit(fn () => null));
    $pdo = new AuditHardeningPdo(); $pdo->failRollback = true; $db = DatabaseManager::using($pdo);
    hardening_reject(fn () => $db->transaction(function () use ($db): void {
        try { $db->transaction(fn () => throw new RuntimeException('nested')); } catch (RuntimeException) {}
    }));
    hardening_check($pdo->commits === 0 && !$db->hasActiveManagedTransaction(), 'Swallowed nested rollback failure committed parent.');

    config_set('auth.session_key', 'audit.actor');
    config_set('auth.providers.users.key', 'id');
    $users = new class implements UserProviderInterface {
        public array $actor = ['id' => 'actor-b', 'active' => true, 'organization_id' => 'tenant-b'];
        public function findById(string|int $id): ?array { return $this->actor; }
        public function findByCredentials(array $credentials): ?array { return $this->actor; }
    };
    $session = new SessionStore(); $auth = new AuthManager($session, $users, new Hasher());
    $users->actor['password'] = password_hash('synthetic-password', PASSWORD_DEFAULT);
    foreach ([false, 0, '0', null, 'true', [], 2] as $inactive) {
        $users->actor['active'] = $inactive; $session->put('audit.actor', 'actor-b');
        $called = false;
        $response = (new Authenticate($auth))->handle(new Request('GET', '/private', headers: ['accept' => 'application/json']),
            function () use (&$called): Response { $called = true; return Response::text('unsafe'); });
        hardening_check(!$called && $response->status() === 401 && $auth->id() === null, 'Inactive session reached protected code.');
        hardening_check(!$auth->attempt(['password' => 'synthetic-password']), 'Inactive login attempt succeeded.');
        hardening_reject(fn () => $auth->login($users->actor), InvalidArgumentException::class);
    }
    foreach ([true, 1, '1'] as $active) {
        $users->actor['active'] = $active; $auth->login($users->actor);
        hardening_check($auth->check(), 'Active database boolean was rejected.');
    }
    $users->actor['revoked_at'] = '2026-10-01';
    hardening_check(!$auth->check(), 'Revoked session survived.');
    unset($users->actor['revoked_at']);
    hardening_check(ActorStatus::active(['id' => 'legacy']), 'Legacy actors without a status were rejected.');
    $resolver = new UserProviderTenantIdentityResolver($users);
    hardening_check($resolver->findActor('another-actor') === null, 'Resolver accepted mismatched provider identity.');

    config_set('security.tenancy.mode', 'organization');
    config_set('security.tenancy.organization_field', 'organization_id');
    $tenants = new TenantContextManager($auth, $resolver, new AccessControl($auth, new PolicyRegistry()));
    $container = new Container(); $container->instance(TenantContextManager::class, $tenants);
    $queueStore = new FileQueueStore($hardeningDirectory . '/events');
    $queue = new QueueManager($container, $queueStore, null, $tenants);
    $audit = new class implements AuditLoggerInterface {
        public int $count = 0;
        public function record(AuditEvent $event): void { $this->count++; }
    };
    $bus = new DomainEventBus($container, new Dispatcher($container), $queue);
    $seen = [];
    $bus->listen('record.changed', 1, 'inspect', function () use ($tenants, &$seen): void {
        $current = $tenants->requireContext();
        $seen[] = [$current->tenantId(), $current->actorId(), $current->correlationId()];
    });
    $event = new DomainEvent('test-event', 'record.changed', 1, gmdate(DATE_ATOM), 'record', 'record-b',
        new ActionContext('actor-b', 'tenant-b', 'human', 'event-correlation'), []);
    foreach ([false, true] as $reliable) {
        config_set('actions.reliable_outbox', $reliable);
        $store = new AuditHardeningOutbox($event);
        $ambient = new TenantContext('organization', 'tenant-a', 'actor-a', 'ambient');
        $tenants->within($ambient, function () use ($store, $audit, $bus, $tenants, $ambient): void {
            hardening_check((new OutboxProcessor($store, $audit, $bus))->publishPending(1) === 1, 'Outbox did not publish.');
            hardening_check($tenants->current() === $ambient, 'Outbox replaced ambient context.');
        });
        hardening_check(end($seen) === ['tenant-b', 'actor-b', 'event-correlation'] && $tenants->current() === null,
            'Outbox delivered in the wrong tenant context.');
    }
    config_set('queue.job_types', ['hardening.event' => ['class' => AuditHardeningJob::class, 'version' => 1]]);
    $queued = new DomainEventBus($container, new Dispatcher($container), $queue);
    $queued->queue('record.changed', 1, 'queued', AuditHardeningJob::class);
    $store = new AuditHardeningOutbox($event);
    hardening_check((new OutboxWorker($store, $audit, $queued, $container))->work(1)['published'] === 1
        && $queueStore->pendingCount() === 1 && $tenants->current() === null, 'CLI outbox could not enqueue without ambient context.');
    $bus->listen('record.changed', 1, 'failure', fn () => throw new RuntimeException('listener'));
    hardening_reject(fn () => $bus->publish($event));
    hardening_check($tenants->current() === null, 'Listener failure leaked context.');
    foreach (['active' => false, 'organization_id' => 'tenant-other', 'id' => 'another-actor'] as $field => $value) {
        $original = $users->actor[$field]; $users->actor[$field] = $value;
        $store = new AuditHardeningOutbox($event); $before = count($seen);
        hardening_check((new OutboxWorker($store, $audit, $bus))->work(1)['retried'] === 1 && !$store->published
            && count($seen) === $before && $tenants->current() === null, 'Untrusted persisted identity reached listener.');
        $users->actor[$field] = $original;
    }
    $users->actor['active'] = false;
    $store = new AuditHardeningOutbox(new AuditEvent('record.change', 'human', 'actor-b', 'record', 'record-b', 'tenant-b', 'audit-correlation'));
    hardening_check((new OutboxWorker($store, $audit, $bus))->work(1)['published'] === 1 && $audit->count === 1,
        'Revocation prevented durable audit delivery.');

    foreach (['emali', 'max', 'min:no', 'required:yes', 'in:', 'max:INF'] as $rule) {
        hardening_reject(fn () => Validator::make(['value' => null], ['value' => 'nullable|' . $rule])->validate(), InvalidArgumentException::class);
    }
    foreach ([null, '', []] as $value) {
        hardening_reject(fn () => Validator::make(['value' => $value], ['value' => 'nullable|required'])->validate(), ValidationException::class);
    }
    hardening_reject(fn () => Validator::make(['value' => str_repeat('0', 100)], ['value' => 'string|max:10'])->validate(), ValidationException::class);
    hardening_check(Validator::make(['value' => '000'], ['value' => 'string|min:3|max:3'])->validate()['value'] === '000', 'String measured as a number.');
    hardening_check(Validator::make(['value' => '10'], ['value' => 'numeric|min:10|max:10'])->validate()['value'] === '10', 'Numeric bounds changed.');
    hardening_reject(fn () => Validator::make(['value' => ['x']], ['value' => 'in:x'])->validate(), ValidationException::class);
    foreach ([['minItems' => 4097], ['maxItems' => 4097], ['minItems' => 5000, 'maxItems' => 6000]] as $bounds) {
        hardening_reject(fn () => ActionShape::assertDefinition(['type' => 'array', 'items' => ['type' => 'integer']] + $bounds), InvalidArgumentException::class);
    }
    $shape = ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 4096];
    ActionShape::assertDefinition($shape); ActionShape::validate($shape, array_fill(0, 4096, 1));
    hardening_check(ActionShape::describe($shape)['maxItems'] === 4096, 'Schema omitted effective array bound.');

    config_set('app.log_path', $hardeningDirectory . '/safe.log'); config_set('logging.redact_keys', []);
    Logger::exception(new RuntimeException('unlabelled-SYNTHETIC_SECRET'), ['failure' => new RuntimeException('nested-SYNTHETIC_SECRET')]);
    Logger::write('info', 'Authorization: Bearer SYNTHETIC_SECRET password="SYNTHETIC_SECRET" token%3DSYNTHETIC_SECRET https://user:SYNTHETIC_SECRET@example.com',
        ['password' => 'SYNTHETIC_SECRET', 'note' => 'pwd=SYNTHETIC_SECRET', 'long' => str_repeat('a', 50000)]);
    $contents = (string) file_get_contents($hardeningDirectory . '/safe.log');
    hardening_check(!str_contains($contents, 'SYNTHETIC_SECRET') && str_contains($contents, 'RuntimeException') && strlen($contents) < 10000,
        'Logger leaked credentials or unbounded content.');
    echo 'Audit hardening: ' . $GLOBALS['hardening_assertions'] . ' assertions passed.' . PHP_EOL;
} finally {
    $GLOBALS['fnlla_config'] = $hardeningConfig; $_SESSION = $hardeningSession;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($hardeningDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($hardeningDirectory);
}
