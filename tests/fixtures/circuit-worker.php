<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Fnlla\\Php\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php'; }
});
require $root . '/src/Support/helpers.php';
$directory = $argv[1];
$store = new \Fnlla\Php\Cache\FileCacheStore($directory . '/coordination');
$store->increment('ready', 1, 30);
$deadline = microtime(true) + 3;
while ($store->get('ready', 0) < 2) {
    if (microtime(true) > $deadline) { exit(2); }
    usleep(10000);
}
$breaker = new \Fnlla\Php\Resilience\CircuitBreaker($directory . '/state', new \Fnlla\Php\Resilience\ResilienceEvents(), 1, 1, 10, static fn (): int => 102);
try {
    $breaker->run('parallel_api', static function () use ($store): void { $store->increment('calls'); usleep(300000); });
} catch (\Fnlla\Php\Resilience\DependencyUnavailable) {}
