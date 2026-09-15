<?php

declare(strict_types=1);

$container = require __DIR__ . "/common.php";
$router = require __DIR__ . "/router.php";
return (new \Fnlla\Php\Application($router, $container, $container->make(\Fnlla\Php\Exceptions\ExceptionHandler::class)))
    ->middleware(\Fnlla\Php\Support\ProjectProfile::hasPanel() ? ["trusted-hosts", "cors", "maintenance"] : ["trusted-hosts", "cors"]);
