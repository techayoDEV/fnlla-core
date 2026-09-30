<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use Closure;
use Fnlla\Php\Routing\RouteDefinition;
use Fnlla\Php\Routing\Router;
use ReflectionFunction;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class RuntimeSourceMap
{
    /** @return array<string, mixed> */
    public function report(?Router $router = null): array
    {
        $engine = dirname(__DIR__, 2);
        $routes = [];
        foreach ($router?->getRoutes() ?? [] as $items) {
            foreach ($items as $item) {
                $definition = $item["definition"] ?? null;
                if (!$definition instanceof RouteDefinition) { continue; }
                $handler = $definition->handler();
                $source = null;
                $target = "callable";
                try {
                    if (is_array($handler)) {
                        $class = is_string($handler[0]) ? $handler[0] : get_class($handler[0]);
                        $target = $class . "::" . $handler[1];
                        $reflection = new ReflectionMethod($class, $handler[1]);
                    } elseif ($handler instanceof Closure) {
                        $target = "closure";
                        $reflection = new ReflectionFunction($handler);
                    } elseif (is_string($handler)) {
                        $target = $handler;
                        $reflection = new ReflectionFunction($handler);
                    } else {
                        $target = get_debug_type($handler) . "::__invoke";
                        $reflection = new ReflectionMethod($handler, "__invoke");
                    }
                    $source = $this->source($reflection->getFileName(), $reflection->getStartLine());
                } catch (Throwable) {
                    // Declared handlers may not be loadable yet; do not instantiate them.
                }
                $middleware = [];
                foreach ($definition->middlewareStack() as $entry) {
                    $middleware[] = is_string($entry) ? explode(":", $entry, 2)[0] : get_debug_type($entry);
                }
                $routes[] = [
                    "method" => $definition->method(),
                    "path" => $definition->path(),
                    "name" => $definition->routeName(),
                    "handler" => explode("\0", $target, 2)[0],
                    "source" => $source,
                    "middleware" => $middleware,
                    "authorization" => is_string($definition->metadata("authorize_ability"))
                        ? $definition->metadata("authorize_ability") : null,
                    "openapi_declared" => is_array($definition->metadata("openapi")),
                ];
            }
        }
        usort($routes, static fn (array $a, array $b): int => [$a["path"], $a["method"]] <=> [$b["path"], $b["method"]]);

        $packages = [];
        $lock = $this->json(base_path("composer.lock"));
        foreach (["packages", "packages-dev"] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                if (is_array($package) && is_string($package["name"] ?? null) && is_string($package["version"] ?? null)) {
                    $packages[] = ["name" => $package["name"], "version" => $package["version"], "source" => "composer.lock",
                        "scope" => $section === "packages" ? "runtime" : "development", "installed_verified" => false];
                }
            }
        }
        usort($packages, static fn (array $a, array $b): int => $a["name"] <=> $b["name"]);
        $coreVersion = is_file($engine . "/VERSION") ? trim((string) file_get_contents($engine . "/VERSION")) : "unknown";
        $installed = "Composer\\InstalledVersions";
        if (class_exists($installed) && $installed::isInstalled("techayodev/fnlla-core")) {
            $coreVersion = $installed::getPrettyVersion("techayodev/fnlla-core") ?? $coreVersion;
        }

        $contracts = [];
        foreach (["product-specification", "security", "events"] as $directory) {
            foreach (glob($engine . "/resources/" . $directory . "/*.schema.json") ?: [] as $path) {
                $schema = $this->json($path);
                $contracts[] = ["id" => $schema['$id'] ?? basename($path),
                    "source" => "resources/" . $directory . "/" . basename($path),
                    "owner" => "techayodev/fnlla-core", "sha256" => hash_file("sha256", $path)];
            }
        }
        usort($contracts, static fn (array $a, array $b): int => $a["source"] <=> $b["source"]);
        $configFiles = [];
        foreach (glob(base_path("config/*.php")) ?: [] as $path) {
            $configFiles[] = "config/" . basename($path);
        }
        sort($configFiles);
        $checks = [];
        foreach (["scripts/test.php", "scripts/lint.php", "scripts/static-analysis.php"] as $path) {
            if (is_file(base_path($path))) { $checks[] = "php " . $path; }
        }
        $map = [
            "schema" => "fnlla.runtime.context.v1",
            "engine" => ["package" => "techayodev/fnlla-core", "version" => $coreVersion,
                "source" => class_exists($installed) && $installed::isInstalled("techayodev/fnlla-core") ? "composer_installed" : "engine_VERSION"],
            "packages" => $packages,
            "routes" => ["status" => $router === null ? "not_loaded" : "registered",
                "items" => $routes, "global_middleware" => "not_inferred", "executed" => false],
            "contracts" => $contracts,
            "interfaces" => $this->interfaces(),
            "configuration" => [
                "mode" => $GLOBALS["fnlla_config_source"] ?? "unknown",
                "declared_files" => $configFiles,
                "runtime_override_count" => (int) ($GLOBALS["fnlla_config_override_count"] ?? 0),
                "values_exported" => false, "environment_exported" => false,
                "cache_present" => is_file(framework_config_cache_path()),
            ],
            "checks" => $checks,
            "guidance" => array_values(array_filter(["AGENTS.md", "CLAUDE.md", ".github/copilot-instructions.md"],
                static fn (string $path): bool => is_file(base_path($path)))),
            "limitations" => [
                "Registered declarations do not prove authorization or business behavior.",
                "Lock versions are declared dependencies, not proof of installed package integrity.",
                "Application bootstrap and route registration are trusted application code.",
                "No environment values, credentials, customer rows or job payloads are exported.",
            ],
        ];
        $map["fingerprint"] = hash("sha256", json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $map;
    }

    /** @return array<string, mixed>|null */
    private function source(string|false $file, int|false $line): ?array
    {
        if ($file === false) { return null; }
        $actual = realpath($file);
        if ($actual === false || is_link($file) || !is_readable($actual)) { return null; }
        $actual = str_replace("\\", "/", $actual);
        foreach (["core" => dirname(__DIR__, 2), "application" => base_path()] as $owner => $root) {
            $prefix = rtrim(str_replace("\\", "/", (string) realpath($root)), "/") . "/";
            if (str_starts_with($actual, $prefix)) {
                $relative = substr($actual, strlen($prefix));
                if (preg_match('~^(?:vendor|packages)/~i', $relative) === 1) {
                    return ["owner" => "external_dependency", "path" => null, "line" => $line ?: null];
                }
                return ["owner" => $owner, "path" => $relative, "line" => $line ?: null,
                    "sha256" => hash_file("sha256", $actual)];
            }
        }
        return ["owner" => "external_dependency", "path" => null, "line" => $line ?: null];
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > 8 * 1024 * 1024) { return []; }
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (Throwable) { return []; }
    }

    /** @return list<array<string, mixed>> */
    private function interfaces(): array
    {
        $interfaces = [
            \Fnlla\Php\Cache\CacheStoreInterface::class, \Fnlla\Php\Cache\RateLimitStoreInterface::class,
            \Fnlla\Php\Queue\QueueStoreInterface::class, \Fnlla\Php\Queue\ReliableQueueStoreInterface::class,
            \Fnlla\Php\Actions\ActionStoreInterface::class, \Fnlla\Php\Actions\ReliableOutboxStoreInterface::class,
            \Fnlla\Php\Audit\AuditLoggerInterface::class, \Fnlla\Php\Tenancy\TenantIdentityResolverInterface::class,
        ];
        sort($interfaces);
        $result = [];
        foreach ($interfaces as $interface) {
            $reflection = new ReflectionClass($interface);
            $methods = [];
            foreach ($reflection->getMethods() as $method) {
                $parameters = [];
                foreach ($method->getParameters() as $parameter) {
                    $parameters[] = ["name" => $parameter->getName(), "type" => (string) $parameter->getType(),
                        "optional" => $parameter->isOptional(), "variadic" => $parameter->isVariadic()];
                }
                $methods[] = ["name" => $method->getName(), "parameters" => $parameters, "return" => (string) $method->getReturnType()];
            }
            usort($methods, static fn (array $a, array $b): int => $a["name"] <=> $b["name"]);
            $result[] = ["name" => $interface, "source" => $this->source($reflection->getFileName(), $reflection->getStartLine()),
                "methods" => $methods];
        }
        return $result;
    }
}
