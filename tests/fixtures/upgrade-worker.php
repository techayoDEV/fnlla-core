<?php
declare(strict_types=1);

[$script, $engine, $phase, $stateFile] = $argv;
require $engine . "/src/Support/helpers.php";
spl_autoload_register(static function (string $class) use ($engine): void {
    if (str_starts_with($class, "Fnlla\\Php\\")) {
        $path = $engine . "/src/" . str_replace("\\", "/", substr($class, 10)) . ".php";
        if (is_file($path)) { require $path; }
    }
});

use Fnlla\Php\Actions\DatabaseActionStore;
use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Audit\AuditLoggerInterface;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\Dispatcher;
use Fnlla\Php\Events\DomainEventBus;
use Fnlla\Php\Events\OutboxWorker;
use Fnlla\Php\Queue\QueueManager;
use Fnlla\Php\Queue\RedisQueueStore;

function upgradeAssert(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
$state = json_decode((string) file_get_contents($stateFile), true, 32, JSON_THROW_ON_ERROR);
$prefix = $state["prefix"];
upgradeAssert(preg_match('/^upgrade_[a-f0-9]{12}$/D', $prefix) === 1, "Invalid isolated prefix.");
$dsn = (string) getenv("FNLLA_CORE_TEST_MYSQL_DSN");
upgradeAssert(str_contains($dsn, "dbname=fnlla_core_test"), "Isolated database required.");
$pdo = new PDO($dsn, (string) getenv("FNLLA_CORE_TEST_MYSQL_USER"), (string) getenv("FNLLA_CORE_TEST_MYSQL_PASSWORD"));
$database = DatabaseManager::using($pdo);
$redis = new Redis();
$host = getenv("FNLLA_CORE_TEST_REDIS_HOST") ?: "127.0.0.1";
$port = (int) (getenv("FNLLA_CORE_TEST_REDIS_PORT") ?: 6379);
$redis->connect($host, $port, 2);
$GLOBALS["fnlla_config"] = ["actions" => ["receipts_table" => $prefix . "_receipts", "outbox_table" => $prefix . "_outbox",
    "delivery_table" => $prefix . "_delivery", "reliable_outbox" => !in_array($phase, ["seed", "verify-rollback"], true)],
    "queue" => ["max_attempts" => 5, "visibility_timeout_seconds" => 60, "retry_backoff_seconds" => 3600]];
$store = new DatabaseActionStore($database);
$queue = new RedisQueueStore(["host" => $host, "port" => $port, "prefix" => $prefix . ":"]);

if ($phase === "seed") {
    $store->installSchema();
    $state["delayed_id"] = $queue->push("SyntheticUpgradeJob", ["marker" => "delayed"]);
    $delayed = $queue->pop();
    upgradeAssert($delayed !== null, "Baseline did not reserve seed job.");
    $queue->fail($delayed);
    $state["ready_id"] = $queue->push("SyntheticUpgradeJob", ["marker" => "ready"]);
    $payload = (new AuditEvent("item.update", "system", null, "item", "synthetic", null, "upgrade-drill"))->toArray();
    $database->transaction(fn () => $store->append("upgrade-audit", "audit", "item.update", $payload));
    // Capture the pre-upgrade state while workers are stopped. Only synthetic data.
    $backup = [];
    foreach ($redis->keys($prefix . ":*") as $key) { $backup[$key] = base64_encode($redis->dump($key)); }
    file_put_contents($stateFile . ".redis-backup.json", json_encode($backup, JSON_THROW_ON_ERROR));
    file_put_contents($stateFile . ".outbox-backup.json", json_encode($store->pending(), JSON_THROW_ON_ERROR));
    upgradeAssert($queue->pendingCount() === 2, "Baseline queue count differs.");
} elseif ($phase === "migrate") {
    $store->installDeliverySchema();
    $job = $queue->pop();
    upgradeAssert($job !== null && $job["id"] === $state["ready_id"], "Upgrade hid baseline ready work.");
    upgradeAssert($redis->zCard($prefix . ":delayed") === 1, "Legacy delayed work was not migrated.");
    $state["crashed_job"] = $job;
    // This process exits without settling the reservation, simulating a crash.
} elseif ($phase === "recover") {
    $raw = json_decode($redis->hGet($prefix . ":reserved", $state["ready_id"]), true, 32, JSON_THROW_ON_ERROR);
    $raw["reserved_until"] = time() - 1;
    $redis->hSet($prefix . ":reserved", $state["ready_id"], json_encode($raw, JSON_THROW_ON_ERROR));
    $redis->zAdd($prefix . ":leases", time() - 1, $state["ready_id"]);
    $job = $queue->pop();
    upgradeAssert($job !== null && $job["id"] === $state["ready_id"] && $job["reservation"] !== $state["crashed_job"]["reservation"], "Crashed reservation was not reclaimed.");
    upgradeAssert(!$queue->owns($state["crashed_job"]), "Crashed worker retained ownership.");
    $queue->complete($job);
    $container = new Container();
    $audit = new class implements AuditLoggerInterface {
        public int $count = 0;
        public function record(AuditEvent $event): void { $this->count++; }
    };
    $events = new DomainEventBus($container, new Dispatcher($container), new QueueManager($container, $queue));
    $worker = new OutboxWorker($store, $audit, $events, $container);
    upgradeAssert($worker->work()["published"] === 1 && $worker->work()["published"] === 0 && $audit->count === 1, "Migrated outbox duplicated or lost delivery.");
} elseif ($phase === "reconcile") {
    upgradeAssert($redis->hLen($prefix . ":reserved") === 0, "Rollback requires stopped and settled workers.");
    // Explicit reconciliation for a rollback: retain published_at, move delayed
    // envelopes back to the old list. Never blindly restore already-sent effects.
    $redis->eval("for _, raw in ipairs(redis.call('ZRANGE', KEYS[1], 0, -1)) do redis.call('RPUSH', KEYS[2], raw) end redis.call('DEL', KEYS[1]) return 1",
        [$prefix . ":delayed", $prefix . ":pending"], 2);
} elseif ($phase === "verify-rollback") {
    upgradeAssert($queue->pendingCount() === 1 && $queue->pop() === null, "Rollback lost or prematurely ran delayed work.");
    $raw = json_decode($redis->lIndex($prefix . ":pending", 0), true, 32, JSON_THROW_ON_ERROR);
    $raw["available_at"] = time() - 1; // Advance synthetic time; do not wait one hour.
    $redis->lSet($prefix . ":pending", 0, json_encode($raw, JSON_THROW_ON_ERROR));
    $job = $queue->pop();
    upgradeAssert($job !== null && $job["id"] === $state["delayed_id"] && $job["payload"]["marker"] === "delayed", "Old worker cannot consume reconciled envelope.");
    $queue->complete($job);
    upgradeAssert($queue->pendingCount() === 0 && $store->pending() === [], "Rollback replayed published outbox or left jobs behind.");
} elseif ($phase === "cleanup") {
    foreach (["_delivery", "_outbox", "_receipts"] as $suffix) { $pdo->exec("DROP TABLE IF EXISTS `{$prefix}{$suffix}`"); }
    $keys = $redis->keys($prefix . ":*");
    if ($keys !== []) { $redis->del($keys); }
} else { throw new RuntimeException("Unknown drill phase."); }
file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
echo "Upgrade drill phase passed: " . $phase . "\n";
