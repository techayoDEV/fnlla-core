<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/vendor/autoload.php";

use Fnlla\Php\Cache\FileCacheStore;
use Fnlla\Php\Cache\RateLimiter;
use Fnlla\Php\Cache\RedisCacheStore;

[$script, $backend, $location, $barrier, $operation] = $argv;
$store = $backend === "redis"
    ? new RedisCacheStore(json_decode($location, true, 512, JSON_THROW_ON_ERROR))
    : new FileCacheStore($location);
$deadline = microtime(true) + 20;
while (trim((string) file_get_contents($barrier)) !== "go") {
    if (microtime(true) > $deadline) { throw new RuntimeException("Cache barrier timed out."); }
    usleep(1000);
}
$accepted = 0;
for ($i = 0; $i < 40; $i++) {
    if ($operation === "rate") {
        $accepted += (int) (new RateLimiter($store))->acquire("parallel", 17, 60)["allowed"];
    } elseif ($operation === "increment") {
        $store->increment("parallel-counter");
    } elseif ($operation === "write") {
        $store->put("large", str_repeat((string) ($i % 10), 131072));
    } elseif ($operation === "read") {
        $value = $store->get("large");
        if (!is_string($value) || strlen($value) !== 131072 || $value !== str_repeat($value[0], 131072)) {
            throw new RuntimeException("Observed a missing or partially written cache value.");
        }
    } else {
        throw new RuntimeException("Unknown cache worker operation.");
    }
}
echo $accepted;
