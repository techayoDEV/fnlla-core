<?php

declare(strict_types=1);

$root = dirname(__DIR__);

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
use Fnlla\Php\Database\QueryBuilder;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Routing\Router;
use Fnlla\Php\Support\FrameworkIdentity;
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
];

foreach ($classes as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "Missing class: " . $class . PHP_EOL);
        exit(1);
    }
}

$container = new Container();
$GLOBALS["fnlla_container"] = $container;
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