<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Support\CoreProjectExporter;
use RuntimeException;

final class MakeProjectCommand extends Command
{
    public function name(): string
    {
        return "make:project";
    }

    public function description(): string
    {
        return "Create a minimal public FNLLA Core project.";
    }

    public function usage(): string
    {
        return "make:project <target-path> [App Name] [--profile=core]";
    }

    public function handle(array $arguments): int
    {
        if (in_array("--help", $arguments, true) || in_array("-h", $arguments, true)) {
            $this->printHelp();
            $this->line("Creates only the FNLLA Core mini project template.");

            return 0;
        }

        try {
            $arguments = $this->normalizeArguments($arguments);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        $targetArgument = trim((string) ($arguments[0] ?? ""));
        $appName = trim(implode(" ", array_slice($arguments, 1)));

        if ($targetArgument === "") {
            $this->error("Usage: php fnlla " . $this->usage());

            return 1;
        }

        $sourceRoot = $this->normalizePath(base_path());
        $targetRoot = $this->resolveTargetPath($targetArgument);

        if ($this->pathsEqual($sourceRoot, $targetRoot) || $this->isChildPath($targetRoot, $sourceRoot)) {
            $this->error("Target path must be outside the FNLLA Core source repository.");

            return 1;
        }

        $appName = $appName !== "" ? $appName : $this->guessAppName($targetRoot);
        $slug = $this->slugify($appName);

        if ($slug === "") {
            $this->error("Unable to derive a valid project slug.");

            return 1;
        }

        try {
            $this->prepareTargetDirectory($targetRoot);
            $this->container->make(CoreProjectExporter::class)->export($targetRoot, $appName, $slug);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        $this->line("Exported FNLLA Core project to: " . $targetRoot);
        $this->line("Application name: " . $appName);
        $this->line("Project profile: core");
        $this->line("");
        $this->line("Next steps:");
        $this->line("1. Open the new project directory.");
        $this->line("2. Copy .env.example to .env and adjust APP_URL/database settings.");
        $this->line("3. Run composer install, php scripts/test.php, php scripts/lint.php and php fnlla route:list.");
        $this->line("4. Start php -S 127.0.0.1:8080 -t public public/router.php using an available port.");

        return 0;
    }

    private function normalizeArguments(array $arguments): array
    {
        $normalized = [];

        for ($index = 0; $index < count($arguments); $index++) {
            $argument = (string) $arguments[$index];

            if ($argument === "--profile") {
                $profile = (string) ($arguments[$index + 1] ?? "");
                $index++;
                if ($profile !== "core") {
                    throw new RuntimeException("FNLLA Core can only create --profile=core projects.");
                }
                continue;
            }

            if (str_starts_with($argument, "--profile=")) {
                if (substr($argument, 10) !== "core") {
                    throw new RuntimeException("FNLLA Core can only create --profile=core projects.");
                }
                continue;
            }

            if ($argument === "--no-interaction" || $argument === "--interactive") {
                continue;
            }

            if (str_starts_with($argument, "--")) {
                throw new RuntimeException("Unsupported make:project option for FNLLA Core: " . $argument);
            }

            $normalized[] = $argument;
        }

        return $normalized;
    }

    private function prepareTargetDirectory(string $targetRoot): void
    {
        if (is_dir($targetRoot)) {
            $entries = scandir($targetRoot);

            if ($entries === false) {
                throw new RuntimeException("Unable to inspect target directory: " . $targetRoot);
            }

            if (array_values(array_diff($entries, [".", ".."])) !== []) {
                throw new RuntimeException("Target directory must be empty: " . $targetRoot);
            }

            return;
        }

        if (file_exists($targetRoot) && !is_dir($targetRoot)) {
            throw new RuntimeException("Target path already exists and is not a directory: " . $targetRoot);
        }

        if (!mkdir($targetRoot, 0755, true) && !is_dir($targetRoot)) {
            throw new RuntimeException("Unable to create target directory: " . $targetRoot);
        }
    }

    private function resolveTargetPath(string $targetArgument): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $targetArgument) === 1 || str_starts_with($targetArgument, "\\\\") || str_starts_with($targetArgument, "/")) {
            return $this->normalizePath($targetArgument);
        }

        return $this->normalizePath((string) getcwd() . DIRECTORY_SEPARATOR . $targetArgument);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $path);
        $segments = [];
        $prefix = "";

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            $prefix = strtoupper(substr($path, 0, 2));
            $path = substr($path, 2);
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;
            $path = substr($path, 2);
        }

        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $parts = preg_split('/[\\\\\\/]+/', $path) ?: [];

        foreach ($parts as $part) {
            if ($part === "" || $part === ".") {
                continue;
            }

            if ($part === "..") {
                if ($segments !== [] && end($segments) !== "..") {
                    array_pop($segments);
                } elseif (!$isAbsolute) {
                    $segments[] = $part;
                }

                continue;
            }

            $segments[] = $part;
        }

        $normalized = implode(DIRECTORY_SEPARATOR, $segments);

        if ($prefix !== "") {
            return $prefix . DIRECTORY_SEPARATOR . $normalized;
        }

        return ($isAbsolute ? DIRECTORY_SEPARATOR : "") . $normalized;
    }

    private function pathsEqual(string $left, string $right): bool
    {
        $left = rtrim($left, "\\/");
        $right = rtrim($right, "\\/");

        return DIRECTORY_SEPARATOR === "\\" ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private function isChildPath(string $childPath, string $parentPath): bool
    {
        $child = rtrim($childPath, "\\/");
        $parent = rtrim($parentPath, "\\/");

        if (DIRECTORY_SEPARATOR === "\\") {
            $child = strtolower($child);
            $parent = strtolower($parent);
        }

        return str_starts_with($child, $parent . DIRECTORY_SEPARATOR);
    }

    private function guessAppName(string $targetPath): string
    {
        $basename = basename(rtrim($targetPath, "\\/"));
        $basename = preg_replace('/[-_]+/', " ", $basename);

        return ucwords(trim((string) $basename));
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', "-", $value);

        return trim((string) $value, "-");
    }
}
