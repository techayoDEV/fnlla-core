<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class CoreProjectExporter
{
    public function export(string $targetRoot, string $appName, string $packageSlug): void
    {
        $packageRoot = $this->packageRoot();
        $templateRoot = $packageRoot . "/resources/project-templates/core";

        if (!is_dir($templateRoot)) {
            throw new RuntimeException("Core project template is missing.");
        }

        $this->copyDirectory($templateRoot, $targetRoot);
        $this->copyCorePackage($packageRoot, $targetRoot . "/packages/fnlla-core");
        $this->writeProjectComposer($targetRoot, $appName, $packageSlug);
        $this->replaceTokens($targetRoot, [
            "{{APP_NAME}}" => $this->cleanAppName($appName),
            "{{APP_SLUG}}" => $packageSlug,
            "{{FNLLA_CORE_VERSION}}" => $this->version(),
        ]);
        $this->writeRuntimePlaceholders($targetRoot);
    }

    private function copyCorePackage(string $sourceRoot, string $packageRoot): void
    {
        foreach (["src", "bootstrap", "docs/framework", "branding", "resources/product-specification", "resources/security", "resources/events", "resources/project-templates/core"] as $directory) {
            $source = $sourceRoot . "/" . $directory;

            if (is_dir($source)) {
                $this->copyDirectory($source, $packageRoot . "/" . $directory);
            }
        }

        foreach (["README.md", "LICENSE.md", "SECURITY.md", "VERSION"] as $file) {
            $source = $sourceRoot . "/" . $file;

            if (is_file($source)) {
                $this->write($packageRoot . "/" . $file, (string) file_get_contents($source));
            }
        }

        $this->write($packageRoot . "/composer.json", json_encode([
            "name" => "techayodev/fnlla-core",
            "description" => "Open PHP framework core for FNLLA applications.",
            "type" => "library",
            "license" => "MIT",
            "version" => $this->version(),
            "homepage" => "https://fnlla.com",
            "support" => [
                "issues" => "https://github.com/techayoDEV/fnlla-core/issues",
                "source" => "https://github.com/techayoDEV/fnlla-core",
            ],
            "require" => [
                "php" => "^8.3",
                "ext-fileinfo" => "*",
                "ext-json" => "*",
                "ext-mbstring" => "*",
                "ext-pdo" => "*",
                "ext-session" => "*",
            ],
            "suggest" => [
                "ext-pdo_mysql" => "Required by the maintained MySQL database connection.",
                "ext-redis" => "Required only for Redis cache, queue or session drivers.",
            ],
            "autoload" => [
                "psr-4" => [
                    "Fnlla\\Php\\" => "src/",
                ],
                "files" => [
                    "src/Support/helpers.php",
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        $this->writePackageManifest($packageRoot);
    }

    private function writePackageManifest(string $packageRoot): void
    {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink() || $item->getFilename() === "FNLLA-MANIFEST.sha256") {
                continue;
            }
            $path = str_replace("\\", "/", $item->getPathname());
            $relative = ltrim(substr($path, strlen(rtrim(str_replace("\\", "/", $packageRoot), "/"))), "/");
            $hashes[$relative] = hash_file("sha256", $item->getPathname());
        }
        ksort($hashes, SORT_STRING);
        $manifest = "";
        foreach ($hashes as $relative => $hash) {
            $manifest .= $hash . "  " . $relative . PHP_EOL;
        }
        $this->write($packageRoot . "/FNLLA-MANIFEST.sha256", $manifest);
    }

    private function writeProjectComposer(string $targetRoot, string $appName, string $packageSlug): void
    {
        $this->write($targetRoot . "/composer.json", json_encode([
            "name" => "project/" . $packageSlug,
            "description" => $appName . " built on FNLLA Core.",
            "type" => "project",
            "license" => "proprietary",
            "repositories" => [
                [
                    "type" => "path",
                    "url" => "packages/fnlla-core",
                    "options" => [
                        "symlink" => false,
                    ],
                ],
            ],
            "require" => [
                "php" => "^8.3",
                "techayodev/fnlla-core" => $this->versionConstraint(),
            ],
            "autoload" => [
                "psr-4" => [
                    "App\\" => "app/",
                    "Database\\Seeders\\" => "database/seeders/",
                    "Database\\Factories\\" => "database/factories/",
                ],
            ],
            "suggest" => [
                "phpunit/phpunit" => "Optional full PHPUnit runner. The Core export ships a dependency-light local smoke-test harness.",
                "phpstan/phpstan" => "Optional deeper static analysis. The Core export runs a dependency-light baseline without it.",
            ],
            "scripts" => [
                "console" => "@php fnlla",
                "test" => "@php scripts/test.php",
                "analyse" => "@php scripts/static-analysis.php",
                "lint" => "@php scripts/lint.php",
            ],
            "config" => [
                "sort-packages" => true,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private function copyDirectory(string $sourceRoot, string $targetRoot): void
    {
        $sourceRoot = rtrim(str_replace("\\", "/", $sourceRoot), "/");
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }

            $source = str_replace("\\", "/", $item->getPathname());
            $relative = ltrim(substr($source, strlen($sourceRoot)), "/");

            if (!$this->isSafeRelativePath($relative)) {
                throw new RuntimeException("Unsafe template path: " . $relative);
            }

            $this->write($targetRoot . "/" . $relative, (string) file_get_contents($item->getPathname()));
        }
    }

    private function replaceTokens(string $targetRoot, array $tokens): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }

            $path = $item->getPathname();
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (!in_array($extension, ["", "php", "md", "json", "neon", "xml", "css", "cmd", "example"], true)) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            $updated = strtr($contents, $tokens);

            if ($updated !== $contents) {
                $this->write($path, $updated);
            }
        }
    }

    private function writeRuntimePlaceholders(string $targetRoot): void
    {
        $placeholders = [
            "storage/.gitignore" => "# Runtime data is private, including files created by future modules.\n*\n!*/\n!.gitignore\n",
            "storage/app/.gitignore" => "*\n!.gitignore\n",
            "storage/logs/.gitignore" => "*\n!.gitignore\n",
            "storage/framework/cache/.gitignore" => "*\n!.gitignore\n",
            "storage/framework/sessions/.gitignore" => "*\n!.gitignore\n",
            "storage/framework/queue/.gitignore" => "*\n!.gitignore\n",
            "database/migrations/.gitignore" => "*\n!.gitignore\n",
            ".fnlla/project-profile" => "core\n",
        ];

        foreach ($placeholders as $relative => $contents) {
            $this->write($targetRoot . "/" . $relative, $contents);
        }
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create directory: " . $directory);
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Cannot write file: " . $path);
        }
    }

    private function cleanAppName(string $value): string
    {
        $value = trim(str_replace(["\r", "\n", '"'], ["", "", ""], $value));

        return $value !== "" ? $value : "FNLLA Core Project";
    }

    private function version(): string
    {
        $version = trim((string) strtok((string) file_get_contents($this->packageRoot() . "/VERSION"), "\r\n"));

        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            throw new RuntimeException("Invalid FNLLA Core version.");
        }

        return $version;
    }

    private function versionConstraint(): string
    {
        $parts = explode(".", $this->version());

        return "~" . $parts[0] . "." . $parts[1] . ".0";
    }

    private function packageRoot(): string
    {
        return str_replace("\\", "/", dirname(__DIR__, 2));
    }

    private function isSafeRelativePath(string $path): bool
    {
        return preg_match('#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#D', $path) === 1
            && !str_contains($path, "..")
            && !str_starts_with($path, ".git/")
            && !str_starts_with($path, "vendor/")
            && !str_starts_with($path, "node_modules/");
    }
}
