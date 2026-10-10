<?php

declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";
require_once __DIR__ . "/fixtures/concurrency.php";

use Fnlla\Php\Cache\RateLimiter;
use Fnlla\Php\Cache\RedisCacheStore;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Queue\RedisQueueStore;

function serviceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function serviceRejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

$redisOnly = in_array("--redis-only", $argv, true);
if ((!$redisOnly && !extension_loaded("pdo_mysql")) || !extension_loaded("redis")) {
    throw new RuntimeException("The service gate requires pdo_mysql and redis extensions.");
}

if (!$redisOnly) {
require __DIR__ . "/OutboxServiceTest.php";
require __DIR__ . "/CapabilityServiceTest.php";
$dsn = getenv("FNLLA_CORE_TEST_MYSQL_DSN");
if (!is_string($dsn) || !str_starts_with($dsn, "mysql:")) {
    throw new RuntimeException("FNLLA_CORE_TEST_MYSQL_DSN must name an isolated MySQL database.");
}
$pdo = new PDO($dsn, (string) getenv("FNLLA_CORE_TEST_MYSQL_USER"), (string) getenv("FNLLA_CORE_TEST_MYSQL_PASSWORD"));
$database = DatabaseManager::using($pdo);
$table = "fnlla_core_it_" . bin2hex(random_bytes(6));
$pdo->exec("CREATE TABLE `{$table}` (`id` INT NOT NULL PRIMARY KEY, `value` VARCHAR(64) NOT NULL) ENGINE=InnoDB");
try {
    $effects = [];
    $database->transaction(function (DatabaseManager $db) use ($table, &$effects): void {
        $db->connection()->exec("INSERT INTO `{$table}` (`id`, `value`) VALUES (1, 'committed')");
        $db->afterCommit(static function () use (&$effects): void { $effects[] = "committed"; });
        try {
            $db->transaction(function (DatabaseManager $nested) use ($table): void {
                $nested->connection()->exec("INSERT INTO `{$table}` (`id`, `value`) VALUES (2, 'rolled-back')");
                throw new RuntimeException("Rollback nested savepoint.");
            });
        } catch (RuntimeException) {
        }
        serviceCheck($effects === [], "After-commit effect ran before the outer commit.");
    });
    serviceCheck($effects === ["committed"], "After-commit effect did not run after commit.");
    serviceCheck((int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() === 1, "Nested rollback changed committed rows.");
} finally {
    $pdo->exec("DROP TABLE `{$table}`");
}
}

$host = getenv("FNLLA_CORE_TEST_REDIS_HOST") ?: "127.0.0.1";
$port = (int) (getenv("FNLLA_CORE_TEST_REDIS_PORT") ?: 6379);
$prefix = "fnlla:core:integration:" . bin2hex(random_bytes(8)) . ":";
$redis = new Redis();
$redis->connect($host, $port, 2.0);
$probeConfig = ["cache" => ["default" => "redis", "stores" => ["redis" => ["host" => $host, "port" => $port]]],
    "queue" => ["default" => "redis", "connections" => ["redis" => ["host" => $host, "port" => $port]]]];
if (!$redisOnly) {
    $dsnParts = [];
    foreach (explode(";", substr($dsn, 6)) as $part) {
        $pair = explode("=", $part, 2);
        if (count($pair) === 2) { $dsnParts[$pair[0]] = $pair[1]; }
    }
    $probeConfig["database"] = ["default" => "mysql", "connections" => ["mysql" => ["driver" => "mysql",
        "host" => $dsnParts["host"] ?? "127.0.0.1", "port" => (int) ($dsnParts["port"] ?? 3306),
        "database" => $dsnParts["dbname"] ?? "", "username" => (string) getenv("FNLLA_CORE_TEST_MYSQL_USER"),
        "password" => (string) getenv("FNLLA_CORE_TEST_MYSQL_PASSWORD")]]];
}
$GLOBALS["fnlla_config"] = $probeConfig;
$readiness = (new Fnlla\Php\Support\RuntimeDoctor())->report(3);
serviceCheck($readiness["status"] === "ready", "Isolated runtime probes did not reach live MySQL/Redis.");
$GLOBALS["fnlla_config"]["cache"]["stores"]["redis"]["password"] = "synthetic-wrong-credential";
$unready = (new Fnlla\Php\Support\RuntimeDoctor())->report(3);
serviceCheck($unready["status"] === "not_ready" && !str_contains(json_encode($unready), "synthetic-wrong-credential"),
    "Readiness accepted invalid credentials or exposed a secret.");
$GLOBALS["fnlla_config"] = ["queue" => [
    "max_attempts" => 2,
    "visibility_timeout_seconds" => 30,
    "idempotency_ttl_seconds" => 60,
]];
try {
    $first = new RedisQueueStore(["host" => $host, "port" => $port, "prefix" => $prefix]);
    $second = new RedisQueueStore(["host" => $host, "port" => $port, "prefix" => $prefix]);
    $metadata = ["job_type" => "integration", "job_version" => 1, "context" => [
        "correlation_id" => "core-integration", "idempotency_key" => "same-effect",
    ]];
    $id = $first->pushWithMetadata("CoreIntegrationJob", [], $metadata);
    $reserved = $first->pop();
    serviceCheck($reserved !== null && $reserved["id"] === $id, "First Redis client did not reserve the job.");
    serviceCheck($second->pop() === null, "Second Redis client reserved an already leased job.");
    $stale = $reserved;
    $stale["reservation"] = "stale-token";
    serviceCheck(!$second->owns($stale), "Stale reservation still owns the job.");
    serviceRejects(static fn () => $second->complete($stale), "Stale reservation settled the job.");
    serviceCheck($first->beginIdempotent($reserved) === "claimed", "Idempotency claim failed.");
    serviceCheck($second->beginIdempotent($reserved) === "busy", "Concurrent idempotency claim was accepted.");
    $first->completeIdempotent($reserved);
    $first->complete($reserved);
    serviceCheck($second->pendingCount() === 0, "Completed Redis job remained pending.");

    $first->pushWithMetadata("CoreIntegrationJob", [], $metadata);
    $replayed = $second->pop();
    serviceCheck($replayed !== null && $second->beginIdempotent($replayed) === "completed", "Durable idempotency marker did not stop replay.");
    $second->complete($replayed);

    // A ready item must not disappear behind more than one legacy scan window.
    for ($index = 0; $index < 251; $index++) {
        $delayed = Fnlla\Php\Queue\JobEnvelope::create("delayed-" . $index, "CoreIntegrationJob", []);
        $delayed["available_at"] = time() + 3600;
        $delayed["attempts"] = 0; $delayed["max_attempts"] = 2;
        $redis->rPush($prefix . "pending", json_encode($delayed, JSON_THROW_ON_ERROR));
    }
    $readyId = $first->push("CoreIntegrationJob", []);
    $ready = $second->pop();
    serviceCheck($ready !== null && $ready["id"] === $readyId, "Delayed backlog hid ready work.");
    $second->complete($ready);
    serviceCheck($redis->zCard($prefix . "delayed") === 251 && $first->pendingCount() === 251, "Delayed migration lost jobs or counts.");
    serviceCheck($first->pop() === null, "Future work was reserved early.");
    $redis->del($prefix . "delayed");

    $retryId = $first->push("CoreIntegrationJob", []);
    $retry = $first->pop();
    serviceCheck($retry !== null, "Cannot reserve retry test job.");
    $first->fail($retry, 'Bearer SYNTHETIC_PRIVATE_QUEUE_ERROR');
    serviceCheck($redis->lLen($prefix . "pending") === 0 && $redis->zCard($prefix . "delayed") === 1, "Retry entered the ready queue early.");
    $rawDelayed = $redis->zRange($prefix . "delayed", 0, 0)[0];
    $due = json_decode($rawDelayed, true, 512, JSON_THROW_ON_ERROR);
    serviceCheck($due['last_error'] === 'job_failed' && !str_contains($rawDelayed, 'SYNTHETIC_PRIVATE_QUEUE_ERROR'), 'Redis retry persisted raw exception text.');
    $due["available_at"] = time() - 1;
    $redis->zRem($prefix . "delayed", $rawDelayed);
    $redis->zAdd($prefix . "delayed", time() - 1, json_encode($due, JSON_THROW_ON_ERROR));
    $retry = $second->pop();
    serviceCheck($retry !== null && $retry["id"] === $retryId && $retry["attempts"] === 2, "Due retry was not promoted.");
    $second->complete($retry);

    $redis->rPush($prefix . "pending", "{invalid-json");
    $afterPoisonId = $first->pushWithMetadata("CoreIntegrationJob", [], [
        "job_type" => "integration", "job_version" => 1,
        "context" => ["correlation_id" => "after-poison"],
    ]);
    $afterPoison = $second->pop();
    serviceCheck($afterPoison !== null && $afterPoison["id"] === $afterPoisonId,
        "Malformed pending Redis payload blocked a later valid job.");
    serviceCheck($second->failedCount() === 1 && $redis->lIndex($prefix . "failed", 0) === "{invalid-json",
        "Malformed pending Redis payload was not quarantined intact.");
    $second->complete($afterPoison);

    $redis->hSet($prefix . "reserved", "corrupt-lease", "{invalid-lease");
    $redis->zAdd($prefix . "leases", time() - 1, "corrupt-lease");
    $afterLeaseId = $first->pushWithMetadata("CoreIntegrationJob", [], [
        "job_type" => "integration", "job_version" => 1,
        "context" => ["correlation_id" => "after-corrupt-lease"],
    ]);
    $afterLease = $second->pop();
    serviceCheck($afterLease !== null && $afterLease["id"] === $afterLeaseId,
        "Malformed expired Redis lease blocked a later valid job.");
    serviceCheck($second->failedCount() === 2 && !$redis->hExists($prefix . "reserved", "corrupt-lease"),
        "Malformed expired Redis lease was not quarantined.");
    $second->complete($afterLease);

    config_set("queue.visibility_timeout_seconds", 2);
    $longMetadata = ["job_type" => "long-running", "context" => ["idempotency_key" => "renewed-effect"]];
    $first->pushWithMetadata("CoreIntegrationJob", [], $longMetadata);
    $long = $first->pop();
    serviceCheck($long !== null && $first->beginIdempotent($long) === "claimed", "Long Redis job claim failed.");
    $long = $first->renew($long, 30);
    $expiry = $long["reserved_until"];
    $long = $first->renew($long, 1);
    serviceCheck($long["reserved_until"] >= $expiry, "Redis renewal shortened a live lease.");
    sleep(3);
    $second->pushWithMetadata("CoreIntegrationJob", [], $longMetadata);
    $duplicate = $second->pop();
    serviceCheck($first->owns($long), "Renewed Redis lease expired early.");
    serviceCheck($duplicate !== null && $second->beginIdempotent($duplicate) === "busy", "Duplicate claimed a renewed Redis job.");
    $first->completeIdempotent($long);
    $first->complete($long);
    serviceCheck($second->beginIdempotent($duplicate) === "completed", "Renewed job completion did not prevent replay.");
    $second->complete($duplicate);

    config_set("queue.visibility_timeout_seconds", 30);
    $first->pushWithMetadata("CoreIntegrationJob", [], ["job_type" => "lost", "context" => ["idempotency_key" => "lost"]]);
    $lost = $first->pop();
    serviceCheck($lost !== null && $first->beginIdempotent($lost) === "claimed", "Lost-claim fixture failed.");
    foreach ($redis->keys($prefix . "idempotency:*") as $key) { $redis->del($key); }
    serviceRejects(static fn () => $first->renew($lost, 60), "Redis renewal recreated a lost claim.");
    serviceRejects(static fn () => $first->completeIdempotent($lost), "Redis completion accepted a lost claim.");
    $first->complete($lost);

    $cacheConfig = ["host" => $host, "port" => $port, "prefix" => $prefix . "cache:"];
    $cache = new RedisCacheStore($cacheConfig);
    $limiter = new RateLimiter($cache);
    $accepted = cache_parallel_workers("redis", json_encode($cacheConfig, JSON_THROW_ON_ERROR), array_fill(0, 6, "rate"));
    serviceCheck(array_sum($accepted) === 17, "Concurrent Redis requests exceeded or underfilled the limit.");
    $ttl = $redis->pTtl($prefix . "cache:rate-limit:parallel");
    serviceCheck(!$limiter->acquire("parallel", 17, 120)["allowed"], "Exhausted Redis limit accepted a request.");
    serviceCheck($redis->pTtl($prefix . "cache:rate-limit:parallel") <= $ttl, "Rejected Redis request extended the window.");
    cache_parallel_workers("redis", json_encode($cacheConfig, JSON_THROW_ON_ERROR), array_fill(0, 6, "increment"));
    serviceCheck($cache->get("parallel-counter") === 240, "Concurrent Redis increments were lost.");
    $redis->set($prefix . "cache:rate-limit:corrupt", "invalid");
    serviceRejects(static fn () => $limiter->acquire("corrupt", 1), "Corrupt Redis counter failed open.");

    $parallelId = $first->pushWithMetadata("CoreIntegrationJob", [], [
        "job_type" => "integration", "job_version" => 1,
        "context" => ["correlation_id" => "parallel-reservation"],
    ]);
    $barrier = tempnam(sys_get_temp_dir(), "fnlla-core-reserve-");
    if ($barrier === false) {
        throw new RuntimeException("Cannot create reservation barrier.");
    }
    $workers = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $process = proc_open([PHP_BINARY, __DIR__ . "/fixtures/service-redis-worker.php", $host,
                (string) $port, $prefix, $barrier], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
            serviceCheck(is_resource($process), "Cannot start a competing Redis worker.");
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        serviceCheck(file_put_contents($barrier, "go") === 2, "Cannot release competing Redis workers.");
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $output = trim((string) stream_get_contents($pipes[1]));
            $error = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            serviceCheck(proc_close($process) === 0, "Competing Redis worker failed: " . $error);
            $results[] = $output;
        }
        sort($results);
        $expected = ["none", $parallelId];
        sort($expected);
        serviceCheck($results === $expected, "Concurrent Redis workers reserved the same job or lost it.");
    } finally {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) { fclose($pipe); }
                }
                proc_close($process);
            }
        }
        unlink($barrier);
    }
} finally {
    $keys = $redis->keys($prefix . "*");
    if ($keys !== []) {
        $redis->del($keys);
    }
    $redis->close();
}

fwrite(STDOUT, ($redisOnly ? "Redis" : "MySQL transaction and Redis") . " reservation/idempotency and cache integration passed." . PHP_EOL);
