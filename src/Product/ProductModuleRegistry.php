<?php

declare(strict_types=1);

namespace Fnlla\Php\Product;

use Closure;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Routing\Router;
use JsonException;
use RuntimeException;
use Throwable;

final class ProductModuleRegistry
{
    private ?string $productPath;
    /** @var list<string> */
    private array $modulePaths;
    /** @var array<string, ProductModuleExtensionInterface|class-string> */
    private array $extensionClasses;
    private string $statePath;
    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;
    /** @var array<string, ProductModuleExtensionInterface> */
    private array $extensions = [];
    private bool $servicesRegistered = false;
    private bool $routesRegistered = false;

    /**
     * @param list<string>|null $modulePaths
     * @param array<string, ProductModuleExtensionInterface|class-string>|null $extensionClasses
     */
    public function __construct(
        private Container $container,
        ?string $productPath = null,
        ?array $modulePaths = null,
        ?array $extensionClasses = null,
        ?string $statePath = null,
    ) {
        $configuredProduct = config("product_modules.product");
        $this->productPath = $productPath ?? (is_string($configuredProduct) && trim($configuredProduct) !== "" ? $configuredProduct : null);
        $configuredPaths = $modulePaths ?? config("product_modules.manifests", []);
        $configuredExtensions = $extensionClasses ?? config("product_modules.extensions", []);
        $configuredState = $statePath ?? config("product_modules.state_path", storage_path("framework/product-modules.json"));
        $this->modulePaths = is_array($configuredPaths)
            ? array_values(array_filter($configuredPaths, static fn (mixed $path): bool => is_string($path) && trim($path) !== ""))
            : [];
        $this->extensionClasses = is_array($configuredExtensions) ? $configuredExtensions : [];
        $this->statePath = is_string($configuredState) && trim($configuredState) !== ""
            ? $configuredState
            : storage_path("framework/product-modules.json");
    }

    /** @return array<string, mixed> */
    public function validationReport(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot["report"];
        }

        if ($this->productPath === null) {
            $report = $this->emptyReport();
            $this->snapshot = ["report" => $report, "product" => [], "modules" => [], "routes" => []];
            return $report;
        }

        $validator = new ProductValidator();
        $report = $validator->validateFiles($this->productPath, $this->modulePaths);
        $product = $this->decodeObject($this->productPath);
        $modules = [];
        foreach ($this->modulePaths as $path) {
            $module = $this->decodeObject($path);
            $id = $module["id"] ?? null;
            if (is_string($id) && $id !== "") {
                $modules[$id] = $module;
            }
        }
        ksort($modules, SORT_STRING);
        $routes = [];
        foreach ((array) ($product["routes"] ?? []) as $route) {
            if (is_array($route) && is_string($route["id"] ?? null)) {
                $routes[$route["id"]] = $route;
            }
        }

        foreach ((array) ($report["missing_module_manifests"] ?? []) as $moduleId) {
            $this->addError($report, "module_manifest_required", "coverage", "/modules/" . $moduleId, "Configured Product Module lifecycle requires a declaration for every product module.");
        }
        $this->validateRouteStatus($report, $modules, $routes);
        $this->validateExtensions($report, $modules);
        $this->validateState($report, $modules);
        $this->finalizeReport($report);

