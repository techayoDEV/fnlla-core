<?php

declare(strict_types=1);

// Deliberately independent of autoload and framework classes that may be replaced.
return static function (string $root): array {
    $directory = rtrim($root, "/\\") . "/.fnlla/update-transaction";
    $lock = null;
    if (is_file($root . '/.fnlla/application-update/active.json')) {
        return ['ready' => false, 'lock' => null];
    }
    if (!is_file($root . '/.fnlla-release.json') && !is_file($root . '/.fnlla/package-distribution')) {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true)) { return ['ready' => false, 'lock' => null]; }
        $lock = @fopen($directory . "/lock", "c+b");
        if ($lock === false || !flock($lock, LOCK_SH | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            return ["ready" => false, "lock" => null];
        }
    }
    if (is_file($directory . "/journal.json") || is_file($root . '/.fnlla/application-update/active.json')) {
        if (is_resource($lock)) { fclose($lock); }
        return ["ready" => false, "lock" => null];
    }
    return ["ready" => true, "lock" => $lock];
};
