<?php

declare(strict_types=1);

namespace Fnlla\Php\Product;

use JsonException;

final class ProductValidator
{
    public const PRODUCT_SCHEMA = "fnlla.product.v1";
    public const MODULE_SCHEMA = "fnlla.module.v1";
    public const REPORT_SCHEMA = "fnlla.product-validation-report.v1";

    /** @var list<string> */
    private const COLLECTIONS = [
        "modules", "entities", "relations", "roles", "permissions", "actions",
        "events", "workflows", "surfaces", "routes", "capabilities", "proposals",
    ];

    /** @var list<string> */
    private const CAPABILITIES = [
        "tenancy.row-scope",
        "audit.append-only",
        "search.exact-match",
        "seo.metadata",
        "ai.semantic-search",
    ];

    /** @var list<array{id:string,category:string,path:string,message:string}> */
    private array $errors = [];

    /** @var list<array{id:string,category:string,path:string,message:string}> */
    private array $warnings = [];

    /**
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $moduleDeclarations
     * @return array<string, mixed>
     */
    public function validate(array $product, array $moduleDeclarations = []): array
    {
        $this->errors = [];
        $this->warnings = [];

        $inputSchema = is_string($product["schema"] ?? null) ? $product["schema"] : null;
        $productId = is_array($product["product"] ?? null) && is_string($product["product"]["id"] ?? null)
            ? $product["product"]["id"]
            : null;

        if ($inputSchema === "fnlla.business_app_blueprint.v1") {
            $this->error(
                "migration_required",
                "migration",
                "/schema",
                "Historical fnlla.business_app_blueprint.v1 input requires the documented explicit migration to fnlla.product.v1.",
            );
            return $this->report($inputSchema, $productId, [], []);
        }
        if ($inputSchema !== self::PRODUCT_SCHEMA) {
            $this->error("unknown_schema", "schema", "/schema", "Supported product schema: " . self::PRODUCT_SCHEMA . ".");
            return $this->report($inputSchema, $productId, [], []);
        }

        $version = $product["specification_version"] ?? null;
        if (!is_string($version)) {
            $this->error("invalid_type", "type", "/specification_version", "Expected a string.");
        } elseif (preg_match('/^1\.[0-9]+\.[0-9]+$/D', $version) !== 1) {
            $this->error("unsupported_version", "schema", "/specification_version", "Supported Product Specification major version: 1.");
        }
        if (!is_array($product["product"] ?? null)) {
            $this->error("invalid_type", "type", "/product", "Expected an object.");
        } elseif (!is_string($product["product"]["id"] ?? null) || trim($product["product"]["id"]) === "") {
            $this->error("invalid_type", "type", "/product/id", "Expected a non-empty string identifier.");
        }
        if (!is_array($product["requirements"] ?? null)) {
            $this->error("invalid_type", "type", "/requirements", "Expected an object.");
        }

        $collections = [];
        foreach (self::COLLECTIONS as $name) {
            $collections[$name] = $this->collection($product, $name);
        }

        $ids = [];
        foreach ($collections as $name => $items) {
            $ids[$name] = $this->identifierMap($items, "/" . $name);
        }

        foreach ($collections["entities"] as $index => $entity) {
            $this->reference($entity["module"] ?? null, $ids["modules"], "/entities/{$index}/module");
            if (!is_bool($entity["tenant_scoped"] ?? null)) {
                $this->error("invalid_type", "type", "/entities/{$index}/tenant_scoped", "Expected a boolean.");
            }
        }
        foreach ($collections["relations"] as $index => $relation) {
            $this->reference($relation["from_entity"] ?? null, $ids["entities"], "/relations/{$index}/from_entity");
            $this->reference($relation["to_entity"] ?? null, $ids["entities"], "/relations/{$index}/to_entity");
        }
        foreach ($collections["roles"] as $index => $role) {
            foreach ($this->stringList($role, "permissions", "/roles/{$index}/permissions") as $permissionIndex => $permission) {
                $this->reference($permission, $ids["permissions"], "/roles/{$index}/permissions/{$permissionIndex}");
            }
        }
        foreach ($collections["actions"] as $index => $action) {
            $this->reference($action["module"] ?? null, $ids["modules"], "/actions/{$index}/module");
            $this->reference($action["entity"] ?? null, $ids["entities"], "/actions/{$index}/entity");
            foreach ($this->stringList($action, "permissions", "/actions/{$index}/permissions") as $permissionIndex => $permission) {
                $this->reference($permission, $ids["permissions"], "/actions/{$index}/permissions/{$permissionIndex}");
            }
            foreach ($this->stringList($action, "emits_events", "/actions/{$index}/emits_events") as $eventIndex => $event) {
                $this->reference($event, $ids["events"], "/actions/{$index}/emits_events/{$eventIndex}");
            }
        }
        foreach ($collections["events"] as $index => $event) {
            $this->reference($event["entity"] ?? null, $ids["entities"], "/events/{$index}/entity");
        }
        foreach ($collections["routes"] as $index => $route) {
            $this->reference($route["surface"] ?? null, $ids["surfaces"], "/routes/{$index}/surface");
            $this->reference($route["action"] ?? null, $ids["actions"], "/routes/{$index}/action");
            if (!in_array($route["status"] ?? null, ["proposed", "implemented", "retired"], true)) {
                $this->error("invalid_value", "route", "/routes/{$index}/status", "Expected proposed, implemented or retired.");
            }
        }
        $this->validateWorkflows($collections["workflows"], $ids["entities"], $ids["actions"]);

        foreach ($collections["capabilities"] as $index => $capability) {
            if (!in_array($capability["id"] ?? null, self::CAPABILITIES, true)) {
                $this->error("unsupported_capability", "capability", "/capabilities/{$index}/id", "Capability is not supported by fnlla.product.v1.");
            }
        }

        $sortedModules = $moduleDeclarations;
        usort($sortedModules, static function (array $left, array $right): int {
            $leftKey = (string) ($left["id"] ?? "") . "\0" . json_encode($left, JSON_UNESCAPED_SLASHES);
            $rightKey = (string) ($right["id"] ?? "") . "\0" . json_encode($right, JSON_UNESCAPED_SLASHES);
            return strcmp($leftKey, $rightKey);
        });
        $providedModules = $this->validateModules($sortedModules, $productId, $ids, $collections);
        $declaredModules = array_keys($ids["modules"]);
        sort($declaredModules, SORT_STRING);
        $missingModules = array_values(array_diff($declaredModules, array_keys($providedModules)));
        sort($missingModules, SORT_STRING);
        foreach ($missingModules as $moduleId) {
            $this->warning(
                "module_manifest_missing",
                "coverage",
                "/modules/" . $moduleId,
                "The product declares this module but no fnlla.module.v1 declaration was supplied; runtime implementation remains unverified.",
            );
        }

        return $this->report($inputSchema, $productId, $declaredModules, $missingModules, count($sortedModules));
    }

