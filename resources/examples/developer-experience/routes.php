<?php

declare(strict_types=1);

use App\Controllers\DemoController;

// Append to routes/web.php; preserve the generated routes.
$router->get('/demo/token', [DemoController::class, 'token']);
$router->post('/demo/login', [DemoController::class, 'login'])->middleware(['csrf']);
$router->get('/demo/report', [DemoController::class, 'report'])->middleware(['auth']);
