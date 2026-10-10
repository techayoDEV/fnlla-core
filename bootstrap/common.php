<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA BOOTSTRAP FILE
File: bootstrap\common.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Bootstraps a framework runtime stage or shared application environment boundary.
*/

use Fnlla\Php\Container\Container;
use Fnlla\Php\Support\Env;
use Fnlla\Php\Support\ServiceProvider;

if (!defined("APP_ROOT")) {
    define("APP_ROOT", dirname(__DIR__));
}

if (!defined("PUBLIC_ROOT")) {
    define("PUBLIC_ROOT", APP_ROOT . DIRECTORY_SEPARATOR . "public");
}

if (!defined("VIEW_ROOT")) {
    define("VIEW_ROOT", APP_ROOT . DIRECTORY_SEPARATOR . "views");
}

$composerAutoload = APP_ROOT . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";

if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    $autoloadPrefixes = [
        "Fnlla\\Php\\" => "src/",
    ];
    $composerJsonPath = APP_ROOT . DIRECTORY_SEPARATOR . "composer.json";

    if (is_file($composerJsonPath)) {
        $composerConfig = json_decode((string) file_get_contents($composerJsonPath), true);
        $composerPrefixes = $composerConfig["autoload"]["psr-4"] ?? null;

        if (is_array($composerPrefixes) && $composerPrefixes !== []) {
            $autoloadPrefixes = $composerPrefixes;
        }
    }

    spl_autoload_register(static function (string $class) use ($autoloadPrefixes): void {
        foreach ($autoloadPrefixes as $prefix => $relativeBasePaths) {
            if (!is_string($prefix) || $prefix === "" || !str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            $relativePath = str_replace("\\", DIRECTORY_SEPARATOR, $relativeClass) . ".php";
            $basePaths = is_array($relativeBasePaths) ? $relativeBasePaths : [$relativeBasePaths];

            foreach ($basePaths as $relativeBasePath) {
                if (!is_string($relativeBasePath) || $relativeBasePath === "") {
                    continue;
                }

                $normalizedBasePath = trim(str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $relativeBasePath), DIRECTORY_SEPARATOR);
                $absolutePath = APP_ROOT . DIRECTORY_SEPARATOR . $normalizedBasePath . DIRECTORY_SEPARATOR . $relativePath;

                if (is_file($absolutePath)) {
                    require $absolutePath;

                    return;
                }
            }
        }
    });
}

$engineRoot = defined("FNLLA_ENGINE_ROOT") ? FNLLA_ENGINE_ROOT : APP_ROOT;
require_once $engineRoot . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "Support" . DIRECTORY_SEPARATOR . "helpers.php";

Env::load(env_file_path());
$GLOBALS["fnlla_config"] = load_config_directory(base_path("config"));
$GLOBALS["fnlla_php_config"] = $GLOBALS["fnlla_config"];

date_default_timezone_set((string) config("app.timezone", "UTC"));
error_reporting(E_ALL);
ini_set("display_errors", app_debug() ? "1" : "0");
ini_set("display_startup_errors", app_debug() ? "1" : "0");
\Fnlla\Php\Exceptions\RuntimeErrorHandling::install();

$logPath = dirname(\Fnlla\Php\Support\Logger::configuredPath());
if (!is_dir($logPath)) {
    mkdir($logPath, 0700, true);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$container = new Container();
$GLOBALS["fnlla_container"] = $container;
$GLOBALS["fnlla_php_container"] = $container;
$resilienceEvents = new \Fnlla\Php\Resilience\ResilienceEvents();
$dependencyHealth = new \Fnlla\Php\Resilience\DependencyHealth($resilienceEvents, (array) config('resilience.dependencies', []));
$container->instance(\Fnlla\Php\Resilience\DependencyHealth::class, $dependencyHealth);
(new \Fnlla\Php\Resilience\ProviderBooter($container, $dependencyHealth))->boot(
    (array) config('app.providers', []), (array) config('resilience.providers', [])
);
if ($container->bound(\Fnlla\Php\Resilience\ResilienceEvents::class)) {
    $dependencyHealth->observeWith($container->make(\Fnlla\Php\Resilience\ResilienceEvents::class));
}

// Optional trusted capability extensions use identical registration in CLI and HTTP.
if ($container->has(\Fnlla\Php\Product\ProductModuleRegistry::class)) {
    $modules = $container->make(\Fnlla\Php\Product\ProductModuleRegistry::class);
    $modules->registerServices();
    $modules->registerActions();
}

return $container;
