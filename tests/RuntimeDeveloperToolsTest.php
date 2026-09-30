<?php

declare(strict_types=1);

if (!class_exists(\Fnlla\Php\Container\Container::class)) {
    require_once dirname(__DIR__) . "/vendor/autoload.php";
}
if (!defined("APP_ROOT")) { define("APP_ROOT", dirname(__DIR__)); }

use Fnlla\Php\Container\Container;
use Fnlla\Php\Routing\Router;
use Fnlla\Php\Routing\OpenApiExporter;
use Fnlla\Php\Support\RuntimeSourceMap;
use Fnlla\Php\Support\RuntimeDoctor;
use Fnlla\Php\Support\BoundedPhpProcess;
use Fnlla\Php\Testing\AdapterContractSuite;
use Fnlla\Php\Cache\FileCacheStore;
use Fnlla\Php\Queue\FileQueueStore;

function developerCheck(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
function developerReject(callable $callback, string $message): void {
    try { $callback(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message);
}

$beforeConfig = $GLOBALS["fnlla_config"] ?? [];
$GLOBALS["fnlla_config"] = ["app" => ["secret" => "must-never-leak-123"],
    "database" => ["password" => "must-never-leak-123"]];
$container = new Container();
$router = new Router($container);
$operation = ["operationId" => "items.show", "security" => [],
    "parameters" => [["name" => "id", "in" => "path", "required" => true, "schema" => ["type" => "string"]]],
    "responses" => ["200" => ["description" => "An item", "content" => ["application/json" => ["schema" => ["type" => "object"]]]]]];
$router->get("/items/{id}", static fn (): string => "must-not-dispatch")->name("items.show")->openapi($operation);
$router->post("/private", static fn (): string => "must-not-dispatch");
$map = (new RuntimeSourceMap())->report($router);
developerCheck(count($map["routes"]["items"]) === 2 && $map["routes"]["executed"] === false, "Inspection omitted routes or ran handlers.");
developerCheck($map["routes"]["items"][0]["source"]["line"] > 0, "Inspection lacks source provenance.");
developerCheck(!str_contains(json_encode($map, JSON_THROW_ON_ERROR), "must-never-leak"), "Inspection leaked config values.");
developerCheck($map["fingerprint"] === (new RuntimeSourceMap())->report($router)["fingerprint"], "Context fingerprint is not deterministic.");
$router->get("/added", static fn (): string => "");
developerCheck($map["fingerprint"] !== (new RuntimeSourceMap())->report($router)["fingerprint"], "Context fingerprint ignored route changes.");
$loaderFile = dirname(__DIR__) . "/vendor/composer/ClassLoader.php";
require_once $loaderFile;
$dependencyRouter = new Router($container);
$dependencyRouter->get("/dependency", [Composer\Autoload\ClassLoader::class, "findFile"]);
$dependencyMap = (new RuntimeSourceMap())->report($dependencyRouter);
developerCheck($dependencyMap["routes"]["items"][0]["source"]["owner"] === "external_dependency"
    && $dependencyMap["routes"]["items"][0]["source"]["path"] === null, "Dependency handler was labeled as editable application/Core source.");
$exporter = new OpenApiExporter();
$export = $exporter->export($router);
developerCheck($export["openapi"] === "3.1.1" && count((array) $export["paths"]) === 1, "OpenAPI inferred undeclared routes.");
developerCheck($export["x-fnlla-contract"]["omitted_routes"] === 2, "OpenAPI omission count is incorrect.");
$cached = new Router($container);
$cacheable = new Router($container);
$cacheable->get("/items/{id}", [DeveloperToolsController::class, "show"])->openapi($operation);
$cached->loadCachedRoutes($cacheable->exportCache());
developerCheck(json_encode($exporter->export($cached)) === json_encode($exporter->export($cacheable)), "Route cache lost API contract metadata.");
$invalid = new Router($container);
$invalid->get("/items/{id}", static fn () => null)->openapi([...$operation, "parameters" => []]);
developerReject(fn () => $exporter->export($invalid), "Missing path parameter accepted.");
$invalid = new Router($container);
$invalid->get("/items/{id}", static fn () => null)->authorize("items.view")->openapi($operation);
developerReject(fn () => $exporter->export($invalid), "Authorized route documented as public.");
$invalid = new Router($container);
$invalid->get("/items/{id}", static fn () => null)->openapi([...$operation,
    "responses" => ["200" => ['$ref' => "https://example.invalid/private"]]]);
developerReject(fn () => $exporter->export($invalid), "Remote reference accepted.");
foreach (["parameters" => "invalid", "security" => ["scheme" => []]] as $key => $value) {
    $invalid = new Router($container);
    $invalid->get("/items/{id}", static fn () => null)->openapi([...$operation, $key => $value]);
    developerReject(fn () => $exporter->export($invalid), "Malformed OpenAPI collection accepted.");
}
$invalid = new Router($container);
$invalid->get("/items/{id}", static fn () => null)->authorize("items.view")->openapi([...$operation, "security" => [[]]]);
developerReject(fn () => $exporter->export($invalid), "Optional authentication bypassed authorization documentation.");

$temporary = sys_get_temp_dir() . "/fnlla-developer-tools-" . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
try {
    developerCheck(count(AdapterContractSuite::cache(fn () => new FileCacheStore($temporary . "/cache"))) === 8, "File cache conformance failed.");
    developerCheck(count(AdapterContractSuite::reliableQueue(fn () => new FileQueueStore($temporary . "/queue"))) === 9, "File queue conformance failed.");
    $GLOBALS["fnlla_config"] = ["cache" => ["default" => "file", "stores" => ["file" => ["path" => $temporary]]]];
    $doctor = (new RuntimeDoctor())->report(2);
    developerCheck($doctor["status"] === "ready" && $doctor["checks"][0]["status"] === "not_configured", "Readiness invents database success.");
    $GLOBALS["fnlla_config"] = [];
    developerCheck((new RuntimeDoctor())->report()["status"] === "not_configured", "Unconfigured application is ready.");
    config_set("queue.default", "custom");
    developerCheck((new RuntimeDoctor())->report()["checks"][2]["status"] === "unsupported", "Custom adapter was treated as unconfigured.");
    $probe = $temporary . "/slow.php";
    file_put_contents($probe, "<?php stream_get_contents(STDIN); sleep(5); echo 'should-not-be-seen';");
    $started = microtime(true);
    $result = BoundedPhpProcess::run($probe, [], 0.2);
    developerCheck($result["status"] === "timeout" && microtime(true) - $started < 3, "Probe timeout is not enforced.");
    $probe = $temporary . "/failed.php";
    file_put_contents($probe, "<?php stream_get_contents(STDIN); fwrite(STDERR, 'private-secret'); exit(1);");
    $result = BoundedPhpProcess::run($probe, [], 1);
    developerCheck($result["output"] === "" && $result["exit_code"] === 1, "Probe exposes private stderr.");
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temporary);
    $GLOBALS["fnlla_config"] = $beforeConfig;
}
echo "Runtime inspection, OpenAPI, readiness and adapter conformance tests passed.\n";

final class DeveloperToolsController { public function show(): string { return "unused"; } }
