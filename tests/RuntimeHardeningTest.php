<?php

declare(strict_types=1);

use Fnlla\Php\Container\Container;
use Fnlla\Php\Queue\FileQueueStore;
use Fnlla\Php\Queue\JobEnvelope;
use Fnlla\Php\Queue\JobContext;
use Fnlla\Php\Queue\QueueManager;
use Fnlla\Php\Queue\QueuePayloadException;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Database\PostCommitCallbackException;
use Fnlla\Php\Events\Dispatcher;
use Fnlla\Php\Mail\Mailer;
use Fnlla\Php\Mail\MailTransportInterface;
use Fnlla\Php\Support\RuntimeInspector;

interface RuntimeHardeningMissingBinding
{
}

final class RuntimeHardeningScopedValue
{
}

final class RuntimeHardeningQueueJob
{
    public function handle(): void
    {
    }
}

final class RuntimeHardeningContextJob
{
    public static array $handled = [];

    public function __construct(private JobContext $context)
    {
    }

    public function handle(): void
    {
        $this->context->assertLeaseOwned();
        self::$handled[] = [
            "tenant" => $this->context->tenantId(),
            "actor" => $this->context->actorId(),
            "correlation" => $this->context->correlationId(),
            "idempotency" => $this->context->idempotencyKey(),
        ];
    }
}

final class RuntimeHardeningStopJob
{
    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
        $GLOBALS["runtime_hardening_manager"]->requestStop();
    }
}

final class RuntimeHardeningLeaseLossJob
{
    public function __construct(private JobContext $context)
    {
    }

    public function handle(): void
    {
        $path = $GLOBALS["runtime_hardening_lease_directory"] . DIRECTORY_SEPARATOR
            . $this->context->jobId() . ".job";
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $payload["reserved_until"] = time() - 1;
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->context->assertLeaseOwned();
    }
}

final class RuntimeHardeningTransactionPdo extends PDO
{
    public array $calls = [];
    public bool $active = false;

    public function __construct()
    {
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function beginTransaction(): bool
    {
        $this->calls[] = "BEGIN";
        return $this->active = true;
    }

    public function commit(): bool
    {
        $this->calls[] = "COMMIT";
        $this->active = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = "ROLLBACK";
        $this->active = false;
        return true;
    }

    public function exec(string $statement): int|false
    {
        $this->calls[] = $statement;
        return 0;
    }
}

final class RuntimeHardeningMailTransport implements MailTransportInterface
{
    public array $messages = [];

