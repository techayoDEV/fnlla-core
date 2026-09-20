<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SUPPORT SOURCE
File: src\Support\FrameworkLock.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Tracks the exported framework base so downstream applications can compare
  framework-managed files against a newer FNLLA export safely.
*/

namespace Fnlla\Php\Support;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FrameworkLock
{
    public const LOCK_FILE = ".fnlla/framework-lock.json";
    public const MIGRATION_LOCK_FILE = ".fnlla/legacy-framework-lock.json";
    private const PROJECT_OWNED_PATHS = [
        "config/app.php",
        "public/assets/app.css",
        // Retired from new exports; existing projects may have replaced this logo.
        "public/assets/fnlla-logo.png",
        "routes/web.php",
        "src/Controllers/PageController.php",
        "views/layouts/app.php",
        "views/pages/about.php",
        "views/pages/api-health.php",
        "views/pages/contact.php",
        "views/pages/docs.php",
        "views/pages/error.php",
        "views/pages/health.php",
        "views/pages/home.php",
        "views/pages/not-found.php",
    ];
    private const LEGACY_UNTRACKED_MANAGED_HASHES = [
        "1.0.18" => [
            "public/assets/app.css" => "033d403648c515625a8ac2617c27ec6fc4a86e419d48750dd6edb9bf504ae9ba",
            "routes/web.php" => "3146de20fb78986a9ed0c61ed856f552e88bc3bed42686a952c941bbe4d34a0c",
            "src/Controllers/PageController.php" => "10ac57288b1c07335501d6f61056fbb7493ee26bad3adfea5ae60c2a30b0821a",
            "views/layouts/app.php" => "51f7110b622fd9c35e6d94cac72a8135488bba4ebec9504702095d111529bd91",
            "views/pages/about.php" => "dcd2d376e3f56d3ecc6ad3423524cb2071645b04f91d39364ce6a60ae74ad5ab",
            "views/pages/contact.php" => "2634d65eb571c82578f2304e68c8241abd1fef030fc56d036832ac8cc2648d9c",
            "views/pages/docs.php" => "032372397f0595abe9afb3bb8a39ffa3f7c830e4c81a7dceb3b5ed39ccd4211f",
            "views/pages/error.php" => "20ba77b2b013401b97e5667e3144cb9c5bd523ea82e1dd6b6e66445510562fa3",
            "views/pages/health.php" => "a775d0f35236f95a9f30545bfefc47bcef39b53bcc740c1809599ed8be0c2de6",
            "views/pages/home.php" => "62ade0d125bc276a9a49e09bdb4655b69c05ca9f3113eb8c3185d80b667028d2",
            "views/pages/not-found.php" => "edf833a1bfab1dc1a8a35c65a094a9f6125ede7ee4daa0e6b2b585902a06023c",
            "views/pages/services.php" => "d9fd32df570cc0bff9c79b3616205e14c128945f6ed2fdcf80d831277fe42c3e",
        ],
        "1.0.19" => [
            "public/assets/app.css" => "9ad7853c2664d4cf03bcb223d0aee1f015bfe28616eed2e9905de3cdb18f17f2",
            "routes/web.php" => "3b18dba0915a10df7f6603838f4f977df1d166533ca95258e809d14d5d2ce91a",
            "src/Controllers/PageController.php" => "640cba89999fda6a1073135048e7f0ce3a52f2d41237b22931257043650169b3",
            "views/layouts/app.php" => "67dbfbbed54ed47dc19eb826da8ad6f10baef56f3057f9ecf5b3b184d0e6ecfa",
            "views/pages/about.php" => "a2c9db10aa648b4d13e181b94133f0cf7a1d7a90f8e7d3c3f643c3d2d1d61db3",
            "views/pages/api-health.php" => "ada636ad0debb3c99abf841271fdb57a68c44fced7501658920b966a22d1917d",
            "views/pages/docs.php" => "f72acf96fc36ee77ef3faf3c8958f219127d81f0be934da5349a533ee9ab2e4b",
            "views/pages/error.php" => "8f5ed24ca8f9e7470bb8cad6e7f9bd1639c403e1f212297452f1497592d7a632",
            "views/pages/health.php" => "7502ad8592de4605136717742d4636297af30edd044964fa5bc1f217e5328317",
            "views/pages/home.php" => "4630d329f45b84825db422a8b704265bf594045939747627787acd34c4d7f75f",
            "views/pages/not-found.php" => "6eb9285780f4f1e7dfef284a7894a3672385b0c4491dbb64a9d27d071bbb2343",
            "views/pages/services.php" => "9191aa115b3f388f85cda5a442e5b8a100c144e1e3d3a41df29326ed8da37a34",
        ],
    ];

