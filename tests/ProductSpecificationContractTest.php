<?php

declare(strict_types=1);

$productRoot = dirname(__DIR__) . "/resources/product-specification";
$product = ps_json($productRoot . "/examples/property-maintenance.product.json");
$scenario = ps_json($productRoot . "/examples/property-maintenance.scenario.json");
$facts = ps_json($productRoot . "/examples/property-maintenance.implementation-facts.json");
$drift = ps_json($productRoot . "/examples/property-maintenance.drift.json");
$graph = ps_json($productRoot . "/examples/property-maintenance.graph.json");
$manifest = ps_json($productRoot . "/MANIFEST.json");

ps_assert_same("fnlla.product_contract_manifest.v1", $manifest["schema"] ?? null, "Product contract manifest schema mismatch.");
ps_assert_same("Fnlla\\Php\\Product\\ProductModuleRegistry", $manifest["module_lifecycle_registry"] ?? null, "Product Module registry manifest entry mismatch.");
ps_assert_same(["module:list", "module:inspect", "module:validate", "module:enable", "module:disable"], $manifest["module_commands"] ?? null, "Product Module command contract mismatch.");
ps_assert_same([], ps_validate($product), "Valid Product Specification was rejected.");

$unknownSchema = $product;
$unknownSchema["schema"] = "fnlla.product.v2";
ps_assert_error(ps_validate($unknownSchema), "unknown_schema", "/schema");

$wrongType = $product;
$wrongType["modules"] = "work-orders";
ps_assert_error(ps_validate($wrongType), "invalid_type", "/modules");

$duplicate = $product;
$duplicate["permissions"][] = $duplicate["permissions"][0];
ps_assert_error(ps_validate($duplicate), "duplicate_id", "/permissions/5/id");

$brokenReference = $product;
$brokenReference["routes"][0]["action"] = "work-order.missing";
ps_assert_error(ps_validate($brokenReference), "broken_reference", "/routes/0/action");

$invalidTransition = $product;
$invalidTransition["workflows"][0]["transitions"][0]["to"] = "missing-state";
ps_assert_error(ps_validate($invalidTransition), "invalid_transition", "/workflows/0/transitions/0/to");

$unsupportedCapability = $product;
$unsupportedCapability["capabilities"][] = ["id" => "billing.magic", "requirement" => "required"];
ps_assert_error(ps_validate($unsupportedCapability), "unsupported_capability", "/capabilities/5/id");

$canonical = ps_canonical_json($product);
ps_assert_same($canonical, ps_canonical_json(json_decode($canonical, true, 512, JSON_THROW_ON_ERROR)), "Canonical Product Specification export is not stable.");

ps_assert_same("fnlla.product-example-scenario.v1", $scenario["schema"] ?? null, "Scenario schema mismatch.");
ps_assert_same(true, $scenario["fixture"] ?? null, "Scenario must identify itself as a fixture.");
ps_assert_same("synthetic-test-only", $scenario["data_classification"] ?? null, "Scenario data classification mismatch.");
ps_assert_same(2, count((array) ($scenario["tenants"] ?? [])), "Scenario must contain exactly two synthetic tenants.");
$tenantIds = array_column((array) $scenario["tenants"], "id");
foreach ((array) ($scenario["work_orders"] ?? []) as $index => $workOrder) {
    ps_assert_true(in_array($workOrder["tenant_id"] ?? null, $tenantIds, true), "Work-order fixture has an unknown tenant at index " . $index . ".");
}

