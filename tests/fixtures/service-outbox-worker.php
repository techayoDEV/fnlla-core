<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . "/vendor/autoload.php";

use Fnlla\Php\Actions\DatabaseActionStore;
use Fnlla\Php\Database\DatabaseManager;

$prefix = (string) ($argv[1] ?? "");
$barrier = (string) ($argv[2] ?? "");
if (preg_match('/^outbox_test_[a-f0-9]+$/D', $prefix) !== 1) { throw new RuntimeException("Invalid test prefix."); }
$GLOBALS["fnlla_config"] = ["actions" => ["receipts_table" => $prefix . "_receipts",
    "outbox_table" => $prefix . "_messages", "delivery_table" => $prefix . "_delivery"]];
$pdo = new PDO((string) getenv("FNLLA_CORE_TEST_MYSQL_DSN"), (string) getenv("FNLLA_CORE_TEST_MYSQL_USER"),
    (string) getenv("FNLLA_CORE_TEST_MYSQL_PASSWORD"));
$store = new DatabaseActionStore(DatabaseManager::using($pdo));
$deadline = microtime(true) + 10;
while (trim((string) file_get_contents($barrier)) !== "go") {
    if (microtime(true) > $deadline) { throw new RuntimeException("Outbox reservation barrier timed out."); }
    usleep(1000);
}
$message = $store->claimDelivery(30, 2);
echo json_encode($message === null ? null : ["id" => $message["id"], "token" => $message["token"]], JSON_THROW_ON_ERROR);
