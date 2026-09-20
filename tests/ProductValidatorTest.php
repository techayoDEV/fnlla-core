<?php

declare(strict_types=1);

use Fnlla\Php\Product\ProductValidator;

$pvRoot = dirname(__DIR__);
$pvResources = $pvRoot . "/resources/product-specification";
$pvFixtures = __DIR__ . "/fixtures/product-specification";
$pvProductPath = $pvResources . "/examples/property-maintenance.product.json";
$pvModulePaths = [
    $pvResources . "/examples/modules/tenancy.module.json",
    $pvResources . "/examples/modules/properties.module.json",
    $pvResources . "/examples/modules/work-orders.module.json",
];

$validator = new ProductValidator();
$valid = $validator->validateFiles($pvProductPath, $pvModulePaths);
pv_assert_true($valid["valid"] ?? false, "Valid Product Specification and module declarations were rejected: " . json_encode($valid["errors"]));
pv_assert_same("fnlla.product-validation-report.v1", $valid["schema"] ?? null, "Validation report schema mismatch.");
pv_assert_same("not_evaluated", $valid["runtime_evidence"] ?? null, "Declaration validation must not claim runtime evidence.");
pv_assert_same([], $valid["missing_module_manifests"] ?? null, "Complete module declarations reported missing entries.");
pv_assert_same(3, $valid["summary"]["module_manifests"] ?? null, "Module declaration count mismatch.");

$reordered = $validator->validateFiles($pvProductPath, array_reverse($pvModulePaths));
pv_assert_same(pv_json($valid), pv_json($reordered), "Validation report depends on module input order.");

$product = pv_decode($pvProductPath);
$declarationOnly = $validator->validate($product);
pv_assert_true($declarationOnly["valid"] ?? false, "A declaration-only specification should remain structurally valid.");
pv_assert_same(["properties", "tenancy", "work-orders"], $declarationOnly["missing_module_manifests"] ?? null, "Missing implementation declarations are not visible.");
pv_assert_same(3, $declarationOnly["summary"]["warnings"] ?? null, "Missing module warnings mismatch.");

$unknown = $product;
$unknown["schema"] = "fnlla.product.v2";
pv_assert_error($validator->validate($unknown), "unknown_schema", "/schema", "schema");

$unknownVersion = $product;
$unknownVersion["specification_version"] = "2.0.0";
pv_assert_error($validator->validate($unknownVersion), "unsupported_version", "/specification_version", "schema");

$wrongType = $product;
$wrongType["modules"] = "work-orders";
pv_assert_error($validator->validate($wrongType), "invalid_type", "/modules", "type");

$duplicate = $product;
$duplicate["permissions"][] = $duplicate["permissions"][0];
pv_assert_error($validator->validate($duplicate), "duplicate_id", "/permissions/5/id", "identity");

$unsupported = $product;
$unsupported["capabilities"][] = ["id" => "billing.magic", "requirement" => "required"];
pv_assert_error($validator->validate($unsupported), "unsupported_capability", "/capabilities/5/id", "capability");

$transition = $product;
$transition["workflows"][0]["transitions"][0]["to"] = "missing-state";
pv_assert_error($validator->validate($transition), "invalid_transition", "/workflows/0/transitions/0/to", "workflow");

$broken = $validator->validateFiles($pvFixtures . "/broken-reference.product.json");
pv_assert_error($broken, "broken_reference", "/actions/0/emits_events/0", "reference");
pv_assert_error($broken, "broken_reference", "/routes/0/action", "reference");

$cycle = $validator->validateFiles($pvProductPath, [
    $pvFixtures . "/cycle-a.module.json",
    $pvFixtures . "/cycle-b.module.json",
]);
pv_assert_error_id($cycle, "dependency_cycle");

$module = pv_decode($pvModulePaths[0]);
$module["schema"] = "fnlla.module.v2";
pv_assert_error($validator->validate($product, [$module]), "unknown_module_schema", "/module_manifests/0/schema", "schema");

$module = pv_decode($pvModulePaths[0]);
$module["entities"] = ["missing-entity"];
pv_assert_error($validator->validate($product, [$module]), "broken_reference", "/module_manifests/0/entities/0", "reference");

$legacy = ["schema" => "fnlla.business_app_blueprint.v1"];
pv_assert_error($validator->validate($legacy), "migration_required", "/schema", "migration");

$invalidJson = tempnam(sys_get_temp_dir(), "fnlla-product-invalid-");
if ($invalidJson === false) {
    fwrite(STDERR, "Unable to create invalid JSON fixture." . PHP_EOL);
    exit(1);
}
try {
    file_put_contents($invalidJson, "{");
    pv_assert_error($validator->validateFiles($invalidJson), "invalid_json", "/", "syntax");
} finally {
    unlink($invalidJson);
}

[$listExit, $listOutput] = pv_process([PHP_BINARY, "fnlla", "list"], $pvRoot);
pv_assert_same(0, $listExit, "Core command list failed: " . $listOutput);
pv_assert_true(str_contains($listOutput, "product:validate"), "product:validate is not registered.");

$command = [PHP_BINARY, "fnlla", "product:validate", $pvProductPath];
foreach ($pvModulePaths as $modulePath) {
    $command[] = "--module=" . $modulePath;
}
[$commandExit, $commandOutput] = pv_process($command, $pvRoot);
pv_assert_same(0, $commandExit, "product:validate rejected valid inputs: " . $commandOutput);
pv_assert_same(pv_json($valid), pv_json(json_decode($commandOutput, true, 512, JSON_THROW_ON_ERROR)), "CLI and service reports differ.");

[$invalidExit, $invalidOutput] = pv_process([PHP_BINARY, "fnlla", "product:validate", $pvFixtures . "/broken-reference.product.json"], $pvRoot);
pv_assert_same(1, $invalidExit, "product:validate should fail invalid declarations.");
pv_assert_error(json_decode($invalidOutput, true, 512, JSON_THROW_ON_ERROR), "broken_reference", "/routes/0/action", "reference");

fwrite(STDOUT, "FNLLA Product Validator tests passed." . PHP_EOL);

/** @return array<string, mixed> */
function pv_decode(string $path): array
{
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function pv_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $report */
function pv_assert_error(array $report, string $id, string $path, string $category): void
{
    foreach ((array) ($report["errors"] ?? []) as $error) {
        if (($error["id"] ?? null) === $id && ($error["path"] ?? null) === $path && ($error["category"] ?? null) === $category) {
            pv_assert_same(["id", "category", "path", "message"], array_keys($error), "Validation finding shape changed.");
            return;
        }
    }
    fwrite(STDERR, "Missing validation error {$id} at {$path}: " . json_encode($report["errors"] ?? []) . PHP_EOL);
    exit(1);
}

/** @param array<string, mixed> $report */
function pv_assert_error_id(array $report, string $id): void
{
    foreach ((array) ($report["errors"] ?? []) as $error) {
        if (($error["id"] ?? null) === $id) {
            return;
        }
    }
    fwrite(STDERR, "Missing validation error: {$id}." . PHP_EOL);
    exit(1);
}

function pv_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function pv_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . " Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "." . PHP_EOL);
        exit(1);
    }
}

/** @param list<string> $command
 *  @return array{0:int,1:string}
 */
function pv_process(array $command, string $workingDirectory): array
{
    $process = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $workingDirectory);
    if (!is_resource($process)) {
        return [1, "Unable to start process."];
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}
