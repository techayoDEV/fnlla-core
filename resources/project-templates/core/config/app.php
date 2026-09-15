<?php

declare(strict_types=1);

return [
    "name" => (string) env("APP_NAME", "Application"),
    "environment" => framework_detect_environment(),
    "debug" => (bool) env("APP_DEBUG", false),
    "base_url" => rtrim((string) env("APP_URL", ""), "/"),
    "asset_url" => rtrim((string) env("ASSET_URL", ""), "/"),
    "timezone" => (string) env("APP_TIMEZONE", "UTC"),
    "locale" => (string) env("APP_LOCALE", "en"),
    "fallback_locale" => "en",
    "log_path" => storage_path("logs/app.log"),
    "session_path" => storage_path("framework/sessions"),
    "providers" => [\Fnlla\Php\Providers\CoreServiceProvider::class, \Fnlla\Php\Providers\AuthServiceProvider::class],
];
