<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA PUBLIC ENTRYPOINT
File: public\index.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Handles a public web request or static file routing boundary for the maintained framework.
*/

$root = dirname(__DIR__);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
$gatePath = $root . "/bootstrap/update-gate.php";
$gate = is_file($root . '/.fnlla/application-update/active.json') ? ['ready' => false, 'lock' => null] : (is_file($gatePath) ? (require $gatePath)($root) : ["ready" => true, "lock" => null]);
if (!$gate["ready"]) {
    http_response_code(503);
    header("Content-Type: text/plain; charset=UTF-8");
    header("Cache-Control: no-store");
    header("Retry-After: 30");
    header("X-Content-Type-Options: nosniff");
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "HEAD") {
        echo "Service temporarily unavailable. Please try again shortly.";
    }
    exit;
}
$emergency = require $root . '/bootstrap/emergency.php';
if ($emergency($root)) {
    if (is_resource($gate['lock'])) { fclose($gate['lock']); }
    exit;
}
try {
    $GLOBALS["fnlla_update_read_lease"] = ["root" => $root, "stream" => $gate["lock"]];
    $application = require $root . "/bootstrap/app.php";
    $application->run();
} catch (\Throwable) {
    @error_log('FNLLA application bootstrap failed; serving emergency response.');
    $emergency($root, true);
} finally {
    if (is_resource($gate["lock"])) { fclose($gate["lock"]); }
    unset($GLOBALS["fnlla_update_read_lease"]);
}
