<?php

declare(strict_types=1);

if (!function_exists("config")) {
    require_once dirname(__DIR__) . "/vendor/autoload.php";
}
require_once __DIR__ . "/fixtures/concurrency.php";

use Fnlla\Php\Cache\FileCacheStore;
use Fnlla\Php\Cache\RateLimiter;
use Fnlla\Php\Queue\FileQueueStore;

function concurrency_check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function concurrency_rejects(callable $operation, string $message): void
{
    try { $operation(); } catch (RuntimeException) { return; }
    throw new RuntimeException($message);
}

$concurrencyRoot = sys_get_temp_dir() . "/fnlla-concurrency-" . bin2hex(random_bytes(8));
mkdir($concurrencyRoot, 0700);
$savedQueueConfig = config("queue", []);
try {
    $cache = new FileCacheStore($concurrencyRoot . "/cache");
    $limiter = new RateLimiter($cache);
    $accepted = cache_parallel_workers("file", $concurrencyRoot . "/cache", array_fill(0, 6, "rate"));
    concurrency_check(array_sum($accepted) === 17, "Parallel requests exceeded or underfilled the rate limit.");
    $counterPath = $concurrencyRoot . "/cache/" . sha1("rate-limit:parallel") . ".cache";
    $before = file_get_contents($counterPath);
    concurrency_check(!$limiter->acquire("parallel", 17, 60)["allowed"], "Exhausted limit accepted a request.");
    concurrency_check(file_get_contents($counterPath) === $before, "Rejected request changed the counter or expiry.");
    $limiter->clear("parallel");
    concurrency_check($limiter->acquire("parallel", 17)["attempts"] === 1, "Clearing the limiter did not reset admission.");
    cache_parallel_workers("file", $concurrencyRoot . "/cache", array_fill(0, 6, "increment"));
    concurrency_check($cache->get("parallel-counter") === 240, "Concurrent increments were lost.");
    $cache->put("large", str_repeat("x", 131072));
    cache_parallel_workers("file", $concurrencyRoot . "/cache", ["write", "read", "write", "read"]);

    file_put_contents($concurrencyRoot . "/cache/" . sha1("rate-limit:corrupt") . ".cache", "{broken");
    concurrency_rejects(fn () => $limiter->acquire("corrupt", 1), "Corrupt rate counter failed open.");
    $unavailable = new FileCacheStore($concurrencyRoot . '/broken-lock');
    mkdir($concurrencyRoot . '/broken-lock/lock-v2-' . substr(sha1('rate-limit:locked'), 0, 2) . '.lock');
    concurrency_rejects(fn () => (new RateLimiter($unavailable))->acquire("locked", 1), "Unavailable cache lock failed open.");
    $bounded = new FileCacheStore($concurrencyRoot . '/bounded-locks');
    for ($key = 0; $key < 1000; $key++) { $bounded->get('missing-' . $key); }
    $locks = glob($concurrencyRoot . '/bounded-locks/*.lock') ?: [];
    concurrency_check(count($locks) > 0 && count($locks) <= 256, 'Cache miss lock cardinality is unbounded.');
    $bounded->put('live', 'preserved', 3600);
    file_put_contents($concurrencyRoot . '/bounded-locks/' . sha1('expired') . '.cache', json_encode(['expires_at' => time() - 1, 'value' => 'old'], JSON_THROW_ON_ERROR));
    concurrency_check($bounded->pruneExpired() === 1 && $bounded->get('live') === 'preserved', 'Pruning removed live data or retained expired data.');
    $bounded->clear();
    concurrency_check(count(glob($concurrencyRoot . '/bounded-locks/*.lock') ?: []) <= 256, 'Clear created extra locks or removed live synchronization.');
    mkdir($concurrencyRoot . "/cache/" . sha1("rate-limit:unwritable") . ".cache");
    concurrency_rejects(fn () => $limiter->acquire("unwritable", 1), "Failed cache write granted a slot.");
    concurrency_rejects(fn () => $cache->increment("rate-limit:unwritable"), "Failed increment returned success.");

    config_set("queue", ["max_attempts" => 2, "visibility_timeout_seconds" => 2]);
    $queue = new FileQueueStore($concurrencyRoot . "/queue");
    $metadata = ["job_type" => "long-job", "context" => ["idempotency_key" => "same-effect"]];
    $queue->pushWithMetadata("LongRunningJob", [], $metadata);
    $first = $queue->pop();
    concurrency_check($first !== null && $queue->beginIdempotent($first) === "claimed", "Cannot claim long job.");
    $first = $queue->renew($first, 30);
    $expiry = $first["reserved_until"];
    $first = $queue->renew($first, 1);
    concurrency_check($first["reserved_until"] >= $expiry, "Renewal shortened the lease or duplicate guard.");
    sleep(3);
    $queue->pushWithMetadata("LongRunningJob", [], $metadata);
    $duplicate = $queue->pop();
    concurrency_check($queue->owns($first), "Renewed job lost its lease.");
    concurrency_check($duplicate !== null && $queue->beginIdempotent($duplicate) === "busy", "Duplicate claimed a renewed job.");
    $queue->completeIdempotent($first);
    $queue->complete($first);
    concurrency_check($queue->beginIdempotent($duplicate) === "completed", "Completion marker did not stop replay.");
    $queue->complete($duplicate);

    $queue->pushWithMetadata("LongRunningJob", [], ["job_type" => "lost-claim", "context" => ["idempotency_key" => "lost"]]);
    $lost = $queue->pop();
    concurrency_check($lost !== null && $queue->beginIdempotent($lost) === "claimed", "Cannot claim lost-guard fixture.");
    foreach (glob($concurrencyRoot . "/queue/idempotency/*.json") ?: [] as $record) { unlink($record); }
    concurrency_rejects(fn () => $queue->renew($lost, 60), "Renewal recreated a lost idempotency claim.");
    concurrency_rejects(fn () => $queue->completeIdempotent($lost), "Completion accepted a lost claim.");
} finally {
    config_set("queue", $savedQueueConfig);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($concurrencyRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($concurrencyRoot);
}
echo "Queue renewal, atomic rate limit and cache concurrency tests passed.\n";
