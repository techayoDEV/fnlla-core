<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || $fileInfo->getExtension() !== "php") {
        continue;
    }

    $path = $fileInfo->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR)) {
        continue;
    }

    passthru(escapeshellarg(PHP_BINARY) . " -l " . escapeshellarg($path), $exitCode);
    if ($exitCode !== 0) {
        $errors[] = $path;
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Lint failed for " . count($errors) . " file(s)." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Lint passed." . PHP_EOL);