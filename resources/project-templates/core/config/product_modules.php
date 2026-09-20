<?php

declare(strict_types=1);

return [
    // Set a Product Specification and every corresponding module manifest.
    // Extension classes are trusted application code; JSON never names or
    // executes a provider, command, migration or uninstall operation.
    "product" => null,
    "manifests" => [],
    "extensions" => [],
    "state_path" => storage_path("framework/product-modules.json"),
];