    public function send(array $message): void
    {
        $this->messages[] = $message;
    }
}

$scopeContainer = new Container();
$scopeContainer->scoped(RuntimeHardeningScopedValue::class);

runtime_expect_exception(
    RuntimeException::class,
    static fn (): object => $scopeContainer->make(RuntimeHardeningScopedValue::class),
    "Scoped resolution outside work scope must fail closed."
);

$firstScoped = null;
$nestedScoped = null;
$scopeContainer->withinScope(static function (Container $container) use (&$firstScoped, &$nestedScoped): void {
    $firstScoped = $container->make(RuntimeHardeningScopedValue::class);
    runtime_assert_true(
        $firstScoped === $container->make(RuntimeHardeningScopedValue::class),
        "A scoped binding must be stable inside one work scope."
    );
    $container->withinScope(static function (Container $nested) use (&$firstScoped, &$nestedScoped): void {
        $nestedScoped = $nested->make(RuntimeHardeningScopedValue::class);
        runtime_assert_true($nestedScoped !== $firstScoped, "A nested work scope must not inherit scoped instances.");
    });
    runtime_assert_true(
        $firstScoped === $container->make(RuntimeHardeningScopedValue::class),
        "Ending a nested scope must restore the outer scoped instance."
    );
});

$secondScoped = $scopeContainer->withinScope(
    static fn (Container $container): object => $container->make(RuntimeHardeningScopedValue::class)
);
runtime_assert_true($secondScoped !== $firstScoped, "Successive work scopes must not share scoped instances.");

$injected = new stdClass();
$scopeContainer->withinScope(static function (Container $container) use ($injected): void {
    $container->scopedInstance(RuntimeHardeningScopedValue::class, $injected);
    runtime_assert_true(
        $container->make(RuntimeHardeningScopedValue::class) === $injected,
        "A scoped instance must be visible only in its active scope."
    );
});

runtime_expect_exception(
    RuntimeException::class,
    static fn (): mixed => $scopeContainer->make(RuntimeHardeningMissingBinding::class),
    "A missing interface binding must fail closed."
);
runtime_expect_exception(
    RuntimeException::class,
    static function () use ($scopeContainer): void { $scopeContainer->endScope(); },
    "Ending a missing scope must fail."
);

$scopeContainer->singleton("runtime.singleton", static fn (): stdClass => new stdClass());
$scopeContainer->instance("runtime.instance", new stdClass());
$metadata = $scopeContainer->inspectBindings();
$abstracts = array_column($metadata, "abstract");
$sorted = $abstracts;
sort($sorted, SORT_STRING);
runtime_assert_same($sorted, $abstracts, "Binding inspection must be deterministic.");
$lifetimes = array_column($metadata, "lifetime", "abstract");
runtime_assert_same("scoped", $lifetimes[RuntimeHardeningScopedValue::class] ?? null, "Scoped lifetime metadata missing.");
runtime_assert_same("singleton", $lifetimes["runtime.singleton"] ?? null, "Singleton lifetime metadata missing.");
runtime_assert_same("instance", $lifetimes["runtime.instance"] ?? null, "Instance lifetime metadata missing.");

$GLOBALS["fnlla_config"]["queue"] = [
    "default" => "file",
    "max_attempts" => 2,
    "retry_backoff_seconds" => 1,
    "visibility_timeout_seconds" => 300,
    "accept_legacy_payloads" => true,
    "job_types" => [
        "runtime-hardening" => ["class" => RuntimeHardeningQueueJob::class, "version" => 1],
        "runtime-context" => ["class" => RuntimeHardeningContextJob::class, "version" => 1],
        "runtime-stop" => ["class" => RuntimeHardeningStopJob::class, "version" => 1],
        "runtime-lease-loss" => ["class" => RuntimeHardeningLeaseLossJob::class, "version" => 1],
    ],
    "worker_max_seconds" => 300,
    "idempotency_ttl_seconds" => 86400,
];

$queueDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "fnlla-core-queue-hardening-" . bin2hex(random_bytes(4));
$queueStore = new FileQueueStore($queueDirectory);
$queueId = $queueStore->push(RuntimeHardeningQueueJob::class, ["value" => [1, "safe"]], [
    "job_type" => "runtime-hardening",
    "job_version" => 1,
    "context" => [
        "correlation_id" => "correlation-1",
        "tenant_id" => "tenant-1",
        "actor_id" => "actor-1",
        "idempotency_key" => "dedupe-1",
    ],
]);
$queued = $queueStore->pop();
runtime_assert_same($queueId, $queued["id"] ?? null, "Versioned queue envelope ID mismatch.");
runtime_assert_same(JobEnvelope::SCHEMA, $queued["schema"] ?? null, "Versioned queue schema missing.");
runtime_assert_same("runtime-hardening", $queued["job_type"] ?? null, "Registered queue type missing.");
runtime_assert_same("tenant-1", $queued["context"]["tenant_id"] ?? null, "Queue context was not preserved.");
$queueStore->complete($queued);

runtime_expect_exception(
    QueuePayloadException::class,
    static fn (): string => $queueStore->push(RuntimeHardeningQueueJob::class, ["unsafe" => new stdClass()]),
    "Unsafe object serialization must fail before a job is persisted."
);

file_put_contents($queueDirectory . DIRECTORY_SEPARATOR . "000-poison.job", json_encode([
    "schema" => "fnlla.queue.v999",
    "id" => "poison-1",
    "job" => RuntimeHardeningQueueJob::class,
    "job_type" => "runtime-hardening",
    "job_version" => 1,
    "payload" => [],
    "context" => ["correlation_id" => "poison-correlation"],
], JSON_THROW_ON_ERROR));
$validAfterPoison = $queueStore->push(RuntimeHardeningQueueJob::class, [], [
    "job_type" => "runtime-hardening",
    "job_version" => 1,
    "context" => ["correlation_id" => "correlation-2"],
]);
$afterPoison = $queueStore->pop();
runtime_assert_same($validAfterPoison, $afterPoison["id"] ?? null, "Poison payload blocked a later valid job.");
runtime_assert_same(1, $queueStore->failedCount(), "Poison payload was not quarantined.");
$queueStore->complete($afterPoison);

$legacyDirectory = $queueDirectory . DIRECTORY_SEPARATOR . "legacy";
$legacyStore = new FileQueueStore($legacyDirectory);
file_put_contents($legacyDirectory . DIRECTORY_SEPARATOR . "legacy-1.job", json_encode([
    "job" => RuntimeHardeningQueueJob::class,
    "payload" => ["legacy" => true],
    "attempts" => 0,
    "max_attempts" => 1,
    "available_at" => time(),
    "state" => "pending",
], JSON_THROW_ON_ERROR));
$legacy = $legacyStore->pop();
runtime_assert_true(($legacy["legacy"] ?? false) === true, "Enabled legacy payload bridge was not marked.");
runtime_assert_same(JobEnvelope::SCHEMA, $legacy["schema"] ?? null, "Legacy payload was not normalized.");
$legacyStore->complete($legacy);

config_set("queue.accept_legacy_payloads", false);
file_put_contents($legacyDirectory . DIRECTORY_SEPARATOR . "legacy-disabled.job", json_encode([
    "job" => RuntimeHardeningQueueJob::class,
    "payload" => [],
    "attempts" => 0,
    "max_attempts" => 1,
    "available_at" => time(),
    "state" => "pending",
], JSON_THROW_ON_ERROR));
runtime_assert_same(null, $legacyStore->pop(), "Disabled legacy payload was consumed.");
runtime_assert_same(1, $legacyStore->failedCount(), "Disabled legacy payload was not quarantined.");
config_set("queue.accept_legacy_payloads", true);

$managerStore = new FileQueueStore($queueDirectory . DIRECTORY_SEPARATOR . "manager");
$queueManager = new QueueManager($scopeContainer, $managerStore);
$managerId = $queueManager->push(RuntimeHardeningQueueJob::class, [], ["correlation_id" => "manager-1"]);
runtime_assert_true($managerId !== "", "Registered queue job was not accepted.");
runtime_expect_exception(
    QueuePayloadException::class,
    static fn (): string => $queueManager->push(stdClass::class),
    "Unregistered queue job class must fail closed."
);

$leaseId = $managerStore->push(RuntimeHardeningQueueJob::class, [], [
    "job_type" => "runtime-hardening",
    "job_version" => 1,
    "context" => ["correlation_id" => "lease-renew", "idempotency_key" => "lease-renew"],
]);
$leaseJob = $managerStore->pop();
$previousExpiry = (int) $leaseJob["reserved_until"];
$renewedJob = $managerStore->renew($leaseJob, 600);
runtime_assert_true($managerStore->owns($renewedJob), "Renewed queue lease is not owned by the worker.");
runtime_assert_true((int) $renewedJob["reserved_until"] >= $previousExpiry, "Queue lease was not extended.");
$managerStore->complete($renewedJob);

RuntimeHardeningContextJob::$handled = [];
$contextDirectory = $queueDirectory . DIRECTORY_SEPARATOR . "contexts";
$contextStore = new FileQueueStore($contextDirectory);
$contextManager = new QueueManager($scopeContainer, $contextStore);
$contextManager->push(RuntimeHardeningContextJob::class, [], [
    "correlation_id" => "context-1",
    "tenant_id" => "tenant-a",
    "actor_id" => "actor-a",
    "idempotency_key" => "shared-delivery",
]);
$contextManager->push(RuntimeHardeningContextJob::class, [], [
    "correlation_id" => "context-2",
    "tenant_id" => "tenant-b",
    "actor_id" => "actor-b",
    "idempotency_key" => "delivery-b",
]);
runtime_assert_same(2, $contextManager->work(10), "Sequential context jobs were not acknowledged.");
$handledContexts = RuntimeHardeningContextJob::$handled;
usort($handledContexts, static fn (array $left, array $right): int => $left["tenant"] <=> $right["tenant"]);
runtime_assert_same([
    ["tenant" => "tenant-a", "actor" => "actor-a", "correlation" => "context-1", "idempotency" => "shared-delivery"],
    ["tenant" => "tenant-b", "actor" => "actor-b", "correlation" => "context-2", "idempotency" => "delivery-b"],
], $handledContexts, "Actor or tenant context leaked across jobs.");
runtime_expect_exception(
    RuntimeException::class,
    static fn (): object => $scopeContainer->make(JobContext::class),
    "Job context must be unavailable after the worker scope ends."
);

// The same tenant/type/key is handled once. The duplicate is acknowledged from
// the durable completion marker without claiming exactly-once external effects.
$contextManager->push(RuntimeHardeningContextJob::class, [], [
    "correlation_id" => "context-duplicate",
    "tenant_id" => "tenant-a",
    "actor_id" => "actor-a",
    "idempotency_key" => "shared-delivery",
]);
runtime_assert_same(1, $contextManager->work(10), "Completed duplicate was not acknowledged.");
runtime_assert_same(2, count(RuntimeHardeningContextJob::$handled), "Completed duplicate invoked the handler again.");

RuntimeHardeningStopJob::$handled = 0;
$stopStore = new FileQueueStore($queueDirectory . DIRECTORY_SEPARATOR . "stop");
$stopManager = new QueueManager($scopeContainer, $stopStore);
$GLOBALS["runtime_hardening_manager"] = $stopManager;
$stopManager->push(RuntimeHardeningStopJob::class, [], ["correlation_id" => "stop-1"]);
$stopManager->push(RuntimeHardeningStopJob::class, [], ["correlation_id" => "stop-2"]);
runtime_assert_same(1, $stopManager->work(10), "Graceful stop did not finish the active job.");
runtime_assert_same(1, RuntimeHardeningStopJob::$handled, "Graceful stop started another job.");
runtime_assert_same(1, $stopStore->pendingCount(), "Graceful stop consumed the next queued job.");
unset($GLOBALS["runtime_hardening_manager"]);

$leaseLossDirectory = $queueDirectory . DIRECTORY_SEPARATOR . "lease-loss";
$leaseLossStore = new FileQueueStore($leaseLossDirectory);
$leaseLossManager = new QueueManager($scopeContainer, $leaseLossStore);
$GLOBALS["runtime_hardening_lease_directory"] = $leaseLossDirectory;
$leaseLossManager->push(RuntimeHardeningLeaseLossJob::class, [], ["correlation_id" => "lease-loss"]);
runtime_assert_same(0, $leaseLossManager->work(1), "Lost lease was reported as a successful job.");
runtime_assert_same(1, $leaseLossStore->pendingCount(), "Lost-lease job was destructively settled.");
$recoveredLeaseJob = $leaseLossStore->pop();
runtime_assert_same(2, $recoveredLeaseJob["attempts"] ?? null, "Lost lease was not recoverable as the next attempt.");
$leaseLossStore->reject($recoveredLeaseJob, "Synthetic lease-loss test complete.");
unset($GLOBALS["runtime_hardening_lease_directory"]);

$transactionPdo = new RuntimeHardeningTransactionPdo();
$database = DatabaseManager::using($transactionPdo);
$effects = [];
$database->transaction(function (DatabaseManager $db) use (&$effects): void {
    $db->afterCommit(static function () use (&$effects): void { $effects[] = "outer-before"; });
    $db->transaction(function (DatabaseManager $nested) use (&$effects): void {
        $nested->afterCommit(static function () use (&$effects): void { $effects[] = "inner"; });
        runtime_assert_same([], $effects, "Nested after-commit effect ran before the outer commit.");
    });
    $db->afterCommit(static function () use (&$effects): void { $effects[] = "outer-after"; });
    runtime_assert_same([], $effects, "After-commit effect ran inside the transaction.");
});
runtime_assert_same(["outer-before", "inner", "outer-after"], $effects, "Nested after-commit order is not deterministic.");
runtime_assert_same("COMMIT", end($transactionPdo->calls), "Outer transaction did not commit before effects.");

$rolledBackEffects = [];
runtime_expect_exception(RuntimeException::class, static function () use ($database, &$rolledBackEffects): void {
    $database->transaction(static function (DatabaseManager $db) use (&$rolledBackEffects): void {
        $db->afterCommit(static function () use (&$rolledBackEffects): void { $rolledBackEffects[] = "forbidden"; });
        throw new RuntimeException("rollback-test");
    });
}, "Rollback fixture did not throw.");
runtime_assert_same([], $rolledBackEffects, "Rollback emitted an after-commit effect.");

runtime_expect_exception(PostCommitCallbackException::class, static function () use ($database): void {
    $database->transaction(static function (DatabaseManager $db): void {
        $db->afterCommit(static function (): never { throw new RuntimeException("synthetic-effect-failure"); });
    });
}, "Post-commit callback failure was not distinguished from rollback.");
runtime_assert_same("COMMIT", end($transactionPdo->calls), "Post-commit failure rewrote the committed transaction.");

$transactionQueueDirectory = $queueDirectory . DIRECTORY_SEPARATOR . "transaction-queue";
$transactionQueueStore = new FileQueueStore($transactionQueueDirectory);
$transactionQueue = new QueueManager($scopeContainer, $transactionQueueStore, $database);
$database->transaction(static function (DatabaseManager $db) use ($transactionQueue, $transactionQueueStore): void {
    runtime_expect_exception(RuntimeException::class, static fn (): string => $transactionQueue->push(RuntimeHardeningQueueJob::class),
        "Direct queue dispatch inside a transaction was accepted.");
    $transactionQueue->pushAfterCommit(RuntimeHardeningQueueJob::class, [], ["correlation_id" => "after-commit-queue"]);
    runtime_assert_same(0, $transactionQueueStore->pendingCount(), "Deferred queue job was visible before commit.");
});
runtime_assert_same(1, $transactionQueueStore->pendingCount(), "Deferred queue job was not persisted after commit.");

$eventDispatcher = new Dispatcher($scopeContainer, $database);
$eventResults = [];
$eventDispatcher->listen("runtime.event", static function () use (&$eventResults): void { $eventResults[] = "handled"; });
$database->transaction(static function (DatabaseManager $db) use ($eventDispatcher, &$eventResults): void {
    runtime_expect_exception(RuntimeException::class, static fn (): array => $eventDispatcher->dispatch("runtime.event"),
        "Direct event dispatch inside a transaction was accepted.");
    $eventDispatcher->dispatchAfterCommit("runtime.event");
    runtime_assert_same([], $eventResults, "Deferred event ran before commit.");
});
runtime_assert_same(["handled"], $eventResults, "Deferred event did not run after commit.");

$mailTransport = new RuntimeHardeningMailTransport();
$container->instance(MailTransportInterface::class, $mailTransport);
config_set("mail.default", "adapter");
config_set("mail.from.address", "no-reply@example.test");
config_set("mail.from.name", "FNLLA Core");
config_set("mail.reply_to.address", "");
$mailer = new Mailer($database);
$database->transaction(static function (DatabaseManager $db) use ($mailer, $mailTransport): void {
    runtime_expect_exception(RuntimeException::class, static function () use ($mailer): void {
        $mailer->send("developer@example.test", "Forbidden", "<p>Forbidden</p>");
    }, "Direct mail inside a transaction was accepted.");
    $mailer->sendAfterCommit("developer@example.test", "Deferred", "<p>Deferred</p>");
    runtime_assert_same(0, count($mailTransport->messages), "Deferred mail was sent before commit.");
});
runtime_assert_same(1, count($mailTransport->messages), "Deferred mail was not sent after commit.");

$inspector = new RuntimeInspector($scopeContainer, $transactionQueueStore);
$inspection = $inspector->report();
runtime_assert_same("fnlla.runtime.inspection.v1", $inspection["schema"] ?? null, "Runtime inspection schema missing.");
runtime_assert_same(JobEnvelope::SCHEMA, $inspection["queue"]["envelope_schema"] ?? null, "Queue envelope diagnostic missing.");
runtime_assert_same(1, $inspection["queue"]["counts"]["pending"] ?? null, "Queue inspection consumed or miscounted jobs.");
runtime_assert_true(!str_contains(json_encode($inspection, JSON_THROW_ON_ERROR), "developer@example.test"),
    "Runtime inspection exposed message or payload data.");

[$inspectExit, $inspectOutput] = run_process([PHP_BINARY, "fnlla", "runtime:inspect"], dirname(__DIR__));
runtime_assert_same(0, $inspectExit, "runtime:inspect CLI failed: " . $inspectOutput);
$inspectJson = json_decode($inspectOutput, true, 512, JSON_THROW_ON_ERROR);
runtime_assert_same("fnlla.runtime.inspection.v1", $inspectJson["schema"] ?? null, "runtime:inspect CLI schema mismatch.");

runtime_remove_directory($queueDirectory);

fwrite(STDOUT, "FNLLA Core runtime hardening tests passed." . PHP_EOL);

function runtime_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function runtime_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . " Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "." . PHP_EOL);
        exit(1);
    }
}

function runtime_expect_exception(string $class, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }
        fwrite(STDERR, $message . " Unexpected exception: " . get_class($exception) . " " . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function runtime_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}
