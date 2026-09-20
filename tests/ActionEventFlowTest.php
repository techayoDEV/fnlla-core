<?php

declare(strict_types=1);

use Fnlla\Php\Actions\ActionContext;
use Fnlla\Php\Actions\ActionDefinition;
use Fnlla\Php\Actions\ActionMutation;
use Fnlla\Php\Actions\ActionRegistry;
use Fnlla\Php\Actions\ActionRunner;
use Fnlla\Php\Actions\ActionStoreInterface;
use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Audit\AuditLoggerInterface;
use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\AccessControl;
use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Auth\Authorization\PolicyRegistry;
use Fnlla\Php\Auth\UserProviderInterface;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\Dispatcher;
use Fnlla\Php\Events\DomainEvent;
use Fnlla\Php\Events\DomainEventBus;
use Fnlla\Php\Events\OutboxProcessor;
use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Queue\FileQueueStore;
use Fnlla\Php\Queue\QueueManager;
use Fnlla\Php\Session\SessionStore;
use Fnlla\Php\Tenancy\TenantContext;
use Fnlla\Php\Tenancy\TenantContextManager;
use Fnlla\Php\Tenancy\UserProviderTenantIdentityResolver;
final class ActionEventTestPdo extends \PDO
{
    public bool $active = false;
    public array $calls = [];
    public function __construct() {}
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->calls[] = "BEGIN"; return $this->active = true; }
    public function commit(): bool { $this->calls[] = "COMMIT"; return !($this->active = false); }
    public function rollBack(): bool { $this->calls[] = "ROLLBACK"; $this->active = false; return true; }
    public function exec(string $statement): int|false { $this->calls[] = $statement; return 0; }
}

final class ActionEventMemoryStore implements ActionStoreInterface
{
    public array $receipts = [];
    public array $messages = [];
    public function claim(string $key, string $action, string $hash, ActionContext $context): ?array
    {
        if (!isset($this->receipts[$key])) {
            $this->receipts[$key] = ["action" => $action, "hash" => $hash, "result" => null];
            return null;
        }
        if ($this->receipts[$key]["action"] !== $action || $this->receipts[$key]["hash"] !== $hash) {
            throw new RuntimeException("Action idempotency key was reused with different input.");
        }
        return $this->receipts[$key]["result"];
    }
    public function complete(string $key, array $result): void { $this->receipts[$key]["result"] = $result; }
    public function append(string $id, string $kind, string $name, array $payload): void
    {
        $this->messages[$id] ??= ["id" => $id, "kind" => $kind, "name" => $name, "payload" => $payload, "published" => false];
    }
    public function pending(int $limit = 100): array
    {
        return array_slice(array_values(array_map(static function (array $message): array {
            unset($message["published"]);
            return $message;
        }, array_filter($this->messages, static fn (array $message): bool => !$message["published"]))), 0, $limit);
    }
    public function markPublished(string $id): void { $this->messages[$id]["published"] = true; }
}

final class ActionEventFixtureJob
{
    public static array $handled = [];
    public function __construct(private array $domain_event) {}
    public function handle(TenantContextManager $tenants): void
    {
        $event = DomainEvent::fromArray($this->domain_event);
        $context = $tenants->requireContext();
        if ($context->tenantId() !== $event->context->tenantId || $context->actorId() !== $event->context->actorId) {
            throw new RuntimeException("Queued action context was not restored.");
        }
        self::$handled[$event->id] = (self::$handled[$event->id] ?? 0) + 1;
    }
}

function action_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$previousSecurity = config("security", []);
$previousAuth = config("auth", []);
$previousQueue = config("queue", []);
$previousSession = $_SESSION ?? [];
$queueDirectory = sys_get_temp_dir() . "/fnlla-action-events-" . bin2hex(random_bytes(8));

