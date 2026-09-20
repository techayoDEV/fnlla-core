<?php

declare(strict_types=1);

use Fnlla\Php\Container\Container;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Product\ProductModuleExtensionInterface;
use Fnlla\Php\Product\ProductModuleRegistry;
use Fnlla\Php\Routing\Router;

interface ProductModuleFixtureClockContract {}
final class ProductModuleFixtureClock implements ProductModuleFixtureClockContract {}
final class ProductModuleFixtureController
{
    public function index(): Response { return Response::text("orders"); }
}
final class ProductModuleFoundationExtension implements ProductModuleExtensionInterface
{
    public function serviceBindings(): array { return ["fixture.clock" => ProductModuleFixtureClock::class]; }
    public function routeHandlers(): array { return []; }
}
final class ProductModuleOrdersExtension implements ProductModuleExtensionInterface
{
    public function serviceBindings(): array { return []; }
    public function routeHandlers(): array { return ["orders.admin" => [ProductModuleFixtureController::class, "index"]]; }
}

$pmDirectory = sys_get_temp_dir() . "/fnlla-product-modules-" . bin2hex(random_bytes(6));
if (!mkdir($pmDirectory, 0777, true)) {
    fwrite(STDERR, "Unable to create Product Module fixture directory." . PHP_EOL);
    exit(1);
}

try {
    $fixture = pm_write_fixture($pmDirectory);
    $container = new Container();
    $registry = pm_registry($container, $fixture);
    $report = $registry->validationReport();
    pm_assert_true($report["valid"] ?? false, "Valid Product Module registry was rejected: " . json_encode($report["errors"]));
    pm_assert_same("not_evaluated", $report["runtime_evidence"] ?? null, "Registry validation claimed runtime evidence.");
    pm_assert_same([false, false, false], array_column($registry->modules(), "enabled"), "Modules must default to disabled in the fixture.");

    $enabled = $registry->enable("reports");
    pm_assert_same(["foundation", "orders", "reports"], $enabled["enabled"] ?? null, "Dependency enable order is not deterministic.");
    pm_assert_same("preserve", $enabled["data_behavior"] ?? null, "Enable must preserve module data.");

    $dataPath = $pmDirectory . "/application-owned-orders.data";
    file_put_contents($dataPath, "synthetic application data\n");
    $dataHash = hash_file("sha256", $dataPath);

    $registry->registerServices();
    pm_assert_true($container->make(ProductModuleFixtureClockContract::class) instanceof ProductModuleFixtureClock, "Declared module service was not registered through the Container.");
    $router = new Router($container);
    $registry->registerRoutes($router);
    pm_assert_true($router->routeByName("orders.admin") !== null, "Enabled Product Module route was not registered.");
    pm_assert_same([], $router->exportCache(), "Product Module routes must not be frozen into the application route cache.");
    $inspection = $registry->inspect("orders");
    pm_assert_same("preserve", $inspection["data_behavior"] ?? null, "Inspection does not expose disable data preservation.");
    pm_assert_same("public/modules/orders/orders.js", $inspection["assets"][0]["target"] ?? null, "Asset ownership plan is absent.");

    try {
        $registry->disable("foundation");
        pm_fail("Dependency of active Product Modules was disabled.");
    } catch (RuntimeException $exception) {
        pm_assert_true(str_contains($exception->getMessage(), "active dependents"), "Dependency refusal is not explicit.");
    }

    $registry->disable("reports");
    $disabled = $registry->disable("orders");
    pm_assert_same(["orders"], $disabled["changed"] ?? null, "Explicit module disable did not update only the target.");
    pm_assert_same($dataHash, hash_file("sha256", $dataPath), "Disable changed application-owned module data.");
    $disabledRouter = new Router($container);
    $registry->registerRoutes($disabledRouter);
    pm_assert_same(null, $disabledRouter->routeByName("orders.admin"), "A disabled privileged route remains reachable by name.");
    pm_assert_true(!pm_has_route($disabledRouter, "GET", "/admin/orders"), "A disabled privileged route remains registered by direct path.");

    $registry->enable("orders");
    pm_assert_same($dataHash, hash_file("sha256", $dataPath), "Re-enable changed application-owned module data.");
    $reenabledRouter = new Router($container);
    $registry->registerRoutes($reenabledRouter);
    pm_assert_true($reenabledRouter->routeByName("orders.admin") !== null, "Re-enabled Product Module route was not restored.");

    $collisionContainer = new Container();
    $collisionContainer->instance(ProductModuleFixtureClockContract::class, new ProductModuleFixtureClock());
    $collisionRegistry = pm_registry($collisionContainer, $fixture, $pmDirectory . "/collision-state.json");
    $collisionRegistry->enable("foundation");
    try {
        $collisionRegistry->registerServices();
        pm_fail("Existing Container binding collision was accepted.");
    } catch (RuntimeException $exception) {
        pm_assert_true(str_contains($exception->getMessage(), "collision"), "Service collision error is not explicit.");
    }

    $routeCollisionRegistry = pm_registry(new Container(), $fixture, $pmDirectory . "/route-collision-state.json");
    $routeCollisionRegistry->enable("orders");
    $collisionRouter = new Router(new Container());
    $collisionRouter->get("/admin/orders", [ProductModuleFixtureController::class, "index"])->name("application.orders");
    try {
        $routeCollisionRegistry->registerRoutes($collisionRouter);
        pm_fail("Existing route collision was accepted.");
    } catch (RuntimeException $exception) {
        pm_assert_true(str_contains($exception->getMessage(), "route collision"), "Route collision error is not explicit.");
    }

    pm_assert_invalid_mutations($pmDirectory, $fixture);

    [$listExit, $listOutput] = pm_process([PHP_BINARY, "fnlla", "list"], dirname(__DIR__));
    pm_assert_same(0, $listExit, "Core command list failed: " . $listOutput);
    foreach (["module:list", "module:inspect", "module:validate", "module:enable", "module:disable"] as $command) {
        pm_assert_true(str_contains($listOutput, $command), "Missing Product Module command: {$command}.");
    }
} finally {
    pm_remove_directory($pmDirectory);
}

