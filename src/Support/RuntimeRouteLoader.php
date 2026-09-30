<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Routing\Router;
use RuntimeException;

final class RuntimeRouteLoader
{
    public static function load(Container $container): ?Router
    {
        $path = base_path("bootstrap/router.php");
        if (!is_file($path)) {
            return null;
        }
        $router = $container->make(Router::class);
        if ($router->getRoutes() !== []) {
            return $router;
        }
        // Execute trusted application registration, never dispatch a request.
        $loaded = require $path;
        if (!$loaded instanceof Router) {
            throw new RuntimeException("Application router bootstrap must return a Router.");
        }
        return $loaded;
    }
}