    public static function path(string $projectRoot): string
    {
        return rtrim($projectRoot, "\\/") . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, self::lockFile());
    }

    public static function migrationPath(string $projectRoot): string
    {
        return rtrim($projectRoot, "\\/") . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, self::migrationLockFile());
    }

    public static function lockFile(): string
    {
        return self::configuredRelativeLockPath("lock_file", self::LOCK_FILE);
    }

    public static function migrationLockFile(): string
    {
        $configured = function_exists("config")
            ? config("framework_update.migration_lock_file", self::MIGRATION_LOCK_FILE)
            : self::MIGRATION_LOCK_FILE;

        return self::normalizeConfiguredRelativeLockPath((string) $configured, self::MIGRATION_LOCK_FILE);
    }

    public static function write(string $projectRoot, string $sourceRoot, string $appName, string $packageSlug): array
    {
        $lock = self::build($projectRoot, $sourceRoot, $appName, $packageSlug);
        $directory = dirname(self::path($projectRoot));

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create framework lock directory: " . $directory);
        }

        file_put_contents(self::path($projectRoot), self::encode($lock, "framework lock"));

        return $lock;
    }

    public static function load(string $projectRoot): array
    {
        $path = self::existingPath($projectRoot);

        if ($path === null) {
            throw new RuntimeException(
                "Framework lock is missing. This project needs " . self::lockFile() . " before framework updates can be checked."
            );
        }

        return self::readLockFile($path);
    }

    public static function syncFromExport(string $exportRoot, string $projectRoot): void
    {
        $sourcePath = self::existingPath($exportRoot);

        if ($sourcePath === null) {
            return;
        }

        $targetPath = self::path($projectRoot);
        $directory = dirname($targetPath);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create framework lock directory: " . $directory);
        }

        $lock = self::readLockFile($sourcePath);

        FrameworkUpdateTransaction::replace($targetPath, self::encode($lock, "framework lock"));
    }

    public static function build(string $projectRoot, string $sourceRoot, string $appName, string $packageSlug): array
    {
        return [
            "schema_version" => 2,
            "framework_base" => [
                "application" => [
                    "name" => $appName,
                    "package_slug" => $packageSlug,
                ],
                "framework" => [
                    "name" => RuntimeIdentity::get("name"),
                    "slug" => RuntimeIdentity::get("slug"),
                    "version" => self::readVersion($sourceRoot . DIRECTORY_SEPARATOR . "VERSION"),
                    "repository" => RuntimeIdentity::get("repository_url"),
                    "website" => RuntimeIdentity::get("website"),
                    "support" => RuntimeIdentity::get("support"),
                ],
                "ui_runtime" => [
                    "name" => "Integrated FNLLA UI surface",
                    "slug" => "fnlla-runtime",
                    "version" => self::readVersion($sourceRoot . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "fnlla-runtime" . DIRECTORY_SEPARATOR . "VERSION"),
                    "repository" => RuntimeIdentity::get("repository_url"),
                    "website" => RuntimeIdentity::get("website"),
                ],
                "lock_file" => self::lockFile(),
                "profile" => ProjectProfile::name($projectRoot),
                "managed_files" => self::managedFileHashes($projectRoot),
                "generated_at_utc" => gmdate(DATE_ATOM),
            ],
        ];
    }

    public static function managedFileHashes(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, "\\/");
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }

            $path = $item->getPathname();
            $relativePath = self::normalizeSeparators(substr($path, strlen($projectRoot) + 1));

            if (!self::isFrameworkManagedPath($relativePath)) {
                continue;
            }

            $hash = hash_file("sha256", $path);

            if ($hash === false) {
                throw new RuntimeException("Unable to hash framework-managed file: " . $path);
            }

            $hashes[$relativePath] = $hash;
        }

        ksort($hashes);

        return $hashes;
    }

    public static function legacyUntrackedManagedHashes(string $frameworkVersion): array
    {
        return self::LEGACY_UNTRACKED_MANAGED_HASHES[$frameworkVersion] ?? [];
    }

    public static function legacyNormalizedHashes(string $frameworkVersion): array
    {
        if ($frameworkVersion !== "2.1.3") { return []; }
        $path = base_path("resources/update-baselines/2.1.3.json");
        if (!is_file($path)) { return []; }
        $baseline = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return (array) ($baseline["normalized_sha256"] ?? []);
    }

    public static function isFrameworkManagedPath(string $relativePath): bool
    {
        $relativePath = self::normalizeSeparators($relativePath);
        if (!self::isSafeRelativePath($relativePath)) {
            return false;
        }
        if (str_starts_with($relativePath, ".env") || $relativePath === "src/Controllers/CoreHomeController.php") {
            return false;
        }
        if ($relativePath === "VERSION" || str_starts_with($relativePath, "docs/framework/")
            || str_starts_with($relativePath, "public/assets/brand/fnlla/")
            || str_starts_with($relativePath, "public/vendor/fnlla-runtime/")) {
            return true;
        }

        foreach ([
            ".git/",
            ".fnlla/",
            "docs/",
            "app/",
            "node_modules/",
            "output/",
            "playwright-report/",
            "public/uploads/",
            "test-results/",
            "tests/",
            "tmp/",
            "vendor/",
        ] as $ignoredPrefix) {
            if (str_starts_with($relativePath, $ignoredPrefix)) {
                return false;
            }
        }

        if (in_array($relativePath, [self::LOCK_FILE, self::MIGRATION_LOCK_FILE], true)) {
            return false;
        }

        if (in_array($relativePath, self::PROJECT_OWNED_PATHS, true)) {
            return false;
        }

        if (in_array($relativePath, ["views/layouts/developer.php", "views/partials/framework-wordmark.php", "public/assets/app-base.css", "public/assets/developer-panel.css", "public/assets/developer-panel.js", "public/assets/debug-toolbar.css"], true)) {
            return true;
        }

        if (str_starts_with($relativePath, "views/maintenance/")) {
            return true;
        }

        if (str_starts_with($relativePath, "views/developer/")) {
            return true;
        }

        if (str_starts_with($relativePath, "views/customer/")) {
            return true;
        }

        foreach ([
            "database/factories/",
            "database/migrations/",
            "lang/",
            "public/assets/",
            "public/vendor/fnlla-runtime/",
            "resources/fnlla-ai-runtime/",
            "storage/",
            "views/",
        ] as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return false;
            }
        }

        return !in_array($relativePath, [
            ".env",
            ".env.example",
            ".env.local",
            "README.md",
            "MANIFEST.json",
            "VERSION",
            "composer.json",
            "database/seeders/DatabaseSeeder.php",
            "routes/console.php",
            "routes/web.php",
            "src/Controllers/PageController.php",
            "tests/BootstrapAutoloadTest.php",
        ], true);
    }

    public static function isSafeRelativePath(string $path): bool
    {
        return preg_match('~^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$~D', $path) === 1
            && !in_array("..", explode("/", $path), true) && !in_array(".", explode("/", $path), true);
    }

    private static function existingPath(string $projectRoot): ?string
    {
        $path = self::path($projectRoot);

        if (is_file($path)) {
            return $path;
        }

        $migrationPath = self::migrationPath($projectRoot);

        if (is_file($migrationPath)) {
            return $migrationPath;
        }

        return null;
    }

    private static function readLockFile(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read framework lock: " . $path);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Framework lock is not valid JSON: " . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Framework lock must decode to a JSON object.");
        }

        return self::normalize($decoded);
    }

    private static function normalize(array $decoded): array
    {
        if (isset($decoded["framework_base"]) && is_array($decoded["framework_base"])) {
            foreach ((array) ($decoded["framework_base"]["managed_files"] ?? []) as $path => $hash) {
                if (!is_string($path) || !self::isSafeRelativePath($path) || !is_string($hash)) {
                    throw new RuntimeException("Framework lock contains an unsafe managed-file entry.");
                }
            }
            return $decoded;
        }

        throw new RuntimeException("Framework lock must contain a framework_base object.");
    }

    private static function readVersion(string $path): string
    {
        $contents = file($path, FILE_IGNORE_NEW_LINES);

        if (!is_array($contents)) {
            throw new RuntimeException("Unable to read version file for framework lock: " . $path);
        }

        $version = trim((string) ($contents[0] ?? ""));

        if ($version === "") {
            throw new RuntimeException("Version file has an empty first line: " . $path);
        }

        return $version;
    }

    private static function encode(array $lock, string $label): string
    {
        try {
            return json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to encode {$label} JSON: " . $exception->getMessage(), 0, $exception);
        }
    }

    private static function configuredRelativeLockPath(string $configKey, string $default): string
    {
        $value = function_exists("config")
            ? trim((string) config("framework_update." . $configKey, $default))
            : $default;

        return self::normalizeConfiguredRelativeLockPath($value, $default);
    }

    private static function normalizeConfiguredRelativeLockPath(string $value, string $default): string
    {
        if ($value === "") {
            return $default;
        }

        $value = self::normalizeSeparators($value);

        if (str_starts_with($value, "/") || preg_match('/^[a-z]:\//i', $value)) {
            return $default;
        }

        while (str_starts_with($value, "./")) {
            $value = substr($value, 2);
        }

        return $value;
    }

    private static function normalizeSeparators(string $path): string
    {
        return str_replace("\\", "/", $path);
    }
}
