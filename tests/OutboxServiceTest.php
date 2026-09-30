<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/vendor/autoload.php";

use Fnlla\Php\Actions\DatabaseActionStore;
use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Audit\AuditLoggerInterface;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\Dispatcher;
use Fnlla\Php\Events\DomainEventBus;
use Fnlla\Php\Events\OutboxWorker;
use Fnlla\Php\Queue\FileQueueStore;
use Fnlla\Php\Queue\QueueManager;

function outboxCheck(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
function outboxReject(callable $callback, string $message): void {
    try { $callback(); } catch (RuntimeException) { return; }
    throw new RuntimeException($message);
}

$dsn = getenv("FNLLA_CORE_TEST_MYSQL_DSN");
if (!is_string($dsn) || !str_starts_with($dsn, "mysql:")) { throw new RuntimeException("An isolated MySQL DSN is required."); }
$connect = static fn (): PDO => new PDO($dsn, (string) getenv("FNLLA_CORE_TEST_MYSQL_USER"), (string) getenv("FNLLA_CORE_TEST_MYSQL_PASSWORD"));
$pdo = $connect();
$db = DatabaseManager::using($pdo);
$secondDb = DatabaseManager::using($connect());
$prefix = "outbox_test_" . bin2hex(random_bytes(5));
$previous = $GLOBALS["fnlla_config"] ?? [];
$GLOBALS["fnlla_config"] = ["actions" => ["receipts_table" => $prefix . "_receipts", "outbox_table" => $prefix . "_messages",
    "delivery_table" => $prefix . "_delivery", "reliable_outbox" => true, "outbox" => ["retry_seconds" => 1, "max_attempts" => 2]]];
$store = new DatabaseActionStore($db);
$second = new DatabaseActionStore($secondDb);
$queuePath = sys_get_temp_dir() . "/fnlla-outbox-test-" . bin2hex(random_bytes(5));
$container = new Container();
$events = new DomainEventBus($container, new Dispatcher($container), new QueueManager($container, new FileQueueStore($queuePath)));
$audit = new class implements AuditLoggerInterface {
    public array $ids = [];
    public bool $fail = false;
    public function record(AuditEvent $event): void {
        if ($this->fail) { throw new RuntimeException("private-driver-secret"); }
        $this->ids[] = $event->subjectId;
    }
};
$worker = new OutboxWorker($store, $audit, $events, $container);
$seed = function (string $id, string $subject) use ($db, $store): void {
    $payload = (new AuditEvent("item.update", "test", "actor-1", "item", $subject, "tenant-1", "test-correlation"))->toArray();
    $db->transaction(fn () => $store->append($id, "audit", "item.update", $payload));
};
try {
    $store->installSchema();
    $seed("parallel", "parallel");
    $barrier = tempnam(sys_get_temp_dir(), "fnlla-outbox-barrier-");
    if ($barrier === false) { throw new RuntimeException("Cannot allocate barrier."); }
    $processes = [];
    $owners = [];
    try {
        for ($index = 0; $index < 4; $index++) {
            $process = proc_open([PHP_BINARY, __DIR__ . "/fixtures/service-outbox-worker.php", $prefix, $barrier],
                [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
            if (!is_resource($process)) { throw new RuntimeException("Cannot start outbox worker."); }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $processes[] = [$process, $pipes];
        }
        file_put_contents($barrier, "go");
        foreach ($processes as [$process, $pipes]) {
            $output = ""; $error = ""; $deadline = microtime(true) + 15;
            do {
                $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
                $state = proc_get_status($process);
                if (microtime(true) > $deadline) { throw new RuntimeException("Outbox worker timed out."); }
                usleep(1000);
            } while ($state["running"]);
            $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
            outboxCheck($state["exitcode"] === 0, "Concurrent outbox worker failed: " . $error);
            $owner = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
            if ($owner !== null) { $owners[] = $owner; }
        }
        outboxCheck(count($owners) === 1 && $owners[0]["id"] === "parallel", "Concurrent workers share an outbox lease.");
        $store->acknowledgeDelivery("parallel", $owners[0]["token"]);
    } finally {
        foreach ($processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
        unlink($barrier);
    }
    $seed("exclusive", "one");
    $job = $store->claimDelivery(30, 2);
    outboxCheck($job !== null && $second->claimDelivery(30, 2) === null, "Two clients reserved the same delivery.");
    outboxReject(fn () => $second->acknowledgeDelivery("exclusive", "wrong"), "Stale token acknowledged delivery.");
    outboxReject(fn () => $db->transaction(fn () => $store->claimDelivery(30, 2)), "Worker ran inside application transaction.");
    $pdo->exec("UPDATE {$prefix}_delivery SET leased_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)");
    $recovered = $second->claimDelivery(30, 2);
    outboxCheck($recovered !== null && $recovered["token"] !== $job["token"] && $recovered["attempts"] === 2, "Expired delivery was not recovered.");
    outboxReject(fn () => $store->acknowledgeDelivery("exclusive", $job["token"]), "Expired owner acknowledged reclaimed delivery.");
    $second->acknowledgeDelivery("exclusive", $recovered["token"]);
    outboxCheck(!$store->retryDelivery("exclusive"), "Published delivery was retried.");
    outboxReject(fn () => $store->pending(), "Legacy publisher bypassed delivery leases.");

    $seed("poison", "poison");
    $pdo->exec("UPDATE {$prefix}_messages SET payload_json = '{broken' WHERE message_id = 'poison'");
    $seed("healthy", "healthy");
    $report = $worker->work();
    outboxCheck($report["failed"] === 1 && $report["published"] === 1 && $audit->ids === ["healthy"], "Poison blocked valid delivery.");
    outboxCheck(!$container->hasActiveScope(), "Outbox leaked work scope.");
    $status = $store->deliveryStatus();
    outboxCheck(count($status) === 1 && $status[0]["state"] === "failed", "Poison was not quarantined.");
    outboxCheck(!array_key_exists("payload", $status[0]) && !array_key_exists("payload_json", $status[0])
        && !str_contains(json_encode($status), "{broken"), "Outbox status exposed payloads.");
    $db->transaction(fn () => $store->append("invalid-structure", "domain_event", "item.update", []));
    outboxCheck($worker->work()["failed"] === 1, "Structurally invalid event was retried instead of quarantined.");
    $invalidAudit = (new AuditEvent("item.update", "test", "actor-1", "item", "synthetic", "tenant-1", "correlation"))->toArray();
    $invalidAudit["subject"]["id"] = ["must-not-coerce"];
    $db->transaction(fn () => $store->append("invalid-identity", "audit", "item.update", $invalidAudit));
    outboxCheck($worker->work()["failed"] === 1, "Invalid identity was coerced into an audit event.");

    $seed("transient", "retried");
    $audit->fail = true;
    outboxCheck($worker->work()["retried"] === 1, "Transient delivery was not retried.");
    outboxCheck($worker->work()["retried"] === 0, "Backoff did not postpone delivery.");
    $pdo->exec("UPDATE {$prefix}_delivery SET available_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE message_id = 'transient'");
    outboxCheck($worker->work()["failed"] === 1, "Retries did not stop at the configured limit.");
    outboxCheck(!str_contains(json_encode($store->deliveryStatus()), "private-driver-secret"), "Outbox persisted private error text.");
    outboxCheck($store->retryDelivery("transient") && !$second->retryDelivery("transient"), "Manual retry was not compare-and-set.");
    $audit->fail = false;
    outboxCheck($worker->work()["published"] === 1, "Manual retry failed to deliver.");
    $seed("after-rollback", "never");
    $db->transaction(function () use ($db, $store): void {
        try {
            $db->transaction(function () use ($store): void { $store->append("rolled-back", "audit", "item.update", []); throw new RuntimeException(); });
        } catch (RuntimeException) {}
    });
    outboxCheck((int) $pdo->query("SELECT COUNT(*) FROM {$prefix}_messages WHERE message_id = 'rolled-back'")->fetchColumn() === 0, "Rolled-back outbox persisted.");
} finally {
    foreach (["_delivery", "_messages", "_receipts"] as $suffix) { $pdo->exec("DROP TABLE IF EXISTS {$prefix}{$suffix}"); }
    if (is_dir($queuePath)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($queuePath, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($queuePath);
    }
    $GLOBALS["fnlla_config"] = $previous;
}
echo "MySQL outbox leases, poison isolation, retry, replay and rollback tests passed.\n";
