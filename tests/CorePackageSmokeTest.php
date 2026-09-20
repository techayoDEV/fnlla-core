<?php

declare(strict_types=1);

$root = dirname(__DIR__);

if (!defined("APP_ROOT")) {
    define("APP_ROOT", $root);
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = "Fnlla\\Php\\";
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = $root . "/src/" . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
    if (is_file($path)) {
        require $path;
    }
});

require_once $root . "/src/Support/helpers.php";

use Fnlla\Php\Container\Container;
use Fnlla\Php\Console\Application as ConsoleApplication;
use Fnlla\Php\Console\Commands\MakeProjectCommand;
use Fnlla\Php\Database\QueryBuilder;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Routing\Router;
use Fnlla\Php\Support\FrameworkIdentity;
use Fnlla\Php\Support\RuntimeIdentity;
use Fnlla\Php\Validation\ValidationException;
use Fnlla\Php\Validation\Validator;

$GLOBALS["fnlla_config"] = [
    "app" => [
        "base_url" => "",
    ],
    "http" => [
        "security_headers" => [],
    ],
];

$classes = [
    Container::class,
    Fnlla\Php\Console\Application::class,
    Request::class,
    Response::class,
    Router::class,
    Validator::class,
    Fnlla\Php\View\View::class,
    FrameworkIdentity::class,
    RuntimeIdentity::class,
];

foreach ($classes as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "Missing class: " . $class . PHP_EOL);
        exit(1);
    }
}

$container = new Container();
$GLOBALS["fnlla_container"] = $container;
$GLOBALS["fnlla_php_container"] = $container;
$container->instance(Container::class, $container);
$container->singleton(stdClass::class, static fn (): stdClass => (object) ["ok" => true]);
assert_true($container->make(stdClass::class) === $container->make(stdClass::class), "Container singleton contract failed.");

$validated = Validator::make(
    ["email" => "developer@example.test"],
    ["email" => "required|email"]
)->validate();
assert_same("developer@example.test", $validated["email"] ?? null, "Validator contract failed.");

expect_exception(ValidationException::class, static fn (): array => Validator::make(
    ["email" => "not-an-email"],
    ["email" => "required|email"]
)->validate(), "Validator should reject invalid email.");

$request = Request::capture(
    '{"name":"Core"}',
    [
        "REQUEST_METHOD" => "POST",
        "REQUEST_URI" => "/payload?debug=1",
        "CONTENT_TYPE" => "application/json",
        "CONTENT_LENGTH" => "15",
        "HTTP_X_REQUEST_ID" => "core-request-1",
    ]
);
assert_same("POST", $request->method(), "JSON request method mismatch.");
assert_same("/payload", $request->path(), "JSON request path mismatch.");
assert_same("Core", $request->json("name"), "JSON request body mismatch.");
assert_same("core-request-1", $request->requestId(), "Request ID mismatch.");

$router = new Router($container);
$router->get("/projects/{slug}", static fn (Request $request, string $slug): Response => Response::json([
    "slug" => $slug,
    "route_param" => $request->input("slug"),
]))->name("projects.show");

$routeResponse = $router->dispatch(Request::capture("", [
    "REQUEST_METHOD" => "GET",
    "REQUEST_URI" => "/projects/core",
]));
assert_true($routeResponse instanceof Response, "Router did not return a response.");
assert_same(200, $routeResponse->status(), "Route response status mismatch.");
$payload = json_decode($routeResponse->body(), true, 512, JSON_THROW_ON_ERROR);
assert_same("core", $payload["slug"] ?? null, "Route parameter mismatch.");
assert_same("core", $payload["route_param"] ?? null, "Route input mismatch.");

$optionsResponse = $router->dispatch(Request::capture("", [
    "REQUEST_METHOD" => "OPTIONS",
    "REQUEST_URI" => "/projects/core",
]));
assert_true($optionsResponse instanceof Response, "OPTIONS did not return a response.");
assert_same(204, $optionsResponse->status(), "OPTIONS response status mismatch.");
assert_true(str_contains((string) ($optionsResponse->headers()["Allow"] ?? ""), "GET"), "OPTIONS Allow header missing GET.");