try {
    config_set("auth.session_key", "action.event.user");
    config_set("auth.providers.users.key", "id");
    config_set("security.authorization", [
        "role_field" => "role",
        "roles" => [
            "tenant-admin" => ["permissions" => ["work-orders.create"]],
            "viewer" => ["permissions" => ["work-orders.view"]],
        ],
        "assignable_roles" => [],
        "legacy_gate_permissions" => [],
    ]);
    config_set("security.tenancy", [
        "mode" => "organization",
        "organization_field" => "organization_id",
        "custom_field" => "tenant_id",
        "additional_field" => "tenant_ids",
    ]);
    config_set("queue.job_types", [
        "fixture.domain-event" => ["class" => ActionEventFixtureJob::class, "version" => 1],
    ]);
    config_set("queue.max_attempts", 2);
    $_SESSION = [];

    $users = new class implements UserProviderInterface {
        public array $records = [
            "actor-a" => ["id" => "actor-a", "role" => "tenant-admin", "organization_id" => "tenant-a", "active" => true],
            "actor-b" => ["id" => "actor-b", "role" => "viewer", "organization_id" => "tenant-b", "active" => true],
        ];
        public function findById(string|int $id): ?array { return $this->records[(string) $id] ?? null; }
        public function findByCredentials(array $credentials): ?array { return null; }
    };
    $auth = new AuthManager(new SessionStore(), $users, new Hasher());
    $auth->login($users->records["actor-a"]);
    $access = new AccessControl($auth, new PolicyRegistry());
    $audit = new class implements AuditLoggerInterface {
        public array $events = [];
        public function record(AuditEvent $event): void { $this->events[$event->correlationId] = $event->toArray(); }
    };
    $tenants = new TenantContextManager($auth, new UserProviderTenantIdentityResolver($users), $access, $audit);
    $container = new Container();
    $container->instance(TenantContextManager::class, $tenants);
    $pdo = new ActionEventTestPdo();
    $database = DatabaseManager::using($pdo);
    $store = new ActionEventMemoryStore();
    $queueStore = new FileQueueStore($queueDirectory);
    $queue = new QueueManager($container, $queueStore, $database, $tenants);
    $dispatcher = new Dispatcher($container, $database);
    $bus = new DomainEventBus($container, $dispatcher, $queue);
    $syncEffects = [];
    $bus->listen("work-order.created", 1, "fixture.sync", static function (DomainEvent $event) use (&$syncEffects, $pdo): void {
        action_assert(end($pdo->calls) === "COMMIT", "Domain event ran before commit.");
        $syncEffects[$event->id] = ($syncEffects[$event->id] ?? 0) + 1;
    });
    $bus->queue("work-order.created", 1, "fixture.queue", ActionEventFixtureJob::class);
    $outbox = new OutboxProcessor($store, $audit, $bus);
    $registry = new ActionRegistry();
    $domainWrites = [];
    $registry->register(new ActionDefinition(
        "work-order.create",
        "work-orders.create",
        "work-order",
        static function (array $input): array {
            $summary = trim((string) ($input["summary"] ?? ""));
            if ($summary === "") { throw new InvalidArgumentException("summary is required"); }
            return ["summary" => $summary];
        },
        static function (array $input, ActionContext $context) use (&$domainWrites): ActionMutation {
            $id = "wo-" . (count($domainWrites) + 1);
            $domainWrites[$id] = ["id" => $id, "tenant_id" => $context->tenantId, "summary" => $input["summary"]];
            return new ActionMutation($id, $domainWrites[$id], [], $domainWrites[$id], [[
                "name" => "work-order.created",
                "payload_version" => 1,
                "payload" => ["summary" => $input["summary"]],
            ]]);
        },
        ["work-order.created"]
    ));
    $registry->register(new ActionDefinition(
        "work-order.fail",
        "work-orders.create",
        "work-order",
        static fn (array $input): array => $input,
        static function (): never { throw new RuntimeException("rollback fixture"); }
    ));
    $runner = new ActionRunner($container, $database, $auth, $access, $tenants, $registry, $store, $outbox);

    $first = $tenants->within(new TenantContext("organization", "tenant-a", "actor-a", "api-mutation-1"),
        static fn (): \Fnlla\Php\Actions\ActionResult => $runner->run("work-order.create", ["summary" => "Leaking tap"], "api"));
    action_assert(!$first->replayed && count($domainWrites) === 1, "First Action mutation did not commit exactly once.");
    action_assert(count($syncEffects) === 1 && count($audit->events) === 1, "Audit/event was not published after commit.");
    action_assert($queueStore->pendingCount() === 1 && $queue->work(5) === 1, "Queued event listener did not execute.");
    action_assert(array_values(ActionEventFixtureJob::$handled) === [1], "Queued event context or idempotency failed.");

    $replay = $tenants->within(new TenantContext("organization", "tenant-a", "actor-a", "api-mutation-1"),
        static fn (): \Fnlla\Php\Actions\ActionResult => $runner->run("work-order.create", ["summary" => "Leaking tap"], "api"));
    action_assert($replay->replayed && count($domainWrites) === 1 && count($syncEffects) === 1,
        "Action retry duplicated a committed domain/event effect.");

    try {
        $tenants->within(new TenantContext("organization", "tenant-a", "actor-a", "api-mutation-1"),
            static fn () => $runner->run("work-order.create", ["summary" => "Different input"], "api"));
        throw new RuntimeException("Changed input reused an action idempotency key.");
    } catch (RuntimeException $error) {
        action_assert(str_contains($error->getMessage(), "different input"), "Unexpected idempotency mismatch failure.");
    }

    try {
        $tenants->within(new TenantContext("organization", "tenant-a", "actor-a", "api-rollback-1"),
            static fn () => $runner->run("work-order.fail", [], "api"));
        throw new RuntimeException("Failing Action committed.");
    } catch (RuntimeException $error) {
        action_assert($error->getMessage() === "rollback fixture", "Unexpected Action rollback failure.");
    }
    action_assert(end($pdo->calls) === "ROLLBACK" && count($syncEffects) === 1, "Rollback published a success event.");

    $auth->login($users->records["actor-b"]);
    try {
        $tenants->within(new TenantContext("organization", "tenant-b", "actor-b", "api-denied-1"),
            static fn () => $runner->run("work-order.create", ["summary" => "Denied"], "api"));
        throw new RuntimeException("Role denial did not protect the Action.");
    } catch (AuthorizationException) {
    }
    action_assert(count($domainWrites) === 1, "Denied Action changed domain state.");

    action_assert($registry->inspect()[0]["id"] === "work-order.create", "Action registry inspection is not deterministic.");
    action_assert($bus->inspect()[0]["listener_id"] === "fixture.queue", "Domain listener inspection is not deterministic.");
    $schema = json_decode((string) file_get_contents(__DIR__ . "/../resources/events/fnlla.domain-event.v1.schema.json"), true, 512, JSON_THROW_ON_ERROR);
    action_assert(($schema['$id'] ?? null) === "https://fnlla.com/schemas/fnlla.domain-event.v1.schema.json", "Domain event schema is missing.");
    echo "FNLLA action and domain event tests passed.\n";
} finally {
    config_set("security", $previousSecurity);
    config_set("auth", $previousAuth);
    config_set("queue", $previousQueue);
    $_SESSION = $previousSession;
    if (is_dir($queueDirectory)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($queueDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($queueDirectory);
    }
}
