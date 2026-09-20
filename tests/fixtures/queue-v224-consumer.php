<?php

declare(strict_types=1);

$coreRoot = realpath((string) ($argv[1] ?? ""));
if ($coreRoot === false || !is_file($coreRoot . "/src/Queue/QueueStoreInterface.php")) {
    fwrite(STDERR, "Usage: php queue-v224-consumer.php <fnlla-core-root>" . PHP_EOL);
    exit(64);
}

if (!defined("APP_ROOT")) {
    define("APP_ROOT", $coreRoot);
}

spl_autoload_register(static function (string $class) use ($coreRoot): void {
    $prefix = "Fnlla\\Php\\";
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = $coreRoot . "/src/" . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
    if (is_file($path)) {
        require $path;
    }
});

require_once $coreRoot . "/src/Support/helpers.php";

use Fnlla\Php\Container\Container;
use Fnlla\Php\Queue\QueueManager;
use Fnlla\Php\Queue\QueueStoreInterface;

/**
 * Exact consumer-side implementation of the public v2.2.4 store interface.
 * Deliberately contains no post-v2.2.4 queue capability methods.
 */
final class PublishedV224QueueStore implements QueueStoreInterface
{
    /** @var list<array{id:string,job:string,payload:array,source:string}> */
    private array $pending = [];
    private int $failed = 0;
    public int $pushCalls = 0;
    public ?int $lastPushArgumentCount = null;
    public int $completed = 0;

    public function push(string $jobClass, array $payload = []): string
    {
        $this->pushCalls++;
        $this->lastPushArgumentCount = func_num_args();
        $id = "legacy-" . $this->pushCalls;
        $this->pending[] = [
            "id" => $id,
            "job" => $jobClass,
            "payload" => $payload,
            "source" => "memory://" . $id,
        ];
        return $id;
    }

    public function pop(): ?array
    {
        return array_shift($this->pending);
    }

    public function complete(array $job): void
    {
        $this->completed++;
    }

    public function fail(array $job): string
    {
        $this->failed++;
        return "memory://failed/" . (string) ($job["id"] ?? "unknown");
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function failedCount(): int
    {
        return $this->failed;
    }
}

final class PublishedV224QueueJob
{
    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}

function v224_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $reflection = new ReflectionClass(QueueStoreInterface::class);
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    $methodNames = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods);
    sort($methodNames, SORT_STRING);
    v224_assert(
        $methodNames === ["complete", "fail", "failedCount", "pendingCount", "pop", "push"],
        "QueueStoreInterface does not match the published v2.2.4 method set."
    );

    $pushParameters = $reflection->getMethod("push")->getParameters();
    v224_assert(count($pushParameters) === 2, "Published v2.2.4 push must have exactly two parameters.");
    v224_assert($pushParameters[0]->getName() === "jobClass", "Published push parameter 1 name changed.");
    v224_assert($pushParameters[1]->getName() === "payload", "Published push parameter 2 name changed.");
    v224_assert($pushParameters[1]->isDefaultValueAvailable(), "Published push payload default is missing.");
    v224_assert($pushParameters[1]->getDefaultValue() === [], "Published push payload default changed.");

    $consumerMethods = get_class_methods(PublishedV224QueueStore::class);
    sort($consumerMethods, SORT_STRING);
    v224_assert(
        $consumerMethods === ["complete", "fail", "failedCount", "pendingCount", "pop", "push"],
        "The v2.2.4 consumer fixture contains methods outside the published store contract."
    );

    $GLOBALS["fnlla_config"] = ["queue" => ["worker_max_seconds" => 5]];
    $container = new Container();
    $GLOBALS["fnlla_container"] = $container;
    $GLOBALS["fnlla_php_container"] = $container;
    $container->instance(Container::class, $container);
    $store = new PublishedV224QueueStore();
    $container->instance(QueueStoreInterface::class, $store);
    $manager = new QueueManager($container);

    $beforeRejectedDispatch = $store->pushCalls;
    try {
        $manager->push(PublishedV224QueueJob::class, [], ["correlation_id" => "requires-metadata"]);
        throw new RuntimeException("Context-aware dispatch was accepted by a legacy queue store.");
    } catch (RuntimeException $exception) {
        v224_assert(
            str_contains($exception->getMessage(), "ReliableQueueStoreInterface"),
            "Missing reliable capability did not produce an explicit error."
        );
    }
    v224_assert($store->pushCalls === $beforeRejectedDispatch, "Rejected dispatch partially wrote a queue job.");
    v224_assert($store->pendingCount() === 0, "Rejected dispatch left pending queue state.");

    $jobId = $manager->push(PublishedV224QueueJob::class);
    v224_assert($jobId === "legacy-1", "Legacy push returned an unexpected identifier.");
    v224_assert($store->lastPushArgumentCount === 2, "QueueManager passed a third argument to v2.2.4 push.");
    v224_assert($manager->work(1, 5) === 1, "Legacy QueueManager work did not complete one job.");
    v224_assert(PublishedV224QueueJob::$handled === 1, "Legacy queued job handler did not run.");
    v224_assert($store->completed === 1, "Legacy queued job was not acknowledged.");
    v224_assert($store->pendingCount() === 0, "Legacy queue retained an acknowledged job.");
    v224_assert($store->failedCount() === 0, "Legacy queue recorded an unexpected failure.");

    fwrite(STDOUT, json_encode([
        "status" => "PASS",
        "fixture_contract" => "techayodev/fnlla-core@b79904abde2897ca494c940dc1df1f08d0d94c0c",
        "push_argument_count" => $store->lastPushArgumentCount,
        "handled" => PublishedV224QueueJob::$handled,
        "context_rejected_before_push" => true,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage() . PHP_EOL);
    exit(1);
}