ps_assert_same("fnlla.product-implementation-facts.v1", $facts["schema"] ?? null, "Implementation-facts schema mismatch.");
ps_assert_same("example_fixture", $facts["origin"] ?? null, "Example facts must not claim a live repository scan.");
ps_assert_same("fnlla.product-drift.v1", $drift["schema"] ?? null, "Drift schema mismatch.");
$driftStatuses = array_column((array) ($drift["entries"] ?? []), "status");
foreach (["declared", "implemented", "unverified", "contradictory"] as $status) {
    ps_assert_true(in_array($status, $driftStatuses, true), "Drift example is missing status " . $status . ".");
}
ps_assert_true((int) ($drift["summary"]["unverified"] ?? 0) > 0, "Drift example must visibly retain missing implementation.");
ps_assert_same("fnlla.product-graph.v1", $graph["schema"] ?? null, "Derived graph schema mismatch.");
ps_assert_same(false, $graph["authoritative"] ?? null, "Derived graph must be explicitly non-authoritative.");

$sensitiveKeyPattern = '/(?:password|secret|token|credential|customer|fionn_memory)/i';
$sensitiveValuePattern = '/(?:-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|\bgh[pousr]_[A-Za-z0-9_]{30,}\b|\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b|\bAKIA[A-Z0-9]{16}\b)/';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($productRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    ps_assert_true(preg_match($sensitiveValuePattern, $contents) !== 1, "Sensitive value pattern found in " . $file->getPathname() . ".");
    if ($file->getExtension() === "json") {
        ps_assert_no_sensitive_keys(json_decode($contents, true, 512, JSON_THROW_ON_ERROR), $sensitiveKeyPattern, $file->getPathname());
    }
}

foreach ((array) ($manifest["schemas"] ?? []) as $schemaFile) {
    $schema = ps_json($productRoot . "/" . $schemaFile);
    ps_assert_true(isset($schema['$schema'], $schema['$id'], $schema["type"]), "Public JSON Schema metadata missing from " . $schemaFile . ".");
}

fwrite(STDOUT, "FNLLA Product Specification contract test passed." . PHP_EOL);

/** @return array<string, mixed> */
function ps_json(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Expected JSON object at " . $path . PHP_EOL);
        exit(1);
    }
    return $decoded;
}

/** @param array<string, mixed> $specification
 *  @return list<array{id:string,path:string,category:string}>
 */
