<?php

declare(strict_types=1);

// Symbol discovery only: do not boot services or read a developer's .env during analysis.
define("APP_ROOT", dirname(__DIR__));
define("PUBLIC_ROOT", APP_ROOT . "/public");
define("VIEW_ROOT", APP_ROOT . "/views");