fwrite(STDOUT, "FNLLA Product Module lifecycle tests passed." . PHP_EOL);

/** @return array{product:string,modules:list<string>,extensions:array<string,class-string>} */
function pm_write_fixture(string $directory): array
{
    $product = [
        "schema" => "fnlla.product.v1", "specification_version" => "1.0.0",
        "product" => ["id" => "module-fixture", "name" => "Module Fixture", "summary" => "Synthetic lifecycle fixture.", "origin" => ["kind" => "example_fixture", "reference" => "K-06.A"]],
        "modules" => [
            ["id" => "foundation", "description" => "Foundation"],
            ["id" => "orders", "description" => "Orders"],
            ["id" => "reports", "description" => "Reports"],
        ],
        "entities" => [["id" => "order", "module" => "orders", "description" => "Synthetic order", "tenant_scoped" => true]],
        "relations" => [], "roles" => [], "permissions" => [["id" => "orders.view", "description" => "View orders"]],
        "actions" => [["id" => "orders.view", "module" => "orders", "entity" => "order", "permissions" => ["orders.view"], "emits_events" => []]],
        "events" => [], "workflows" => [], "surfaces" => [["id" => "admin", "description" => "Admin fixture"]],
        "routes" => [["id" => "orders.admin", "surface" => "admin", "method" => "GET", "path" => "/admin/orders", "action" => "orders.view", "status" => "implemented"]],
        "capabilities" => [["id" => "tenancy.row-scope", "requirement" => "required"]],
        "requirements" => [
            "tenancy" => ["mode" => "row-scope", "required" => true, "notes" => "Synthetic."],
            "audit" => ["mode" => "disabled", "required" => false, "notes" => "Synthetic."],
            "search" => ["mode" => "disabled", "required" => false, "notes" => "Synthetic."],
            "seo" => ["mode" => "disabled", "required" => false, "notes" => "Synthetic."],
            "ai" => ["mode" => "disabled", "required" => false, "notes" => "Synthetic."],
        ],
        "proposals" => [],
    ];
    $modules = [
        "foundation" => pm_module("foundation", [], [
            ["id" => "fixture.clock", "abstract" => ProductModuleFixtureClockContract::class, "lifetime" => "singleton"],
        ]),
        "orders" => pm_module("orders", ["foundation"], [], [["id" => "orders.admin", "middleware" => ["auth"], "privileged" => true]], [[
            "id" => "orders.assets", "target" => "public/modules/orders/orders.js", "publication" => "module-copy", "disable_behavior" => "preserve", "removal" => "owner-reviewed",
        ]], ["order"], ["orders.view"]),
        "reports" => pm_module("reports", ["orders"]),
    ];
    $productPath = $directory . "/product.json";
    pm_write_json($productPath, $product);
    $modulePaths = [];
    foreach ($modules as $id => $module) {
        $path = $directory . "/{$id}.module.json";
        pm_write_json($path, $module);
        $modulePaths[] = $path;
    }
    return [
        "product" => $productPath,
        "modules" => $modulePaths,
        "extensions" => [
            "foundation" => ProductModuleFoundationExtension::class,
            "orders" => ProductModuleOrdersExtension::class,
        ],
    ];
}

