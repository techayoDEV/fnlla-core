<?php

declare(strict_types=1);

define("FNLLA_RUNTIME_SKIP_AUTO_GUARD", true);
require __DIR__ . "/bootstrap.php";
// Let PHPUnit own error reporting; production bootstrap uses its own handler.
restore_error_handler();
