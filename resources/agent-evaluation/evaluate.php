<?php
declare(strict_types=1);

// Trusted grader runs outside the agent-editable application.
$project = realpath((string) ($argv[1] ?? ""));
$task = (string) ($argv[2] ?? "");
if ($project === false) { throw new RuntimeException("Missing evaluation project."); }
$container = require $project . "/bootstrap/common.php";
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ": " . $error->getMessage() . "\n");
    exit(1);
});
function evaluationCheck(bool $condition, string $invariant): void {
    if (!$condition) { throw new RuntimeException("Evaluation invariant failed: " . $invariant); }
}
if ($task === "route-json" || $task === "openapi-route") {
    $router = require $project . "/bootstrap/router.php";
    $path = $task === "route-json" ? "/api/catalog" : "/api/echo/item-42";
    $response = $router->dispatch(new Fnlla\Php\Http\Request("GET", $path));
    evaluationCheck($response instanceof Fnlla\Php\Http\Response && $response->status() === 200, "HTTP response");
    evaluationCheck(str_contains(strtolower(json_encode($response->headers(), JSON_UNESCAPED_SLASHES)), "application/json"), "JSON content type");
    $body = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
    evaluationCheck($body === ($task === "route-json" ? ["items" => [["id" => "item-1", "name" => "Example"]]] : ["id" => "item-42"]), "JSON data");
    evaluationCheck($router->routeByName("home") !== null && $router->routeByName("api.health") !== null, "existing routes");
    if ($task === "openapi-route") {
        $document = (new Fnlla\Php\Routing\OpenApiExporter())->export($router, (array) config("openapi", []));
        $operation = ((array) $document["paths"])["/api/echo/{id}"]["get"] ?? [];
        evaluationCheck(($operation["operationId"] ?? "") === "benchmark.echo", "explicit operation ID");
        $schema = $operation["responses"]["200"]["content"]["application/json"]["schema"] ?? [];
        evaluationCheck(($schema["type"] ?? "") === "object" && in_array("id", $schema["required"] ?? [], true)
            && ($schema["properties"]["id"]["type"] ?? "") === "string", "response contract");
    }
} elseif ($task === "tenant-policy") {
    $policy = new App\Policies\WorkOrderPolicy();
    $actor = ["id" => "actor-a", "active" => true];
    $resource = ["owner_id" => "actor-a", "tenant_id" => "tenant-a"];
    $tenant = new Fnlla\Php\Tenancy\TenantContext("organization", "tenant-a", "actor-a", "eval");
    evaluationCheck($policy($actor, $resource, "work-orders.update", $tenant) === true, "valid ownership");
    foreach ([
        [$actor, [...$resource, "tenant_id" => "tenant-b"], "work-orders.update", $tenant],
        [$actor, [...$resource, "owner_id" => "actor-b"], "work-orders.update", $tenant],
        [$actor, $resource, "work-orders.delete", $tenant],
        [$actor, $resource, "work-orders.update", null],
        [["id" => "actor-a", "active" => false], $resource, "work-orders.update", $tenant],
        [["id" => "actor-a", "revoked_at" => "2026-01-01"], $resource, "work-orders.update", $tenant],
        [[], $resource, "work-orders.update", $tenant],
        [$actor, null, "work-orders.update", $tenant],
        [$actor, $resource, "work-orders.update", new Fnlla\Php\Tenancy\TenantContext("none", null, "actor-a", "eval")],
    ] as $arguments) {
        evaluationCheck($policy(...$arguments) === false, "negative ownership boundary");
    }
} elseif ($task === "after-commit") {
    if (!in_array("sqlite", PDO::getAvailableDrivers(), true)) { throw new RuntimeException("Evaluation requires pdo_sqlite."); }
    $database = Fnlla\Php\Database\DatabaseManager::using(new PDO("sqlite::memory:"));
    $service = new App\Services\CommitNotifier();
    $effects = [];
    $database->transaction(function () use ($database, $service, &$effects): void {
        $service->schedule($database, function () use (&$effects): void { $effects[] = "outer"; });
        $database->transaction(function () use ($database, $service, &$effects): void {
            $service->schedule($database, function () use (&$effects): void { $effects[] = "nested"; });
        });
        evaluationCheck($effects === [], "no premature side effects");
        try {
            $database->transaction(function () use ($database, $service, &$effects): void {
                $service->schedule($database, function () use (&$effects): void { $effects[] = "rolled-back"; });
                throw new RuntimeException("fixture rollback");
            });
        } catch (RuntimeException) {}
    });
    evaluationCheck($effects === ["outer", "nested"], "commit and nested rollback");
    try {
        $database->transaction(function () use ($database, $service, &$effects): void {
            $service->schedule($database, function () use (&$effects): void { $effects[] = "outer-rollback"; });
            throw new RuntimeException("fixture rollback");
        });
    } catch (RuntimeException) {}
    evaluationCheck($effects === ["outer", "nested"], "outer rollback");
} else {
    throw new RuntimeException("Unknown evaluation task.");
}
echo json_encode(["task" => $task, "status" => "passed"], JSON_THROW_ON_ERROR) . "\n";