expect_exception(RuntimeException::class, static fn (): Response => Response::text("bad")->withHeader("X-Test", "bad\r\nheader"), "Response should reject unsafe header values.");

if (in_array("sqlite", PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO("sqlite::memory:");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, deleted_at TEXT NULL)");
    $builder = new QueryBuilder($pdo, "users");
    assert_true($builder->insert(["email" => "core@example.test", "deleted_at" => null]), "Query builder insert failed.");
    $row = (new QueryBuilder($pdo, "users"))->where("email", "core@example.test")->whereNull("deleted_at")->first();
    assert_same("core@example.test", $row["email"] ?? null, "Query builder select failed.");
    expect_exception(RuntimeException::class, static fn (): QueryBuilder => new QueryBuilder($pdo, "users; DROP TABLE users"), "Query builder should reject unsafe table names.");
}

assert_same("techayoDEV/fnlla-core", FrameworkIdentity::REPOSITORY, "Core repository identity was not rewritten.");
assert_same("FNLLA Core", FrameworkIdentity::PRODUCT_NAME, "Core product identity was not rewritten.");
assert_same("FNLLA Core", RuntimeIdentity::get("name"), "Core runtime identity default is incorrect.");
RuntimeIdentity::configure(["name" => "Consumer Runtime", "slug" => "consumer-runtime"]);
assert_same("Consumer Runtime", RuntimeIdentity::get("name"), "Consumer runtime identity was not applied.");
RuntimeIdentity::reset();
assert_same("FNLLA Core", RuntimeIdentity::get("name"), "Core runtime identity reset failed.");

$target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "fnlla-core-make-project-" . bin2hex(random_bytes(4));
$coreVersion = trim((string) file_get_contents($root . "/VERSION"));
$container->singleton(ConsoleApplication::class);
$console = $container->make(ConsoleApplication::class);
$console->register(MakeProjectCommand::class);
$exitCode = $console->run(["fnlla", "make:project", $target, "Core Smoke"]);
assert_same(0, $exitCode, "make:project should export a Core project.");
assert_true(is_file($target . "/composer.json"), "Core project composer.json missing.");
assert_true(is_file($target . "/fnlla"), "Core project CLI launcher missing.");
assert_true(is_file($target . "/packages/fnlla-core/src/Application.php"), "Bundled Core package missing.");
assert_true(is_file($target . "/packages/fnlla-core/resources/product-specification/fnlla.product.v1.schema.json"), "Bundled Product Specification schema missing.");
assert_true(is_file($target . "/packages/fnlla-core/resources/security/fnlla.audit-event.v1.schema.json"), "Bundled audit event schema missing.");
assert_true(is_file($target . "/packages/fnlla-core/resources/events/fnlla.domain-event.v1.schema.json"), "Bundled domain event schema missing.");
assert_true(is_file($target . "/config/product_modules.php"), "Exported Core Product Module configuration missing.");
assert_true(is_file($target . "/config/actions.php"), "Exported Core action/outbox configuration missing.");
assert_true(is_file($target . "/AGENTS.md"), "Exported Core product guidance missing.");
assert_true(str_contains((string) file_get_contents($target . "/AGENTS.md"), "php fnlla route:list"), "Core product guidance does not use the Core CLI.");
assert_true(!str_contains((string) file_get_contents($target . "/AGENTS.md"), "project:claim"), "Core product guidance names a full-Framework command.");
assert_true(str_contains((string) file_get_contents($target . "/bootstrap/router.php"), '"tenant"'), "Exported Core tenant middleware alias missing.");
assert_true(str_contains((string) file_get_contents($target . "/.env.example"), "TENANCY_MODE=none"), "Exported Core tenancy default missing.");
assert_same(
    hash_file("sha256", $root . "/resources/product-specification/examples/property-maintenance.product.json"),
    hash_file("sha256", $target . "/packages/fnlla-core/resources/product-specification/examples/property-maintenance.product.json"),
    "Bundled Product Specification example changed during export."
);
assert_true(str_contains((string) file_get_contents($target . "/README.md"), "Core Smoke"), "Core project README was not customized.");
assert_true(str_contains((string) file_get_contents($target . "/README.md"), "GitHub or fnlla.com"), "Core project README should document official update sources.");

