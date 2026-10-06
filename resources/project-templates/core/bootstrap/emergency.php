<?php

declare(strict_types=1);

/** Application entry guard: no autoloader, configuration, session or database. */
return static function (string $root, bool $failed = false): bool {
    $maintenance = $root . '/storage/framework/resilience/down.html';
    if (!$failed && !is_file($maintenance)) { return false; }
    $path = is_file($maintenance) ? $maintenance : $root . '/storage/framework/resilience/emergency/503.html';
    if (!is_file($path)) { $path = $root . '/public/emergency/503.html'; }
    $body = !is_link($path) && is_file($path) && filesize($path) <= 1048576 ? @file_get_contents($path) : false;
    if (!is_string($body)) {
        $body = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Service unavailable</title><main><h1>Service temporarily unavailable</h1><p>Please try again shortly.</p></main></html>';
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Retry-After: 30');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') { echo $body; }
    return true;
};