/** @return array<string, mixed> */
function pm_module(string $id, array $dependencies, array $services = [], array $routes = [], array $assets = [], array $entities = [], array $actions = []): array
{
    return [
        "schema" => "fnlla.module.v1", "version" => "1.0.0", "id" => $id,
        "product_id" => "module-fixture", "description" => "Synthetic {$id} module.",
        "default_enabled" => false, "depends_on" => $dependencies, "entities" => $entities,
        "actions" => $actions, "events" => [], "capabilities" => [], "services" => $services,
        "routes" => $routes, "assets" => $assets,
    ];
}

/** @param array{product:string,modules:list<string>,extensions:array<string,class-string>} $fixture */
function pm_registry(Container $container, array $fixture, ?string $statePath = null): ProductModuleRegistry
{
    $state = $statePath ?? dirname($fixture["product"]) . "/state.json";
    return new ProductModuleRegistry($container, $fixture["product"], $fixture["modules"], $fixture["extensions"], $state);
}

/** @param array{product:string,modules:list<string>,extensions:array<string,class-string>} $fixture */
function pm_assert_invalid_mutations(string $directory, array $fixture): void
{
    $baseModules = array_map(static fn (string $path): array => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $fixture["modules"]);
    $cases = [];
    $wrongType = $baseModules; $wrongType[0]["default_enabled"] = "yes"; $cases["invalid_type"] = $wrongType;
    $cycle = $baseModules; $cycle[0]["depends_on"] = ["reports"]; $cases["dependency_cycle"] = $cycle;
    $missing = $baseModules; $missing[2]["depends_on"] = ["missing"]; $cases["broken_reference"] = $missing;
    $duplicateRoute = $baseModules; $duplicateRoute[2]["routes"] = $duplicateRoute[1]["routes"]; $cases["duplicate_module_route"] = $duplicateRoute;
    $command = $baseModules; $command[0]["command"] = "php dangerous.php"; $cases["unknown_field"] = $command;
    $cases["duplicate_module"] = [$baseModules[0], $baseModules[0], $baseModules[1], $baseModules[2]];

    foreach ($cases as $expected => $modules) {
        $paths = [];
        foreach ($modules as $index => $module) {
            $path = $directory . "/invalid-{$expected}-{$index}.json";
            pm_write_json($path, $module);
            $paths[] = $path;
        }
        $registry = new ProductModuleRegistry(new Container(), $fixture["product"], $paths, $fixture["extensions"], $directory . "/invalid-{$expected}-state.json");
        pm_assert_report_error($registry->validationReport(), $expected);
    }

    $invalidJson = $directory . "/invalid-json.module.json";
    file_put_contents($invalidJson, "{");
    $registry = new ProductModuleRegistry(new Container(), $fixture["product"], [$invalidJson], [], $directory . "/invalid-json-state.json");
    pm_assert_report_error($registry->validationReport(), "invalid_json");
}

/** @param array<string, mixed> $report */
function pm_assert_report_error(array $report, string $expected): void
{
    foreach ((array) ($report["errors"] ?? []) as $error) {
        if (($error["id"] ?? null) === $expected) {
            return;
        }
    }
    pm_fail("Missing Product Module validation error {$expected}: " . json_encode($report["errors"] ?? []));
}

function pm_has_route(Router $router, string $method, string $path): bool
{
    foreach ($router->getRoutes()[$method] ?? [] as $route) {
        if ($route["definition"]->path() === $path) {
            return true;
        }
    }
    return false;
}

function pm_write_json(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}

function pm_assert_true(bool $condition, string $message): void
{
    if (!$condition) { pm_fail($message); }
}

function pm_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) { pm_fail($message . " Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "."); }
}

function pm_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/** @param list<string> $command
 *  @return array{0:int,1:string}
 */
function pm_process(array $command, string $workingDirectory): array
{
    $process = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $workingDirectory);
    if (!is_resource($process)) { return [1, "Unable to start process."]; }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output];
}

function pm_remove_directory(string $directory): void
{
    if (!is_dir($directory)) { return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
