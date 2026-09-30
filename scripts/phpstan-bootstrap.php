<?php

declare(strict_types=1);

// Symbol discovery only. Analysis does not boot an application or read .env.
define("APP_ROOT", dirname(__DIR__));
define("PUBLIC_ROOT", APP_ROOT . "/public");
define("VIEW_ROOT", APP_ROOT . "/views");
