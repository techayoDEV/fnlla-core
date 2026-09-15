<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . "/src", RecursiveDirectoryIterator::SKIP_DOTS));

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || $fileInfo->getExtension() !== "php") {
        continue;
    }

    $contents = (string) file_get_contents($fileInfo->getPathname());
    if (!str_contains($contents, "declare(strict_types=1);")) {
        $errors[] = $fileInfo->getPathname() . " is missing strict_types.";
    }
    if (preg_match('/\b(var_dump|print_r|dd)\s*\(/', $contents) === 1) {
        $errors[] = $fileInfo->getPathname() . " contains debug output helpers.";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Static analysis baseline passed." . PHP_EOL);