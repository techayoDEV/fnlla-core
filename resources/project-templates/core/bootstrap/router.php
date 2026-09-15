<?php

declare(strict_types=1);

$router = ($rebuildRouteCache ?? false) ? new \Fnlla\Php\Routing\Router($container) : $container->make(\Fnlla\Php\Routing\Router::class);
foreach (["csrf" => \Fnlla\Php\Middleware\VerifyCsrfToken::class,
    "auth" => \Fnlla\Php\Auth\Middleware\Authenticate::class,
    "authorize" => \Fnlla\Php\Auth\Middleware\Authorize::class,
    "cors" => \Fnlla\Php\Middleware\HandleCors::class,
    "throttle" => \Fnlla\Php\Middleware\ThrottleRequests::class,
    "trusted-hosts" => \Fnlla\Php\Middleware\EnforceTrustedHosts::class] as $alias => $class) {
    $router->middleware($alias, $class);
}
$cache = framework_route_cache_path();
$cachedRoutes = ($rebuildRouteCache ?? false) ? null : \Fnlla\Php\Support\PhpArrayCache::routes($cache, \Fnlla\Php\Support\ProjectProfile::name());
if ($cachedRoutes !== null) {
    $router->loadCachedRoutes($cachedRoutes);
} else {
    if (\Fnlla\Php\Support\ProjectProfile::hasPanel() && is_file(base_path("routes/maintenance.php"))) {
        foreach (["developer-operations" => \Fnlla\Php\Middleware\AuthorizeDeveloperOperations::class,
            "customer-session" => \Fnlla\Php\Middleware\RequireCustomerSession::class,
            "developer-session" => \Fnlla\Php\Middleware\RequireDeveloperSession::class,
            "maintenance" => \Fnlla\Php\Middleware\EnforceMaintenanceAccess::class] as $alias => $class) {
            $router->middleware($alias, $class);
        }
        require base_path("routes/maintenance.php");
    }
    require base_path("routes/web.php");
}
return $router;