$packageManifest = file($target . "/packages/fnlla-core/FNLLA-MANIFEST.sha256", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
assert_true($packageManifest !== [], "Bundled Core package manifest is empty.");
foreach ($packageManifest as $entry) {
    assert_same(1, preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*)$/D', $entry, $matches), "Invalid bundled Core manifest entry.");
    $manifestFile = $target . "/packages/fnlla-core/" . $matches[2];
    assert_true(is_file($manifestFile), "Bundled Core manifest file is missing: " . $matches[2]);
    assert_same($matches[1], hash_file("sha256", $manifestFile), "Project customization changed bundled Core content: " . $matches[2]);
}
$projectComposer = json_decode((string) file_get_contents($target . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
$bundledComposer = json_decode((string) file_get_contents($target . "/packages/fnlla-core/composer.json"), true, 512, JSON_THROW_ON_ERROR);
if (str_contains($coreVersion, "-")) {
    assert_same($bundledComposer["version"], $projectComposer["require"]["techayodev/fnlla-core"] ?? null, "Prerelease export is not pinned to the bundled Core version.");
} else {
    $parts = explode(".", $coreVersion);
    assert_same("~" . $parts[0] . "." . $parts[1] . ".0", $projectComposer["require"]["techayodev/fnlla-core"] ?? null, "Stable export does not use the supported minor constraint.");
}
if (str_contains(strtolower($coreVersion), "-rc.")) {
    assert_same("RC", $projectComposer["minimum-stability"] ?? null, "RC export does not declare the required Composer stability.");
    assert_same(true, $projectComposer["prefer-stable"] ?? null, "RC export should prefer stable transitive dependencies.");
}

[$routeExit, $routeOutput] = run_process([PHP_BINARY, "fnlla", "route:list"], $target);
assert_same(0, $routeExit, "Exported Core route:list failed: " . $routeOutput);
assert_true(str_contains($routeOutput, "GET"), "Exported Core route:list did not list routes.");

[$productExit, $productOutput] = run_process([
    PHP_BINARY,
    "fnlla",
    "product:validate",
    "packages/fnlla-core/resources/product-specification/examples/property-maintenance.product.json",
    "--module=packages/fnlla-core/resources/product-specification/examples/modules/tenancy.module.json",
    "--module=packages/fnlla-core/resources/product-specification/examples/modules/properties.module.json",
    "--module=packages/fnlla-core/resources/product-specification/examples/modules/work-orders.module.json",
], $target);
assert_same(0, $productExit, "Exported Core product:validate failed: " . $productOutput);
$productReport = json_decode($productOutput, true, 512, JSON_THROW_ON_ERROR);
assert_same("fnlla.product-validation-report.v1", $productReport["schema"] ?? null, "Exported Product Validator report schema mismatch.");
assert_same(true, $productReport["valid"] ?? null, "Exported Product Validator rejected valid declarations.");

[$moduleExit, $moduleOutput] = run_process([PHP_BINARY, "fnlla", "module:validate"], $target);
assert_same(0, $moduleExit, "Exported Core module:validate failed: " . $moduleOutput);
$moduleReport = json_decode($moduleOutput, true, 512, JSON_THROW_ON_ERROR);
assert_same("product_modules_not_configured", $moduleReport["warnings"][0]["id"] ?? null, "Unconfigured exported module registry is not explicit.");

[$testExit, $testOutput] = run_process([PHP_BINARY, "scripts/test.php"], $target);
assert_same(0, $testExit, "Exported Core tests failed: " . $testOutput);

remove_directory($target);

fwrite(STDOUT, "FNLLA Core package smoke test passed." . PHP_EOL);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . " Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "." . PHP_EOL);
        exit(1);
    }
}

function expect_exception(string $class, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }

        fwrite(STDERR, $message . " Unexpected exception: " . get_class($exception) . " " . $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function run_process(array $command, string $cwd): array
{
    $descriptorSpec = [
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);

    if (!is_resource($process)) {
        return [1, "Unable to start process."];
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $output];
}

function remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($directory);
}