function ps_validate(array $specification): array
{
    $errors = [];
    $add = static function (string $id, string $path, string $category) use (&$errors): void {
        $errors[] = ["id" => $id, "path" => $path, "category" => $category];
    };

    if (($specification["schema"] ?? null) !== "fnlla.product.v1") {
        $add("unknown_schema", "/schema", "schema");
        return $errors;
    }

    $collections = ["modules", "entities", "relations", "roles", "permissions", "actions", "events", "workflows", "surfaces", "routes", "capabilities", "proposals"];
    foreach ($collections as $collection) {
        if (!isset($specification[$collection]) || !is_array($specification[$collection])) {
            $add("invalid_type", "/" . $collection, "type");
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    $ids = [];
    foreach ($collections as $collection) {
        $ids[$collection] = [];
        foreach ($specification[$collection] as $index => $item) {
            if (!is_array($item) || !is_string($item["id"] ?? null)) {
                $add("invalid_type", "/" . $collection . "/" . $index . "/id", "type");
                continue;
            }
            $id = $item["id"];
            if (isset($ids[$collection][$id])) {
                $add("duplicate_id", "/" . $collection . "/" . $index . "/id", "identity");
            }
            $ids[$collection][$id] = true;
        }
    }

    foreach ($specification["entities"] as $index => $entity) {
        ps_check_ref($entity["module"] ?? null, $ids["modules"], "/entities/" . $index . "/module", $add);
    }
    foreach ($specification["relations"] as $index => $relation) {
        ps_check_ref($relation["from_entity"] ?? null, $ids["entities"], "/relations/" . $index . "/from_entity", $add);
        ps_check_ref($relation["to_entity"] ?? null, $ids["entities"], "/relations/" . $index . "/to_entity", $add);
    }
    foreach ($specification["roles"] as $index => $role) {
        foreach ((array) ($role["permissions"] ?? []) as $permissionIndex => $permission) {
            ps_check_ref($permission, $ids["permissions"], "/roles/" . $index . "/permissions/" . $permissionIndex, $add);
        }
    }
    foreach ($specification["actions"] as $index => $action) {
        ps_check_ref($action["module"] ?? null, $ids["modules"], "/actions/" . $index . "/module", $add);
        ps_check_ref($action["entity"] ?? null, $ids["entities"], "/actions/" . $index . "/entity", $add);
        foreach ((array) ($action["permissions"] ?? []) as $permissionIndex => $permission) {
            ps_check_ref($permission, $ids["permissions"], "/actions/" . $index . "/permissions/" . $permissionIndex, $add);
        }
        foreach ((array) ($action["emits_events"] ?? []) as $eventIndex => $event) {
            ps_check_ref($event, $ids["events"], "/actions/" . $index . "/emits_events/" . $eventIndex, $add);
        }
    }
    foreach ($specification["events"] as $index => $event) {
        ps_check_ref($event["entity"] ?? null, $ids["entities"], "/events/" . $index . "/entity", $add);
    }
    foreach ($specification["workflows"] as $workflowIndex => $workflow) {
        ps_check_ref($workflow["entity"] ?? null, $ids["entities"], "/workflows/" . $workflowIndex . "/entity", $add);
        $states = array_fill_keys((array) ($workflow["states"] ?? []), true);
        foreach ((array) ($workflow["transitions"] ?? []) as $transitionIndex => $transition) {
            foreach (["from", "to"] as $side) {
                if (!isset($states[$transition[$side] ?? null])) {
                    $add("invalid_transition", "/workflows/" . $workflowIndex . "/transitions/" . $transitionIndex . "/" . $side, "workflow");
                }
            }
            ps_check_ref($transition["action"] ?? null, $ids["actions"], "/workflows/" . $workflowIndex . "/transitions/" . $transitionIndex . "/action", $add);
        }
    }
    foreach ($specification["routes"] as $index => $route) {
        ps_check_ref($route["surface"] ?? null, $ids["surfaces"], "/routes/" . $index . "/surface", $add);
        ps_check_ref($route["action"] ?? null, $ids["actions"], "/routes/" . $index . "/action", $add);
    }
    $knownCapabilities = ["tenancy.row-scope", "audit.append-only", "search.exact-match", "seo.metadata", "ai.semantic-search"];
    foreach ($specification["capabilities"] as $index => $capability) {
        if (!in_array($capability["id"] ?? null, $knownCapabilities, true)) {
            $add("unsupported_capability", "/capabilities/" . $index . "/id", "capability");
        }
    }

    return $errors;
}

/** @param array<string, bool> $known */
function ps_check_ref(mixed $value, array $known, string $path, callable $add): void
{
    if (!is_string($value) || !isset($known[$value])) {
        $add("broken_reference", $path, "reference");
    }
}

/** @param list<array{id:string,path:string,category:string}> $errors */
function ps_assert_error(array $errors, string $id, string $path): void
{
    foreach ($errors as $error) {
        if ($error["id"] === $id && $error["path"] === $path) {
            return;
        }
    }
    fwrite(STDERR, "Expected validation error " . $id . " at " . $path . ". Got " . json_encode($errors) . PHP_EOL);
    exit(1);
}

function ps_canonical_json(mixed $value): string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (!is_array($item)) {
            return $item;
        }
        if (array_is_list($item)) {
            return array_map($normalize, $item);
        }
        ksort($item, SORT_STRING);
        foreach ($item as $key => $child) {
            $item[$key] = $normalize($child);
        }
        return $item;
    };
    return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function ps_assert_no_sensitive_keys(mixed $value, string $pattern, string $path): void
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && preg_match($pattern, $key) === 1) {
            fwrite(STDERR, "Sensitive key found in public product resource: " . $path . "#" . $key . PHP_EOL);
            exit(1);
        }
        ps_assert_no_sensitive_keys($child, $pattern, $path);
    }
}

function ps_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function ps_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . " Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "." . PHP_EOL);
        exit(1);
    }
}
