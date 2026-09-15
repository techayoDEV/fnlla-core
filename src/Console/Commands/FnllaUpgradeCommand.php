<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Support\FrameworkLock;
use Fnlla\Php\Support\FrameworkUpdateTransaction;
use Fnlla\Php\Support\ProjectProfile;
use JsonException;
use RuntimeException;

final class FnllaUpgradeCommand extends Command
{
    /** @var array<string, string> */
    private array $copySources = [];

    public function name(): string
    {
        return "fnlla:upgrade";
    }

    public function aliases(): array
    {
        return ["platform:upgrade"];
    }

    public function description(): string
    {
        return "Plan or apply a safe FNLLA Core to FNLLA upgrade.";
    }

    public function usage(): string
    {
        return "fnlla:upgrade --source <fnlla-source> [--project <path>] [--apply] [--json]";
    }

    public function handle(array $arguments): int
    {
        try {
            $options = $this->parseOptions($arguments);

            if ($options["help"]) {
                $this->printUsage();
                return 0;
            }

            $projectRoot = $this->resolveProjectRoot((string) ($options["project"] ?? base_path()));
            $sourceRoot = $this->resolveSourceRoot((string) ($options["source"] ?? ""));
            $report = $this->buildReport($projectRoot, $sourceRoot);

            if ($options["apply"] && $report["status"] === "upgrade-ready" && $report["conflicts"] === []) {
                $report = $this->applyReport($projectRoot, $sourceRoot, $report);
            }

            if ($options["json"]) {
                $this->line($this->json($report));
            } else {
                $this->renderReport($report, (bool) $options["apply"]);
            }

            return $report["conflicts"] === [] ? 0 : 1;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * @return array{apply:bool, help:bool, json:bool, project:?string, source:?string}
     */
    private function parseOptions(array $arguments): array
    {
        $options = ["apply" => false, "help" => false, "json" => false, "project" => null, "source" => null];

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = trim((string) $arguments[$index]);

            if ($argument === "") {
                continue;
            }

            if ($argument === "--apply") {
                $options["apply"] = true;
                continue;
            }

            if ($argument === "--dry-run" || $argument === "--check") {
                continue;
            }

            if ($argument === "--json") {
                $options["json"] = true;
                continue;
            }

            if ($argument === "--help" || $argument === "-h") {
                $options["help"] = true;
                continue;
            }

            foreach (["project", "source"] as $name) {
                if ($argument === "--" . $name || str_starts_with($argument, "--" . $name . "=")) {
                    if ($options[$name] !== null) {
                        throw new RuntimeException("Duplicate --" . $name . " option.");
                    }

                    $value = $argument === "--" . $name ? (string) ($arguments[++$index] ?? "") : substr($argument, strlen("--" . $name . "="));
                    if ($value === "" || str_starts_with($value, "--") || str_contains($value, "\0")) {
                        throw new RuntimeException("--" . $name . " requires a path.");
                    }
                    $options[$name] = $value;
                    continue 2;
                }
            }

            throw new RuntimeException("Unknown option for fnlla:upgrade: " . $argument);
        }

        return $options;
    }

    private function printUsage(): void
    {
        $this->line("Usage: php fnlla " . $this->usage());
        $this->line("Without --apply the command only reports the Core -> FNLLA upgrade plan.");
        $this->line("The source must be a reviewed FNLLA source checkout or release extraction with resources/project-templates/v1/export-files.json.");
    }

    private function resolveProjectRoot(string $path): string
    {
        $resolved = realpath($path);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException("Project directory does not exist: " . $path);
        }

        return rtrim($resolved, "\\/");
    }

    private function resolveSourceRoot(string $path): string
    {
        if ($path === "") {
            if (is_file(base_path("resources/project-templates/v1/export-files.json"))) {
                $path = base_path();
            } else {
                throw new RuntimeException("Provide --source pointing to a full FNLLA source checkout or release extraction.");
            }
        }

        $resolved = realpath($path);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException("FNLLA source does not exist: " . $path);
        }

        if (!is_file($resolved . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "export-files.json")) {
            throw new RuntimeException("FNLLA source is missing resources/project-templates/v1/export-files.json.");
        }

