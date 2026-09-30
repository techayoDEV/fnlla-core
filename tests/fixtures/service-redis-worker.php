<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/vendor/autoload.php";

use Fnlla\Php\Queue\RedisQueueStore;

[$script, $host, $port, $prefix, $barrier] = $argv;
$deadline = microtime(true) + 10;
while (trim((string) file_get_contents($barrier)) !== "go") {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "Reservation barrier timed out.\n");
        exit(1);
    }
    usleep(1000);
}
$GLOBALS["fnlla_config"] = ["queue" => ["visibility_timeout_seconds" => 30]];
$store = new RedisQueueStore(["host" => $host, "port" => (int) $port, "prefix" => $prefix]);
$job = $store->pop();
fwrite(STDOUT, $job === null ? "none\n" : $job["id"] . "\n");
