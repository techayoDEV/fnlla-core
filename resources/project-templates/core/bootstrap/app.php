<?php

declare(strict_types=1);

$container = require __DIR__ . "/common.php";
$container->make(\Fnlla\Php\Product\ProductModuleRegistry::class)->registerServices();
$router = require __DIR__ . "/router.php";
$application = (new \Fnlla\Php\Application($router, $container, $container->make(\Fnlla\Php\Exceptions\ExceptionHandler::class)))
    ->middleware(\Fnlla\Php\Support\ProjectProfile::hasPanel() ? ["trusted-hosts", "cors", "maintenance"] : ["trusted-hosts", "cors"]);
if (config('resilience.enabled', false) === true) {
    if (!class_exists(\Fnlla\Php\Resilience\ResilienceMiddleware::class)) {
        throw new \RuntimeException('Resilience requires a capability-enabled Core package.');
    }
    $application->middleware(\Fnlla\Php\Resilience\ResilienceMiddleware::class);
}
return $application;
