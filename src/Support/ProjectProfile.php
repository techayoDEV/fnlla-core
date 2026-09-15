<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

final class ProjectProfile
{
    public static function name(?string $root = null): string
    {
        $path = rtrim($root ?? base_path(), "\\/") . "/.fnlla/project-profile";
        $profile = self::normalise(is_file($path) ? trim((string) file_get_contents($path)) : "fnlla");
        if (!in_array($profile, ["fnlla", "core"], true)) {
            throw new \RuntimeException("Invalid FNLLA project profile.");
        }
        return $profile;
    }

    public static function hasPanel(?string $root = null): bool
    {
        return self::name($root) === "fnlla";
    }

    public static function edition(?string $root = null): string
    {
        return self::hasPanel($root) ? "FNLLA" : "FNLLA Core";
    }

    public static function editionLabel(?string $root = null): string
    {
        return self::hasPanel($root) ? "FNLLA" : "Core";
    }

    private static function normalise(string $profile): string
    {
        return match (strtolower(trim($profile))) {
            "fnlla", "platform", "full" => "fnlla",
            "core" => "core",
            default => trim($profile),
        };
    }

    public static function isPanelFile(string $path): bool
    {
        return str_starts_with($path, "views/developer/") || str_starts_with($path, "views/customer/")
            || str_starts_with($path, "public/assets/brand/fnlla/")
            || str_starts_with($path, "views/maintenance/") || str_starts_with($path, "src/Controllers/Developer")
            || in_array($path, ["routes/maintenance.php", "views/partials/framework-wordmark.php", "src/Controllers/CustomerAccessController.php",
                "src/Controllers/FrameworkUpdateController.php", "public/assets/developer-panel.css",
                "public/assets/developer-panel.js", "public/assets/debug-toolbar.css"], true);
    }
}