        return rtrim($resolved, "\\/");
    }

    private function buildReport(string $projectRoot, string $sourceRoot): array
    {
        $profile = ProjectProfile::name($projectRoot);
        $version = $this->readVersion($sourceRoot);

        if ($profile === "fnlla") {
            return [
                "schema" => "fnlla.upgrade.v1",
                "status" => "already-fnlla",
                "project_root" => $projectRoot,
                "source_root" => $sourceRoot,
                "source_version" => $version,
                "adds" => [],
                "updates" => [],
                "skips" => [],
                "conflicts" => [],
                "warnings" => [],
            ];
        }

        if ($profile !== "core") {
            throw new RuntimeException("Only FNLLA Core projects can be upgraded to FNLLA.");
        }

        $this->copySources = $this->fnllaCopySources($sourceRoot);
        $adds = [];
        $updates = [];
        $skips = [];
        $conflicts = [];

        foreach ($this->copySources as $relative => $sourcePath) {
            $targetPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relative);
            $sourceHash = hash_file("sha256", $sourcePath);
            $targetHash = is_file($targetPath) ? hash_file("sha256", $targetPath) : null;

            if ($targetHash === $sourceHash) {
                $skips[$relative] = "already matches FNLLA source";
                continue;
            }

            if ($targetHash !== null) {
                $conflicts[$relative] = "Target file exists with project-specific content; review before applying FNLLA.";
                continue;
            }

            $adds[$relative] = ["source_hash" => $sourceHash];
        }

        foreach ($this->managedReplacements($sourceRoot) as $relative => $replacement) {
            $targetPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relative);
            $currentHash = is_file($targetPath) ? hash_file("sha256", $targetPath) : null;
            $sourceHash = hash_file("sha256", $replacement["source"]);

            if ($currentHash === $sourceHash) {
                $skips[$relative] = "already FNLLA-ready";
                continue;
            }

            if (!$this->replacementAllowed($targetPath, $replacement["core_source"], (array) ($replacement["needles"] ?? []))) {
                $conflicts[$relative] = "Core bootstrap file was changed locally; merge this FNLLA wiring manually.";
                continue;
            }

            $updates[$relative] = ["source_hash" => $sourceHash, "current_hash" => $currentHash];
        }

        $configAppPath = $projectRoot . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "app.php";
        if (!$this->configAppCanRegisterFnlla($configAppPath)) {
            $conflicts["config/app.php"] = "Cannot find CoreServiceProvider in config/app.php; add FrameworkServiceProvider manually.";
        } else {
            $updates["config/app.php"] = ["source_hash" => null, "current_hash" => is_file($configAppPath) ? hash_file("sha256", $configAppPath) : null];
        }

        $composerPath = $projectRoot . DIRECTORY_SEPARATOR . "composer.json";
        if (!$this->composerCanRegisterFnlla($composerPath)) {
            $conflicts["composer.json"] = "composer.json is missing a JSON object or require block; add FNLLA package wiring manually.";
        } else {
            $updates["composer.json"] = ["source_hash" => null, "current_hash" => is_file($composerPath) ? hash_file("sha256", $composerPath) : null];
        }

        return [
            "schema" => "fnlla.upgrade.v1",
            "status" => $conflicts === [] ? "upgrade-ready" : "conflicts",
            "project_root" => $projectRoot,
            "source_root" => $sourceRoot,
            "source_version" => $version,
            "adds" => $adds,
            "updates" => $updates,
            "skips" => $skips,
            "conflicts" => $conflicts,
            "warnings" => [
                "Run composer update techayodev/fnlla-core techayodev/fnlla after applying the upgrade.",
                "Review .env.platform.example and copy only the settings your deployment needs into .env.",
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fnllaCopySources(string $sourceRoot): array
    {
        $manifest = json_decode(
            (string) file_get_contents($sourceRoot . DIRECTORY_SEPARATOR . "resources/project-templates/v1/export-files.json"),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($manifest) || ($manifest["schema"] ?? null) !== "fnlla.project_export.v1" || !is_array($manifest["files"] ?? null)) {
            throw new RuntimeException("Invalid FNLLA export manifest.");
        }

        $sources = [];

        foreach ($manifest["files"] as $relative) {
            if (!is_string($relative) || !$this->safeRelativePath($relative) || $this->isProductOwnedPath($relative)
                || array_key_exists($relative, $this->managedReplacements($sourceRoot))) {
                continue;
            }

            $sourcePath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relative);
            if (!is_file($sourcePath) || is_link($sourcePath)) {
                throw new RuntimeException("FNLLA source file is missing or aliased: " . $relative);
            }

            $sources[$relative] = $sourcePath;
        }

        ksort($sources);

        return $sources;
    }

    private function applyReport(string $projectRoot, string $sourceRoot, array $report): array
    {
        $paths = array_values(array_unique(array_merge(
            array_keys((array) $report["adds"]),
            array_keys((array) $report["updates"]),
            [".fnlla/project-profile", ".fnlla/core-to-fnlla-upgrade.json", FrameworkLock::lockFile()]
        )));

        $transaction = new FrameworkUpdateTransaction($projectRoot);
        $transaction->assertReady();

        return $transaction->run($paths, function () use ($projectRoot, $sourceRoot, $report): array {
            $applied = 0;

            foreach ((array) $report["adds"] as $relative => $_metadata) {
                $source = $this->copySources[$relative] ?? null;
                if (!is_string($source) || !is_file($source)) {
                    throw new RuntimeException("Planned FNLLA source disappeared: " . $relative);
                }
                $this->replace($projectRoot, (string) $relative, (string) file_get_contents($source));
                $applied++;
            }

            foreach ($this->managedReplacements($sourceRoot) as $relative => $replacement) {
                if (!isset($report["updates"][$relative])) {
                    continue;
                }
                $this->replace($projectRoot, $relative, (string) file_get_contents($replacement["source"]));
                $applied++;
            }

            $this->registerFnllaProvider($projectRoot . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "app.php");
            $this->registerFnllaComposer($projectRoot . DIRECTORY_SEPARATOR . "composer.json", $this->readVersion($sourceRoot));
            $this->replace($projectRoot, ".fnlla/project-profile", "fnlla\n");
            $this->replace($projectRoot, ".fnlla/core-to-fnlla-upgrade.json", $this->json(array_merge($report, [
                "status" => "applied",
                "applied_changes" => $applied,
                "applied_at_utc" => gmdate(DATE_ATOM),
            ])) . PHP_EOL);
            FrameworkLock::write($projectRoot, $sourceRoot, $this->projectName($projectRoot), $this->projectSlug($projectRoot));
            $this->clearBootstrapCaches($projectRoot);

            return array_merge($report, [
                "status" => "applied",
                "applied_changes" => $applied,
                "conflicts" => [],
            ]);
        });
    }

    /**
     * @return array<string, array{source:string, core_source:string, needles?:string[]}>
     */
    private function managedReplacements(string $sourceRoot): array
    {
        $coreTemplateRoot = $sourceRoot . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "core";

        return [
            "bootstrap/common.php" => [
                "source" => $sourceRoot . DIRECTORY_SEPARATOR . "bootstrap" . DIRECTORY_SEPARATOR . "common.php",
                "core_source" => $coreTemplateRoot . DIRECTORY_SEPARATOR . "bootstrap" . DIRECTORY_SEPARATOR . "common.php",
                "needles" => ["packages/fnlla-core/src"],
            ],
            "bootstrap/app.php" => [
                "source" => $sourceRoot . DIRECTORY_SEPARATOR . "bootstrap" . DIRECTORY_SEPARATOR . "app.php",
                "core_source" => $coreTemplateRoot . DIRECTORY_SEPARATOR . "bootstrap" . DIRECTORY_SEPARATOR . "app.php",
                "needles" => ["->middleware"],
            ],
            "bootstrap/router.php" => [
                "source" => $sourceRoot . DIRECTORY_SEPARATOR . "bootstrap" . DIRECTORY_SEPARATOR . "router.php",
                "core_source" => $coreTemplateRoot . DIRECTORY_SEPARATOR . "bootstrap" . DIRECTORY_SEPARATOR . "router.php",
                "needles" => ["routes/web.php"],
            ],
            "fnlla.cmd" => [
                "source" => $sourceRoot . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "fnlla.cmd",
                "core_source" => $sourceRoot . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "fnlla.cmd",
            ],
            "phpstan.neon" => [
                "source" => $sourceRoot . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "phpstan.neon",
                "core_source" => $coreTemplateRoot . DIRECTORY_SEPARATOR . "phpstan.neon",
                "needles" => ["packages/fnlla-core/src"],
            ],
        ];
    }

    private function replacementAllowed(string $targetPath, string $coreSource, array $needles): bool
    {
        if (!is_file($targetPath)) {
            return true;
        }

        if (is_file($coreSource) && hash_file("sha256", $targetPath) === hash_file("sha256", $coreSource)) {
            return true;
        }

        $contents = (string) file_get_contents($targetPath);

        foreach ($needles as $needle) {
            if ($needle !== "" && str_contains($contents, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function configAppCanRegisterFnlla(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        return str_contains($contents, "\\Fnlla\\Php\\Providers\\FrameworkServiceProvider::class")
            || str_contains($contents, "\\Fnlla\\Php\\Providers\\CoreServiceProvider::class");
    }

    private function composerCanRegisterFnlla(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        try {
            $metadata = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($metadata) && is_array($metadata["require"] ?? null);
    }

    private function registerFnllaProvider(string $path): void
    {
        $contents = (string) file_get_contents($path);

        if (!str_contains($contents, "\\Fnlla\\Php\\Providers\\FrameworkServiceProvider::class")) {
            $contents = str_replace(
                "\\Fnlla\\Php\\Providers\\CoreServiceProvider::class",
                "\\Fnlla\\Php\\Providers\\FrameworkServiceProvider::class",
                $contents
            );
        }

        FrameworkUpdateTransaction::replace($path, $contents);
    }

    private function registerFnllaComposer(string $path, string $version): void
    {
        $metadata = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($metadata)) {
            throw new RuntimeException("composer.json must decode to an object.");
        }

        $metadata["repositories"] = [["type" => "path", "url" => "packages/*", "options" => ["symlink" => false]]];
        $metadata["require"] = is_array($metadata["require"] ?? null) ? $metadata["require"] : [];
        $metadata["require"]["techayodev/fnlla-core"] = (string) ($metadata["require"]["techayodev/fnlla-core"] ?? $version);
        $metadata["require"]["techayodev/fnlla"] = $version;
        $metadata["autoload"] = is_array($metadata["autoload"] ?? null) ? $metadata["autoload"] : [];
        $metadata["autoload"]["psr-4"] = is_array($metadata["autoload"]["psr-4"] ?? null) ? $metadata["autoload"]["psr-4"] : [];
        $metadata["autoload"]["psr-4"]["Fnlla\\Php\\"] = ["src/", "packages/fnlla-core/src/", "packages/fnlla/src/"];
        $metadata["autoload"]["psr-4"]["App\\"] = (string) ($metadata["autoload"]["psr-4"]["App\\"] ?? "app/");

        FrameworkUpdateTransaction::replace($path, $this->json($metadata) . PHP_EOL);
    }

    private function renderReport(array $report, bool $apply): void
    {
        $this->line("FNLLA Core -> FNLLA upgrade");
        $this->line("Status: " . (string) $report["status"]);
        $this->line("Source version: " . (string) $report["source_version"]);
        $this->line("Additions: " . count((array) $report["adds"]));
        $this->line("Updates: " . count((array) $report["updates"]));
        $this->line("Conflicts: " . count((array) $report["conflicts"]));

        foreach ((array) $report["conflicts"] as $path => $reason) {
            $this->error("[CONFLICT] " . $path . " - " . $reason);
        }

        foreach ((array) $report["warnings"] as $warning) {
            $this->line("Note: " . (string) $warning);
        }

        if ($apply && isset($report["applied_changes"])) {
            $this->line("Applied changes: " . (string) $report["applied_changes"]);
        } elseif (!$apply && $report["conflicts"] === [] && $report["status"] === "upgrade-ready") {
            $this->line("Run again with --apply to install FNLLA files.");
        }
    }

    private function isProductOwnedPath(string $relative): bool
    {
        return $relative === ".env"
            || $relative === ".env.example"
            || $relative === "README.md"
            || $relative === "composer.json"
            || $relative === "phpstan.neon"
            || $relative === "bootstrap/common.php"
            || $relative === "bootstrap/app.php"
            || $relative === "bootstrap/router.php"
            || $relative === "fnlla"
            || $relative === "fnlla.cmd"
            || $relative === "config/app.php"
            || $relative === "routes/web.php"
            || $relative === "public/assets/app.css"
            || $relative === "database/seeders/DatabaseSeeder.php"
            || $relative === "phpunit.xml"
            || str_starts_with($relative, "app/")
            || str_starts_with($relative, "views/pages/")
            || $relative === "views/layouts/app.php"
            || str_starts_with($relative, "tests/");
    }

    private function safeRelativePath(string $path): bool
    {
        return FrameworkLock::isSafeRelativePath($path)
            && !str_starts_with($path, ".git/")
            && !str_starts_with($path, "storage/")
            && !str_starts_with($path, "public/uploads/");
    }

    private function replace(string $projectRoot, string $relative, string $contents): void
    {
        if (!$this->safeRelativePath($relative)) {
            throw new RuntimeException("Unsafe FNLLA upgrade path: " . $relative);
        }

        FrameworkUpdateTransaction::replace(
            $projectRoot . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relative),
            $contents
        );
    }

    private function clearBootstrapCaches(string $projectRoot): void
    {
        foreach (["storage/framework/cache/routes.php", "storage/framework/cache/bootstrap-config.php"] as $relative) {
            $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relative);
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException("Cannot clear stale bootstrap cache: " . $relative);
            }
        }
    }

    private function readVersion(string $sourceRoot): string
    {
        $version = trim((string) (file($sourceRoot . DIRECTORY_SEPARATOR . "VERSION", FILE_IGNORE_NEW_LINES)[0] ?? ""));

        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            throw new RuntimeException("FNLLA source VERSION is invalid.");
        }

        return $version;
    }

    private function projectName(string $projectRoot): string
    {
        $envExample = $projectRoot . DIRECTORY_SEPARATOR . ".env.example";
        if (is_file($envExample) && preg_match('/^APP_NAME=(.+)$/m', (string) file_get_contents($envExample), $match) === 1) {
            return trim((string) $match[1], "\"' \t\r\n") ?: basename($projectRoot);
        }

        return basename($projectRoot);
    }

    private function projectSlug(string $projectRoot): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', "-", $this->projectName($projectRoot)));
        $slug = trim($slug, "-");

        return $slug !== "" ? $slug : "fnlla-project";
    }

    private function json(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