        $this->snapshot = ["report" => $report, "product" => $product, "modules" => $modules, "routes" => $routes];
        return $report;
    }

    /** @return list<array<string, mixed>> */
    public function modules(): array
    {
        $this->assertValid();
        $snapshot = $this->snapshot();
        $enabled = array_fill_keys($this->readState($snapshot["modules"]), true);
        $result = [];
        foreach ($this->topologicalOrder($snapshot["modules"]) as $id) {
            $module = $snapshot["modules"][$id];
            $result[] = [
                "id" => $id,
                "enabled" => isset($enabled[$id]),
                "default_enabled" => $module["default_enabled"],
                "depends_on" => $module["depends_on"],
                "services" => count($module["services"]),
                "routes" => count($module["routes"]),
                "assets" => count($module["assets"]),
            ];
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function inspect(string $id): array
    {
        $this->assertValid();
        $snapshot = $this->snapshot();
        $module = $snapshot["modules"][$id] ?? null;
        if (!is_array($module)) {
            throw new RuntimeException("Unknown Product Module: {$id}.");
        }
        $enabled = $this->readState($snapshot["modules"]);
        return [
            "schema" => "fnlla.product-module-inspection.v1",
            "id" => $id,
            "enabled" => in_array($id, $enabled, true),
            "depends_on" => $module["depends_on"],
            "active_dependents" => $this->activeDependents($id, $enabled, $snapshot["modules"]),
            "services" => $module["services"],
            "routes" => $module["routes"],
            "assets" => $module["assets"],
            "disable_is_uninstall" => false,
            "data_behavior" => "preserve",
        ];
    }

    /** @return array<string, mixed> */
    public function enable(string $id): array
    {
        return $this->mutateState(function (array $enabled, array $modules) use ($id): array {
            $this->requireModule($id, $modules);
            $before = $enabled;
            $set = array_fill_keys($enabled, true);
            $this->enableWithDependencies($id, $modules, $set);
            $after = $this->orderedEnabled(array_keys($set), $modules);
            return [$after, array_values(array_diff($after, $before))];
        }, "enabled");
    }

    /** @return array<string, mixed> */
    public function disable(string $id): array
    {
        return $this->mutateState(function (array $enabled, array $modules) use ($id): array {
            $this->requireModule($id, $modules);
            $dependents = $this->activeDependents($id, $enabled, $modules);
            if ($dependents !== []) {
                throw new RuntimeException("Cannot disable Product Module {$id}; active dependents: " . implode(", ", $dependents) . ".");
            }
            $after = array_values(array_diff($enabled, [$id]));
            return [$this->orderedEnabled($after, $modules), in_array($id, $enabled, true) ? [$id] : []];
        }, "disabled");
    }

    public function registerServices(): void
    {
        if ($this->servicesRegistered) {
            return;
        }
        $this->assertValid();
        $snapshot = $this->snapshot();
        $enabled = array_fill_keys($this->readState($snapshot["modules"]), true);
        $existing = [];
        foreach ($this->container->inspectBindings() as $binding) {
            $existing[$binding["abstract"]] = true;
        }
        $operations = [];
        foreach ($this->topologicalOrder($snapshot["modules"]) as $moduleId) {
            if (!isset($enabled[$moduleId])) {
                continue;
            }
            $module = $snapshot["modules"][$moduleId];
            $extension = $this->extensions[$moduleId] ?? null;
            $bindings = $extension?->serviceBindings() ?? [];
            foreach ($module["services"] as $service) {
                $abstract = $service["abstract"];
                if (isset($existing[$abstract])) {
                    throw new RuntimeException("Product Module service collision for {$abstract}.");
                }
                $existing[$abstract] = true;
                $operations[] = [$abstract, $bindings[$service["id"]], $service["lifetime"]];
            }
        }
        foreach ($operations as [$abstract, $concrete, $lifetime]) {
            if ($lifetime === "singleton") {
                $this->container->singleton($abstract, $concrete);
            } elseif ($lifetime === "scoped") {
                $this->container->scoped($abstract, $concrete);
            } else {
                $this->container->bind($abstract, $concrete);
            }
        }
        $this->servicesRegistered = true;
    }

    public function registerRoutes(Router $router): void
    {
        if ($this->routesRegistered) {
            return;
        }
        $this->assertValid();
        $snapshot = $this->snapshot();
        $enabled = array_fill_keys($this->readState($snapshot["modules"]), true);
        $routeKeys = [];
        $routeNames = [];
        foreach ($router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $definition = $route["definition"];
                $routeKeys[strtoupper((string) $method) . " " . $definition->path()] = true;
                if ($definition->routeName() !== null) {
                    $routeNames[$definition->routeName()] = true;
                }
            }
        }
        $operations = [];
        foreach ($this->topologicalOrder($snapshot["modules"]) as $moduleId) {
            if (!isset($enabled[$moduleId])) {
                continue;
            }
            $module = $snapshot["modules"][$moduleId];
            $handlers = ($this->extensions[$moduleId] ?? null)?->routeHandlers() ?? [];
            foreach ($module["routes"] as $declaration) {
                $productRoute = $snapshot["routes"][$declaration["id"]];
                $key = strtoupper($productRoute["method"]) . " " . $productRoute["path"];
                $name = $declaration["id"];
                if (isset($routeKeys[$key]) || isset($routeNames[$name])) {
                    throw new RuntimeException("Product Module route collision: {$key} ({$name}).");
                }
                $routeKeys[$key] = true;
                $routeNames[$name] = true;
                $operations[] = [$moduleId, $declaration, $productRoute, $handlers[$name]];
            }
        }
        foreach ($operations as [$moduleId, $declaration, $productRoute, $handler]) {
            $definition = $router->add($productRoute["method"], $productRoute["path"], $handler)
                ->setMetadata("product_module", $moduleId)
                ->setMetadata("product_module_route", $declaration["id"])
                ->setMetadata("privileged", $declaration["privileged"])
                ->middleware($declaration["middleware"])
                ->name($declaration["id"]);
            unset($definition);
        }
        $this->routesRegistered = true;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $this->validationReport();
        return $this->snapshot ?? throw new RuntimeException("Product Module registry did not load.");
    }

    private function assertValid(): void
    {
        $report = $this->validationReport();
        if (($report["valid"] ?? false) !== true) {
            $first = $report["errors"][0] ?? [];
            throw new RuntimeException("Product Module registry is invalid: " . ($first["id"] ?? "validation_failed") . " at " . ($first["path"] ?? "/") . ".");
        }
    }

    /** @return array<string, mixed> */
    private function emptyReport(): array
    {
        return [
            "schema" => ProductValidator::REPORT_SCHEMA,
            "valid" => true,
            "input_schema" => null,
            "product_id" => null,
            "runtime_evidence" => "not_evaluated",
            "summary" => ["errors" => 0, "warnings" => 1, "declared_modules" => 0, "module_manifests" => 0],
            "missing_module_manifests" => [],
            "errors" => [],
            "warnings" => [[
                "id" => "product_modules_not_configured",
                "category" => "configuration",
                "path" => "/product_modules/product",
                "message" => "No Product Specification is configured; the Product Module registry is empty.",
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $path): array
    {
        try {
            $decoded = json_decode((string) @file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /** @param array<string, mixed> $report
     *  @param array<string, array<string, mixed>> $modules
     *  @param array<string, array<string, mixed>> $routes
     */
    private function validateRouteStatus(array &$report, array $modules, array $routes): void
    {
        foreach ($modules as $moduleId => $module) {
            foreach ((array) ($module["routes"] ?? []) as $index => $declaration) {
                $routeId = is_array($declaration) ? ($declaration["id"] ?? null) : null;
                if (is_string($routeId) && isset($routes[$routeId]) && ($routes[$routeId]["status"] ?? null) !== "implemented") {
                    $this->addError($report, "route_not_implemented", "route", "/module_manifests/{$moduleId}/routes/{$index}/id", "A Product Module may register only a product route whose status is implemented.");
                }
            }
        }
    }

    /** @param array<string, mixed> $report
     *  @param array<string, array<string, mixed>> $modules
     */
    private function validateExtensions(array &$report, array $modules): void
    {
        foreach ($this->extensionClasses as $moduleId => $candidate) {
            if (!is_string($moduleId) || !isset($modules[$moduleId])) {
                $this->addError($report, "unknown_module_extension", "configuration", "/extensions/" . (string) $moduleId, "Extension configuration targets an unknown Product Module.");
                continue;
            }
            try {
                $extension = $candidate instanceof ProductModuleExtensionInterface ? $candidate : (is_string($candidate) && class_exists($candidate) ? new $candidate() : null);
            } catch (Throwable $exception) {
                $extension = null;
            }
            if (!$extension instanceof ProductModuleExtensionInterface) {
                $this->addError($report, "invalid_module_extension", "configuration", "/extensions/{$moduleId}", "Configured extension must implement ProductModuleExtensionInterface and have a constructor without required arguments.");
                continue;
            }
            $this->extensions[$moduleId] = $extension;
        }

        foreach ($modules as $moduleId => $module) {
            $declaredServices = $this->ids((array) ($module["services"] ?? []));
            $declaredRoutes = $this->ids((array) ($module["routes"] ?? []));
            $extension = $this->extensions[$moduleId] ?? null;
            if (($declaredServices !== [] || $declaredRoutes !== []) && $extension === null) {
                $this->addError($report, "module_extension_missing", "configuration", "/extensions/{$moduleId}", "A module declaring services or routes requires a trusted application extension.");
                continue;
            }
            if ($extension === null) {
                continue;
            }
            try {
                $bindings = $extension->serviceBindings();
                $handlers = $extension->routeHandlers();
            } catch (Throwable) {
                $this->addError($report, "module_extension_failed", "configuration", "/extensions/{$moduleId}", "Module extension declarations could not be read.");
                continue;
            }
            $this->validateExtensionKeys($report, $moduleId, "services", $declaredServices, $bindings);
            $this->validateExtensionKeys($report, $moduleId, "routes", $declaredRoutes, $handlers);
            $serviceDeclarations = [];
            foreach ((array) ($module["services"] ?? []) as $service) {
                if (is_array($service) && is_string($service["id"] ?? null)) {
                    $serviceDeclarations[$service["id"]] = $service;
                }
            }
            foreach ($declaredServices as $serviceId) {
                $implementation = $bindings[$serviceId] ?? null;
                if (!$implementation instanceof Closure && (!is_string($implementation) || trim($implementation) === "")) {
                    $this->addError($report, "invalid_service_binding", "service", "/extensions/{$moduleId}/services/{$serviceId}", "Service implementation must be a class name or Closure from trusted application configuration.");
                } elseif (is_string($implementation) && !class_exists($implementation)) {
                    $this->addError($report, "missing_service_implementation", "service", "/extensions/{$moduleId}/services/{$serviceId}", "Configured service implementation class does not exist.");
                } elseif (is_string($implementation)) {
                    $abstract = $serviceDeclarations[$serviceId]["abstract"] ?? null;
                    if (is_string($abstract) && (class_exists($abstract) || interface_exists($abstract)) && !is_a($implementation, $abstract, true)) {
                        $this->addError($report, "incompatible_service_implementation", "service", "/extensions/{$moduleId}/services/{$serviceId}", "Configured service implementation does not satisfy its declared abstract.");
                    }
                }
            }
            foreach ($declaredRoutes as $routeId) {
                $handler = $handlers[$routeId] ?? null;
                if (!is_array($handler) || count($handler) !== 2 || !is_string($handler[0] ?? null) || !is_string($handler[1] ?? null)) {
                    $this->addError($report, "invalid_route_handler", "route", "/extensions/{$moduleId}/routes/{$routeId}", "Route handler must be a cache-safe class and method pair.");
                } elseif (!class_exists($handler[0]) || !method_exists($handler[0], $handler[1])) {
                    $this->addError($report, "missing_route_handler", "route", "/extensions/{$moduleId}/routes/{$routeId}", "Configured route handler class or method does not exist.");
                }
            }
        }
    }

    /** @param array<string, mixed> $report
     *  @param list<string> $declared
     *  @param array<mixed> $provided
     */
    private function validateExtensionKeys(array &$report, string $moduleId, string $kind, array $declared, array $provided): void
    {
        $providedIds = array_values(array_filter(array_keys($provided), "is_string"));
        sort($providedIds, SORT_STRING);
        foreach (array_diff($declared, $providedIds) as $missing) {
            $this->addError($report, "module_extension_entry_missing", "configuration", "/extensions/{$moduleId}/{$kind}/{$missing}", "The manifest declaration has no extension implementation.");
        }
        foreach (array_diff($providedIds, $declared) as $extra) {
            $this->addError($report, "undeclared_module_extension_entry", "configuration", "/extensions/{$moduleId}/{$kind}/{$extra}", "The extension may not register an entry absent from the manifest.");
        }
    }

    /** @param list<array<string, mixed>> $items
     *  @return list<string>
     */
    private function ids(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item["id"] ?? null)) {
                $ids[] = $item["id"];
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @param array<string, mixed> $report
     *  @param array<string, array<string, mixed>> $modules
     */
    private function validateState(array &$report, array $modules): void
    {
        try {
            $enabled = $this->readState($modules);
        } catch (RuntimeException $exception) {
            $this->addError($report, "invalid_module_state", "state", "/state", $exception->getMessage());
            return;
        }
        $set = array_fill_keys($enabled, true);
        foreach ($enabled as $moduleId) {
            foreach ((array) ($modules[$moduleId]["depends_on"] ?? []) as $dependency) {
                if (!isset($set[$dependency])) {
                    $this->addError($report, "disabled_dependency", "dependency", "/state/enabled/{$moduleId}", "Enabled module {$moduleId} requires enabled dependency {$dependency}.");
                }
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function addError(array &$report, string $id, string $category, string $path, string $message): void
    {
        $report["errors"][] = compact("id", "category", "path", "message");
    }

    /** @param array<string, mixed> $report */
    private function finalizeReport(array &$report): void
    {
        usort($report["errors"], static fn (array $left, array $right): int => [$left["path"], $left["id"]] <=> [$right["path"], $right["id"]]);
        $report["summary"]["errors"] = count($report["errors"]);
        $report["valid"] = $report["errors"] === [];
    }

    /** @param array<string, array<string, mixed>> $modules
     *  @return list<string>
     */
    private function readState(array $modules): array
    {
        if (!is_file($this->statePath)) {
            $set = [];
            foreach ($modules as $id => $module) {
                if (($module["default_enabled"] ?? false) === true) {
                    $this->enableWithDependencies($id, $modules, $set);
                }
            }
            return $this->orderedEnabled(array_keys($set), $modules);
        }
        try {
            $state = json_decode((string) file_get_contents($this->statePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Product Module state is not valid JSON.", 0, $exception);
        }
        if (!is_array($state) || array_is_list($state) || ($state["schema"] ?? null) !== "fnlla.product-module-state.v1") {
            throw new RuntimeException("Product Module state schema is invalid.");
        }
        $enabled = $state["enabled"] ?? null;
        if (!is_array($enabled) || !array_is_list($enabled)) {
            throw new RuntimeException("Product Module state enabled value must be an array.");
        }
        $result = [];
        foreach ($enabled as $id) {
            if (!is_string($id) || !isset($modules[$id])) {
                throw new RuntimeException("Product Module state contains an unknown module.");
            }
            if (in_array($id, $result, true)) {
                throw new RuntimeException("Product Module state contains a duplicate module.");
            }
            $result[] = $id;
        }
        return $this->orderedEnabled($result, $modules);
    }

    /** @param callable(list<string>, array<string, array<string, mixed>>):array{0:list<string>,1:list<string>} $mutation
     *  @return array<string, mixed>
     */
    private function mutateState(callable $mutation, string $operation): array
    {
        $this->assertValid();
        $snapshot = $this->snapshot();
        $directory = dirname($this->statePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create Product Module state directory.");
        }
        $lockPath = $this->statePath . ".lock";
        $lock = fopen($lockPath, "c+");
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException("Unable to lock Product Module state.");
        }
        try {
            $enabled = $this->readState($snapshot["modules"]);
            [$after, $changed] = $mutation($enabled, $snapshot["modules"]);
            $payload = json_encode([
                "schema" => "fnlla.product-module-state.v1",
                "enabled" => $after,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            $temporary = $this->statePath . ".tmp-" . bin2hex(random_bytes(6));
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                throw new RuntimeException("Unable to write Product Module state staging file.");
            }
            if (PHP_OS_FAMILY === "Windows" && is_file($this->statePath) && !unlink($this->statePath)) {
                @unlink($temporary);
                throw new RuntimeException("Unable to replace Product Module state.");
            }
            if (!rename($temporary, $this->statePath)) {
                @unlink($temporary);
                throw new RuntimeException("Unable to publish Product Module state.");
            }
            $routeCache = function_exists("framework_route_cache_path") ? framework_route_cache_path() : "";
            if ($routeCache !== "" && dirname($routeCache) === $directory && is_file($routeCache)) {
                unlink($routeCache);
            }
            $this->snapshot = null;
            $this->servicesRegistered = false;
            $this->routesRegistered = false;
            return [
                "schema" => "fnlla.product-module-transition.v1",
                "operation" => $operation,
                "changed" => $changed,
                "enabled" => $after,
                "data_behavior" => "preserve",
                "uninstall" => false,
                "effective_on" => "next_bootstrap",
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, array<string, mixed>> $modules
     *  @param array<string, true> $set
     */
    private function enableWithDependencies(string $id, array $modules, array &$set): void
    {
        $visiting = [];
        $this->collectEnabled($id, $modules, $set, $visiting);
    }

    /** @param array<string, array<string, mixed>> $modules
     *  @param array<string, true> $set
     *  @param array<string, true> $visiting
     */
    private function collectEnabled(string $id, array $modules, array &$set, array &$visiting): void
    {
        $this->requireModule($id, $modules);
        if (isset($set[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            throw new RuntimeException("Product Module dependency cycle includes {$id}.");
        }
        $visiting[$id] = true;
        foreach ((array) $modules[$id]["depends_on"] as $dependency) {
            $this->collectEnabled($dependency, $modules, $set, $visiting);
        }
        unset($visiting[$id]);
        $set[$id] = true;
    }

    /** @param array<string, array<string, mixed>> $modules */
    private function requireModule(string $id, array $modules): void
    {
        if (!isset($modules[$id])) {
            throw new RuntimeException("Unknown Product Module: {$id}.");
        }
    }

    /** @param array<string, array<string, mixed>> $modules
     *  @return list<string>
     */
    private function topologicalOrder(array $modules): array
    {
        $order = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$order, &$visited, $modules): void {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            $dependencies = (array) ($modules[$id]["depends_on"] ?? []);
            sort($dependencies, SORT_STRING);
            foreach ($dependencies as $dependency) {
                if (isset($modules[$dependency])) {
                    $visit($dependency);
                }
            }
            $order[] = $id;
        };
        $ids = array_keys($modules);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $visit($id);
        }
        return $order;
    }

    /** @param list<string> $enabled
     *  @param array<string, array<string, mixed>> $modules
     *  @return list<string>
     */
    private function orderedEnabled(array $enabled, array $modules): array
    {
        $set = array_fill_keys($enabled, true);
        return array_values(array_filter($this->topologicalOrder($modules), static fn (string $id): bool => isset($set[$id])));
    }

    /** @param list<string> $enabled
     *  @param array<string, array<string, mixed>> $modules
     *  @return list<string>
     */
    private function activeDependents(string $id, array $enabled, array $modules): array
    {
        $enabledSet = array_fill_keys($enabled, true);
        $dependents = [];
        foreach ($enabled as $candidate) {
            if ($candidate === $id) {
                continue;
            }
            $seen = [];
            $stack = (array) ($modules[$candidate]["depends_on"] ?? []);
            while ($stack !== []) {
                $dependency = array_pop($stack);
                if (!is_string($dependency) || isset($seen[$dependency])) {
                    continue;
                }
                $seen[$dependency] = true;
                if ($dependency === $id) {
                    $dependents[] = $candidate;
                    break;
                }
                if (isset($enabledSet[$dependency])) {
                    array_push($stack, ...(array) ($modules[$dependency]["depends_on"] ?? []));
                }
            }
        }
        sort($dependents, SORT_STRING);
        return $dependents;
    }
}
