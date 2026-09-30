
$router->get("/api/echo/{id}", [\App\Controllers\EchoController::class, "show"])->openapi([
    "operationId" => "benchmark.echo", "security" => [],
    "parameters" => [["name" => "id", "in" => "path", "required" => true, "schema" => ["type" => "string"]]],
    "responses" => ["200" => ["description" => "Echoed identifier", "content" => ["application/json" => [
        "schema" => ["type" => "object", "required" => ["id"], "properties" => ["id" => ["type" => "string"]]],
    ]]]],
]);