    /** @param list<string> $modulePaths
     *  @return array<string, mixed>
     */
    public function validateFiles(string $productPath, array $modulePaths = []): array
    {
        $this->errors = [];
        $this->warnings = [];
        $product = $this->readJsonObject($productPath, "/");
        if ($product === null) {
            return $this->report(null, null, [], []);
        }

        $modules = [];
        foreach ($modulePaths as $index => $modulePath) {
            $module = $this->readJsonObject($modulePath, "/module_files/{$index}");
            if ($module !== null) {
                $modules[] = $module;
            }
        }
        $fileErrors = $this->errors;
        $report = $this->validate($product, $modules);
        if ($fileErrors !== []) {
            $report["errors"] = [...$fileErrors, ...$report["errors"]];
            $this->sortFindings($report["errors"]);
            $report["summary"]["errors"] = count($report["errors"]);
            $report["valid"] = false;
        }
        return $report;
    }

    /** @param list<array<string, mixed>> $workflows
     *  @param array<string, true> $entities
     *  @param array<string, true> $actions
     */
    private function validateWorkflows(array $workflows, array $entities, array $actions): void
    {
        foreach ($workflows as $workflowIndex => $workflow) {
            $this->reference($workflow["entity"] ?? null, $entities, "/workflows/{$workflowIndex}/entity");
            $states = $this->stringList($workflow, "states", "/workflows/{$workflowIndex}/states");
            $stateMap = $this->stringValueMap($states, "/workflows/{$workflowIndex}/states");
            $this->transitionState($workflow["initial_state"] ?? null, $stateMap, "/workflows/{$workflowIndex}/initial_state");
            $transitions = $workflow["transitions"] ?? null;
            if (!is_array($transitions) || !array_is_list($transitions)) {
                $this->error("invalid_type", "type", "/workflows/{$workflowIndex}/transitions", "Expected an array.");
                continue;
            }
            $transitionIds = [];
            foreach ($transitions as $transitionIndex => $transition) {
                $path = "/workflows/{$workflowIndex}/transitions/{$transitionIndex}";
                if (!is_array($transition)) {
                    $this->error("invalid_type", "type", $path, "Expected an object.");
                    continue;
                }
                $id = $transition["id"] ?? null;
                if (!is_string($id)) {
                    $this->error("invalid_type", "type", $path . "/id", "Expected a string identifier.");
                } elseif (isset($transitionIds[$id])) {
                    $this->error("duplicate_id", "identity", $path . "/id", "Duplicate workflow transition identifier: {$id}.");
                } else {
                    $transitionIds[$id] = true;
                }
                $this->transitionState($transition["from"] ?? null, $stateMap, $path . "/from");
                $this->transitionState($transition["to"] ?? null, $stateMap, $path . "/to");
                $this->reference($transition["action"] ?? null, $actions, $path . "/action");
            }
        }
    }

