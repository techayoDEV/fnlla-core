<?php

declare(strict_types=1);

use App\Controllers\HomeController as CoreHomeController;

$router->get("/", [CoreHomeController::class, "index"])->name("home");
$router->get("/api/health", [CoreHomeController::class, "health"])->name("api.health");
