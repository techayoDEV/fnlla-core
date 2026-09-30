<?php

declare(strict_types=1);

namespace Fnlla\Php\Routing;

use InvalidArgumentException;

final class OpenApiExporter
{
    /** Explicit route metadata only: no schema inference, remote references or requests.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function export(Router $router, array $options = []): array
    {
        $info = $options["info"] ?? ["title" => "Application API", "version" => "unversioned"];
        if (!is_array($info) || !is_string($info["title"] ?? null) || trim($info["title"]) === ""
            || !is_string($info["version"] ?? null) || trim($info["version"]) === "") {
            throw new InvalidArgumentException("OpenAPI info requires a title and version.");
        }
        $paths = [];
        $operationIds = [];
        $shapes = [];
        $omitted = 0;
        foreach ($router->getRoutes() as $items) {
            foreach ($items as $item) {
                /** @var RouteDefinition $route */
                $route = $item["definition"];
                $operation = $route->metadata("openapi");
                if ($operation === null) { $omitted++; continue; }
                if (!is_array($operation)) { throw new InvalidArgumentException("OpenAPI operation must be an object."); }
                $method = strtolower($route->method());
                if (!in_array($method, ["get", "post", "put", "patch", "delete", "head", "options", "trace"], true)) {
                    throw new InvalidArgumentException("Unsupported OpenAPI HTTP method.");
                }
                $path = $route->path();
                $shape = preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/', "{}", $path);
                if (isset($shapes[$shape]) && $shapes[$shape] !== $path) {
                    throw new InvalidArgumentException("Ambiguous OpenAPI templated path.");
                }
                $shapes[$shape] = $path;
                if (isset($paths[$path][$method])) { throw new InvalidArgumentException("Duplicate OpenAPI operation."); }
                $id = $operation["operationId"] ?? null;
                if (!is_string($id) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $id) !== 1 || isset($operationIds[$id])) {
                    throw new InvalidArgumentException("OpenAPI operationId must be explicit and unique.");
                }
                $operationIds[$id] = true;
                $responses = $operation["responses"] ?? null;
                if (!is_array($responses) || $responses === []) {
                    throw new InvalidArgumentException("OpenAPI operation requires explicit responses.");
                }
                foreach ($responses as $status => $response) {
                    if (preg_match('/^(?:[1-5][0-9]{2}|[1-5]XX|default)$/D', (string) $status) !== 1
                        || !is_array($response) || (!isset($response['$ref']) && !is_string($response["description"] ?? null))) {
                        throw new InvalidArgumentException("Invalid OpenAPI response declaration.");
                    }
                    if (isset($response["content"])) {
                        if (!is_array($response["content"]) || $response["content"] === [] || array_is_list($response["content"])) {
                            throw new InvalidArgumentException("OpenAPI response content must be a nonempty media-type object.");
                        }
                        foreach ($response["content"] as $media) {
                            if (!is_array($media)) { throw new InvalidArgumentException("Invalid OpenAPI media type."); }
                            if (array_key_exists("schema", $media)) { $this->validateSchema($media["schema"]); }
                        }
                    }
                }
                // Security must be intentional, including [] for an explicitly public API.
                if (!array_key_exists("security", $operation) || !is_array($operation["security"])) {
                    throw new InvalidArgumentException("Declare OpenAPI security explicitly; [] means public.");
                }
                if (!array_is_list($operation["security"])) { throw new InvalidArgumentException("OpenAPI security must be a list."); }
                if ($route->metadata("authorize_ability") !== null
                    && ($operation["security"] === [] || in_array([], $operation["security"], true))) {
                    throw new InvalidArgumentException("An authorized route cannot be documented as public.");
                }
                preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);
                $pathNames = $matches[1];
                $declared = [];
                $keys = [];
                if (isset($operation["parameters"]) && (!is_array($operation["parameters"]) || !array_is_list($operation["parameters"]))) {
                    throw new InvalidArgumentException("OpenAPI parameters must be a list.");
                }
                foreach ($operation["parameters"] ?? [] as $parameter) {
                    if (!is_array($parameter) || !is_string($parameter["name"] ?? null)
                        || !in_array($parameter["in"] ?? null, ["path", "query", "header", "cookie"], true)) {
                        throw new InvalidArgumentException("Use explicit inline OpenAPI parameters.");
                    }
                    $key = $parameter["in"] . ":" . $parameter["name"];
                    if (isset($keys[$key])) { throw new InvalidArgumentException("Duplicate OpenAPI parameter."); }
                    $keys[$key] = true;
                    if (array_key_exists("schema", $parameter) === array_key_exists("content", $parameter)) {
                        throw new InvalidArgumentException("OpenAPI parameters need exactly one of schema or content.");
                    }
                    if (array_key_exists("schema", $parameter)) { $this->validateSchema($parameter["schema"]); }
                    if ($parameter["in"] === "path") {
                        if (($parameter["required"] ?? false) !== true || !in_array($parameter["name"], $pathNames, true)) {
                            throw new InvalidArgumentException("Invalid OpenAPI path parameter.");
                        }
                        $declared[] = $parameter["name"];
                    }
                }
                sort($declared); sort($pathNames);
                if ($declared !== $pathNames) { throw new InvalidArgumentException("Declare every OpenAPI path parameter."); }
                $paths[$path][$method] = $operation;
            }
        }
        ksort($paths);
        foreach ($paths as &$operations) { ksort($operations); }
        unset($operations);
        $document = ["openapi" => "3.1.1", "info" => $info, "paths" => $paths,
            "x-fnlla-contract" => ["schema" => "fnlla.openapi.export.v1", "omitted_routes" => $omitted,
                "runtime_validation" => false]];
        if (isset($options["components"])) {
            if (!is_array($options["components"]) || ($options["components"] !== [] && array_is_list($options["components"]))) {
                throw new InvalidArgumentException("OpenAPI components must be an object.");
            }
            $document["components"] = $options["components"];
        }
        $this->validateReferences($document, $document);
        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation["security"] as $requirement) {
                    if (!is_array($requirement) || ($requirement !== [] && array_is_list($requirement))) {
                        throw new InvalidArgumentException("Invalid security requirement.");
                    }
                    foreach ($requirement as $scheme => $scopes) {
                        if (!isset($document["components"]["securitySchemes"][$scheme]) || !is_array($scopes)
                            || !array_is_list($scopes) || count(array_filter($scopes, "is_string")) !== count($scopes)) {
                            throw new InvalidArgumentException("OpenAPI security scheme is undeclared.");
                        }
                    }
                }
            }
        }
        // JSON objects must stay objects, including an empty Paths Object.
        foreach ($document["paths"] as &$operations) {
            foreach ($operations as &$operation) {
                $operation["security"] = array_map(static fn (array $requirement): object => (object) $requirement, $operation["security"]);
            }
            unset($operation);
        }
        unset($operations);
        if (($document["components"] ?? null) === []) { $document["components"] = (object) []; }
        $document["paths"] = (object) $document["paths"];
        json_encode($document, JSON_THROW_ON_ERROR);
        return $document;
    }

    private function validateSchema(mixed $schema): void
    {
        if (!is_bool($schema) && (!is_array($schema) || $schema === [] || array_is_list($schema))) {
            throw new InvalidArgumentException("OpenAPI schemas require a nonempty object or a boolean; use true for an unconstrained schema.");
        }
    }

    private function validateReferences(mixed $value, array $document, int $depth = 0): void
    {
        if ($depth > 48) { throw new InvalidArgumentException("OpenAPI declaration is too deeply nested."); }
        if (!is_array($value)) {
            if (is_object($value) || is_resource($value)) { throw new InvalidArgumentException("OpenAPI declarations must contain JSON data."); }
            return;
        }
        foreach ($value as $key => $child) {
            if ($key === '$ref') {
                if (!is_string($child) || !str_starts_with($child, "#/components/")) {
                    throw new InvalidArgumentException("Only local OpenAPI component references are supported.");
                }
                $target = $document;
                foreach (explode("/", substr($child, 2)) as $segment) {
                    $segment = str_replace(["~1", "~0"], ["/", "~"], $segment);
                    if (!is_array($target) || !array_key_exists($segment, $target)) {
                        throw new InvalidArgumentException("Unresolved OpenAPI component reference.");
                    }
                    $target = $target[$segment];
                }
            }
            $this->validateReferences($child, $document, $depth + 1);
        }
    }
}