    /** @param list<array<string, mixed>> $modules
     *  @param array<string, array<string, true>> $ids
     *  @param array<string, list<array<string, mixed>>> $collections
     *  @return array<string, true>
     */
    private function validateModules(array $modules, ?string $productId, array $ids, array $collections): array
    {
        $provided = [];
        $dependencies = [];
        $paths = [];
        $entitiesById = $this->itemsById($collections["entities"]);
        $actionsById = $this->itemsById($collections["actions"]);
        $routesById = $this->itemsById($collections["routes"]);
        $serviceIds = [];
        $serviceAbstracts = [];
        $moduleRouteIds = [];
        $assetIds = [];
        $assetTargets = [];

        foreach ($modules as $index => $module) {
            $path = "/module_manifests/{$index}";
            $this->allowedKeys($module, [
                "schema", "version", "id", "product_id", "description", "default_enabled",
                "depends_on", "entities", "actions", "events", "capabilities", "services", "routes", "assets",
            ], $path);
            if (($module["schema"] ?? null) !== self::MODULE_SCHEMA) {
                $this->error("unknown_module_schema", "schema", $path . "/schema", "Supported module schema: " . self::MODULE_SCHEMA . ".");
                continue;
            }
            $version = $module["version"] ?? null;
            if (!is_string($version) || preg_match('/^1\.[0-9]+\.[0-9]+$/D', $version) !== 1) {
                $this->error("unsupported_module_version", "schema", $path . "/version", "Supported module declaration major version: 1.");
            }
            $id = $module["id"] ?? null;
            if (!is_string($id) || $id === "") {
                $this->error("invalid_type", "type", $path . "/id", "Expected a non-empty string identifier.");
                continue;
            }
            if (isset($provided[$id])) {
                $this->error("duplicate_module", "identity", $path . "/id", "Duplicate module declaration: {$id}.");
            }
            $provided[$id] = true;
            $paths[$id] = $path;
            $this->reference($id, $ids["modules"], $path . "/id");
            if (($module["product_id"] ?? null) !== $productId) {
                $this->error("product_mismatch", "reference", $path . "/product_id", "Module declaration does not target the validated product.");
            }
            if (!is_bool($module["default_enabled"] ?? null)) {
                $this->error("invalid_type", "type", $path . "/default_enabled", "Expected a boolean.");
            }

            $dependencies[$id] = $this->stringList($module, "depends_on", $path . "/depends_on");
            foreach ($dependencies[$id] as $dependencyIndex => $dependency) {
                $this->reference($dependency, $ids["modules"], $path . "/depends_on/{$dependencyIndex}");
            }
            foreach (["entities", "actions", "events", "capabilities"] as $collection) {
                $values = $this->stringList($module, $collection, $path . "/" . $collection);
                foreach ($values as $valueIndex => $value) {
                    $this->reference($value, $ids[$collection], $path . "/{$collection}/{$valueIndex}");
                    if ($collection === "entities" && isset($entitiesById[$value]) && ($entitiesById[$value]["module"] ?? null) !== $id) {
                        $this->error("module_ownership_mismatch", "reference", $path . "/entities/{$valueIndex}", "Entity is assigned to a different product module.");
                    }
                    if ($collection === "actions" && isset($actionsById[$value]) && ($actionsById[$value]["module"] ?? null) !== $id) {
                        $this->error("module_ownership_mismatch", "reference", $path . "/actions/{$valueIndex}", "Action is assigned to a different product module.");
                    }
                }
            }

            foreach ($this->objectList($module, "services", $path . "/services") as $serviceIndex => $service) {
                $servicePath = $path . "/services/{$serviceIndex}";
                $this->allowedKeys($service, ["id", "abstract", "lifetime"], $servicePath);
                $serviceId = $service["id"] ?? null;
                if (!is_string($serviceId) || $serviceId === "") {
                    $this->error("invalid_type", "type", $servicePath . "/id", "Expected a non-empty service identifier.");
                } elseif (isset($serviceIds[$serviceId])) {
                    $this->error("duplicate_service", "identity", $servicePath . "/id", "Duplicate module service identifier: {$serviceId}.");
                } else {
                    $serviceIds[$serviceId] = true;
                }
                $abstract = $service["abstract"] ?? null;
                if (!is_string($abstract) || trim($abstract) === "") {
                    $this->error("invalid_type", "type", $servicePath . "/abstract", "Expected a non-empty service abstract.");
                } elseif (isset($serviceAbstracts[$abstract])) {
                    $this->error("service_collision", "collision", $servicePath . "/abstract", "Multiple modules declare the same service abstract: {$abstract}.");
                } else {
                    $serviceAbstracts[$abstract] = true;
                }
                if (!in_array($service["lifetime"] ?? null, ["transient", "singleton", "scoped"], true)) {
                    $this->error("invalid_service_lifetime", "service", $servicePath . "/lifetime", "Expected transient, singleton or scoped.");
                }
            }

            foreach ($this->objectList($module, "routes", $path . "/routes") as $routeIndex => $route) {
                $routePath = $path . "/routes/{$routeIndex}";
                $this->allowedKeys($route, ["id", "middleware", "privileged"], $routePath);
                $routeId = $route["id"] ?? null;
                if (!is_string($routeId) || $routeId === "") {
                    $this->error("invalid_type", "type", $routePath . "/id", "Expected a non-empty route identifier.");
                } else {
                    $this->reference($routeId, $ids["routes"], $routePath . "/id");
                    if (isset($moduleRouteIds[$routeId])) {
                        $this->error("duplicate_module_route", "collision", $routePath . "/id", "Multiple modules claim the same product route: {$routeId}.");
                    } else {
                        $moduleRouteIds[$routeId] = true;
                    }
                    $actionId = $routesById[$routeId]["action"] ?? null;
                    if (is_string($actionId) && isset($actionsById[$actionId]) && ($actionsById[$actionId]["module"] ?? null) !== $id) {
                        $this->error("module_ownership_mismatch", "reference", $routePath . "/id", "Route Action is assigned to a different product module.");
                    }
                }
                $middleware = $this->stringList($route, "middleware", $routePath . "/middleware");
                if (!is_bool($route["privileged"] ?? null)) {
                    $this->error("invalid_type", "type", $routePath . "/privileged", "Expected a boolean.");
                } elseif ($route["privileged"] === true && array_intersect($middleware, ["auth", "authorize"]) === []) {
                    $this->error("privileged_route_unprotected", "security", $routePath . "/middleware", "A privileged route must declare auth or authorize middleware.");
                }
            }

            foreach ($this->objectList($module, "assets", $path . "/assets") as $assetIndex => $asset) {
                $assetPath = $path . "/assets/{$assetIndex}";
                $this->allowedKeys($asset, ["id", "target", "publication", "disable_behavior", "removal"], $assetPath);
                $assetId = $asset["id"] ?? null;
                if (!is_string($assetId) || $assetId === "") {
                    $this->error("invalid_type", "type", $assetPath . "/id", "Expected a non-empty asset identifier.");
                } elseif (isset($assetIds[$assetId])) {
                    $this->error("duplicate_asset", "identity", $assetPath . "/id", "Duplicate module asset identifier: {$assetId}.");
                } else {
                    $assetIds[$assetId] = true;
                }
                $target = $asset["target"] ?? null;
                if (!is_string($target) || !$this->safeRelativePath($target)) {
                    $this->error("invalid_asset_target", "security", $assetPath . "/target", "Asset target must be a safe relative path.");
                } elseif (isset($assetTargets[$target])) {
                    $this->error("asset_collision", "collision", $assetPath . "/target", "Multiple modules claim the same asset target: {$target}.");
                } else {
                    $assetTargets[$target] = true;
                }
                if (!in_array($asset["publication"] ?? null, ["application-owned", "module-copy"], true)) {
                    $this->error("invalid_asset_publication", "asset", $assetPath . "/publication", "Expected application-owned or module-copy.");
                }
                if (($asset["disable_behavior"] ?? null) !== "preserve") {
                    $this->error("invalid_disable_behavior", "asset", $assetPath . "/disable_behavior", "Disable must preserve module assets and data.");
                }
                if (!in_array($asset["removal"] ?? null, ["owner-reviewed", "package-manager"], true)) {
                    $this->error("invalid_asset_removal", "asset", $assetPath . "/removal", "Expected owner-reviewed or package-manager removal.");
                }
            }
        }

        $this->validateDependencyCycles($dependencies, $paths);
        ksort($provided, SORT_STRING);
        return $provided;
    }

    /** @param array<string, list<string>> $dependencies
     *  @param array<string, string> $paths
     */
    private function validateDependencyCycles(array $dependencies, array $paths): void
    {
        $state = [];
        $reported = [];
        $visit = function (string $module) use (&$visit, &$state, &$reported, $dependencies, $paths): void {
            $state[$module] = 1;
            foreach ($dependencies[$module] ?? [] as $index => $dependency) {
                if (!isset($dependencies[$dependency])) {
                    continue;
                }
                if (($state[$dependency] ?? 0) === 1) {
                    $key = $module . "->" . $dependency;
                    if (!isset($reported[$key])) {
                        $reported[$key] = true;
                        $this->error("dependency_cycle", "dependency", ($paths[$module] ?? "/module_manifests") . "/depends_on/{$index}", "Module dependency cycle includes {$module} and {$dependency}.");
                    }
                    continue;
                }
                if (($state[$dependency] ?? 0) === 0) {
                    $visit($dependency);
                }
            }
            $state[$module] = 2;
        };
        $moduleIds = array_keys($dependencies);
        sort($moduleIds, SORT_STRING);
        foreach ($moduleIds as $module) {
            if (($state[$module] ?? 0) === 0) {
                $visit($module);
            }
        }
    }

    /** @param array<string, mixed> $document
     *  @return list<array<string, mixed>>
     */
    private function collection(array $document, string $name): array
    {
        $value = $document[$name] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            $this->error("invalid_type", "type", "/" . $name, "Expected an array.");
            return [];
        }
        $items = [];
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                $this->error("invalid_type", "type", "/{$name}/{$index}", "Expected an object.");
                continue;
            }
            $items[] = $item;
        }
        return $items;
    }

    /** @param array<string, mixed> $document
     *  @return list<array<string, mixed>>
     */
    private function objectList(array $document, string $name, string $path): array
    {
        $value = $document[$name] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            $this->error("invalid_type", "type", $path, "Expected an array of objects.");
            return [];
        }
        $items = [];
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                $this->error("invalid_type", "type", $path . "/{$index}", "Expected an object.");
                continue;
            }
            $items[] = $item;
        }
        return $items;
    }

    /** @param array<string, mixed> $object
     *  @param list<string> $allowed
     */
    private function allowedKeys(array $object, array $allowed, string $path): void
    {
        foreach (array_keys($object) as $key) {
            if (is_string($key) && !in_array($key, $allowed, true)) {
                $this->error("unknown_field", "schema", $path . "/" . $key, "Unknown field is not allowed by the declaration contract.");
            }
        }
    }

    private function safeRelativePath(string $path): bool
    {
        $normalized = str_replace("\\", "/", trim($path));
        return $normalized !== ""
            && !str_starts_with($normalized, "/")
            && preg_match('/^[A-Za-z0-9_.\/-]+$/D', $normalized) === 1
            && !in_array("..", explode("/", $normalized), true);
    }

    /** @param list<array<string, mixed>> $items
     *  @return array<string, true>
     */
    private function identifierMap(array $items, string $path): array
    {
        $ids = [];
        foreach ($items as $index => $item) {
            $id = $item["id"] ?? null;
            if (!is_string($id) || $id === "") {
                $this->error("invalid_type", "type", $path . "/{$index}/id", "Expected a non-empty string identifier.");
                continue;
            }
            if (isset($ids[$id])) {
                $this->error("duplicate_id", "identity", $path . "/{$index}/id", "Duplicate identifier: {$id}.");
            }
            $ids[$id] = true;
        }
        ksort($ids, SORT_STRING);
        return $ids;
    }

    /** @param array<string, mixed> $item
     *  @return list<string>
     */
    private function stringList(array $item, string $key, string $path): array
    {
        $value = $item[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            $this->error("invalid_type", "type", $path, "Expected an array of string identifiers.");
            return [];
        }
        $values = [];
        $seen = [];
        foreach ($value as $index => $entry) {
            if (!is_string($entry) || $entry === "") {
                $this->error("invalid_type", "type", $path . "/{$index}", "Expected a non-empty string identifier.");
                continue;
            }
            if (isset($seen[$entry])) {
                $this->error("duplicate_reference", "identity", $path . "/{$index}", "Duplicate reference: {$entry}.");
            }
            $seen[$entry] = true;
            $values[] = $entry;
        }
        return $values;
    }

    /** @param list<string> $values
     *  @return array<string, true>
     */
    private function stringValueMap(array $values, string $path): array
    {
        $map = [];
        foreach ($values as $index => $value) {
            if (isset($map[$value])) {
                $this->error("duplicate_id", "identity", $path . "/{$index}", "Duplicate identifier: {$value}.");
            }
            $map[$value] = true;
        }
        return $map;
    }

    /** @param array<string, true> $known */
    private function reference(mixed $value, array $known, string $path): void
    {
        if (!is_string($value) || !isset($known[$value])) {
            $this->error("broken_reference", "reference", $path, "Reference does not resolve in the Product Specification.");
        }
    }

    /** @param array<string, true> $states */
    private function transitionState(mixed $value, array $states, string $path): void
    {
        if (!is_string($value) || !isset($states[$value])) {
            $this->error("invalid_transition", "workflow", $path, "Workflow state does not resolve in the workflow state list.");
        }
    }

    /** @param list<array<string, mixed>> $items
     *  @return array<string, array<string, mixed>>
     */
    private function itemsById(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (is_string($item["id"] ?? null)) {
                $mapped[$item["id"]] = $item;
            }
        }
        return $mapped;
    }

    /** @return array<string, mixed>|null */
    private function readJsonObject(string $path, string $reportPath): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->error("input_unreadable", "input", $reportPath, "Input file is not readable.");
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error("invalid_json", "syntax", $reportPath, "Input is not valid JSON.");
            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            $this->error("invalid_type", "type", $reportPath, "Expected a JSON object.");
            return null;
        }
        return $decoded;
    }

    private function error(string $id, string $category, string $path, string $message): void
    {
        $this->errors[] = compact("id", "category", "path", "message");
    }

    private function warning(string $id, string $category, string $path, string $message): void
    {
        $this->warnings[] = compact("id", "category", "path", "message");
    }

    /** @param list<string> $declaredModules
     *  @param list<string> $missingModules
     *  @return array<string, mixed>
     */
    private function report(?string $inputSchema, ?string $productId, array $declaredModules, array $missingModules, int $moduleCount = 0): array
    {
        $this->sortFindings($this->errors);
        $this->sortFindings($this->warnings);
        return [
            "schema" => self::REPORT_SCHEMA,
            "valid" => $this->errors === [],
            "input_schema" => $inputSchema,
            "product_id" => $productId,
            "runtime_evidence" => "not_evaluated",
            "summary" => [
                "errors" => count($this->errors),
                "warnings" => count($this->warnings),
                "declared_modules" => count($declaredModules),
                "module_manifests" => $moduleCount,
            ],
            "missing_module_manifests" => $missingModules,
            "errors" => $this->errors,
            "warnings" => $this->warnings,
        ];
    }

    /** @param list<array{id:string,category:string,path:string,message:string}> $findings */
    private function sortFindings(array &$findings): void
    {
        usort($findings, static fn (array $left, array $right): int => [
            $left["path"], $left["id"], $left["category"], $left["message"],
        ] <=> [
            $right["path"], $right["id"], $right["category"], $right["message"],
        ]);
    }
}
