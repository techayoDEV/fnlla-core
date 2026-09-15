<?php

declare(strict_types=1);

namespace Fnlla\Php\Console;

use InvalidArgumentException;
use RuntimeException;

abstract class GeneratorCommand extends Command
{
    protected const KIND = "";

    public function name(): string { return "make:" . static::KIND; }
    public function description(): string { return "Create an application " . static::KIND . " without overwriting existing files."; }
    public function usage(): string { return $this->name() . " <name> [--help]"; }

    public function handle(array $arguments): int
    {
        $input = Input::parse($arguments, [], 1);
        if ($input->option("help", false)) { $this->printHelp(); return 0; }
        $name = trim((string) ($input->arguments[0] ?? ""));
        if ($name === "") { throw new InvalidArgumentException("Usage: php fnlla " . $this->usage()); }
        if (static::KIND === "migration") {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/D', $name) !== 1) {
                throw new InvalidArgumentException("Migration name must contain only letters, digits, underscores and hyphens.");
            }
            $path = "database/migrations/" . gmdate("YmdHis") . "_" . strtolower(str_replace("-", "_", $name)) . ".php";
            $replacements = [];
        } else {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D', $name) !== 1) {
                throw new InvalidArgumentException("Class name must be a single PHP identifier, not a path or namespace.");
            }
            $suffix = ucfirst(static::KIND);
            $class = str_ends_with($name, $suffix) ? $name : $name . $suffix;
            if (strcasecmp($class, $suffix) === 0) { throw new InvalidArgumentException("Provide a descriptive class name before " . $suffix . "."); }
            [$namespace, $directory] = $this->destination();
            $short = substr($class, 0, -strlen($suffix));
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
            $path = $directory . "/" . $class . ".php";
            $replacements = ["{{namespace}}" => $namespace, "{{class}}" => $class,
                "{{command}}" => str_replace("_", ":", $snake), "{{table}}" => $snake . "s"];
        }
        $template = __DIR__ . "/stubs/" . static::KIND . ".stub";
        $contents = file_get_contents($template);
        if ($contents === false) { throw new RuntimeException("Generator template is missing."); }
        $this->writeNew($path, strtr($contents, $replacements));
        $this->line("Created " . static::KIND . ": " . base_path($path));
        return 0;
    }

    private function destination(): array
    {
        if (static::KIND === "factory") { return ["Database\\Factories", "database/factories"]; }
        if (static::KIND === "seeder") { return ["Database\\Seeders", "database/seeders"]; }
        $composer = json_decode((string) file_get_contents(base_path("composer.json")), true, 512, JSON_THROW_ON_ERROR);
        $appPath = $composer["autoload"]["psr-4"]["App\\"] ?? null;
        if (is_array($appPath)) { $appPath = $appPath[0] ?? null; }
        $app = is_string($appPath) && $appPath !== "";
        $subdirectory = match (static::KIND) { "controller" => "Controllers", "middleware" => "Middleware", "command" => "Console/Commands", default => throw new RuntimeException("Unknown generator.") };
        return [($app ? "App\\" : "Fnlla\\Php\\") . str_replace("/", "\\", $subdirectory),
            ($app ? rtrim(str_replace("\\", "/", $appPath), "/") : "src") . "/" . $subdirectory];
    }

    private function writeNew(string $relative, string $contents): void
    {
        if (preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*$#D', $relative) !== 1
            || array_intersect([".", ".."], explode("/", $relative)) !== []
            || preg_match('#^(vendor|packages|public)/#i', $relative) === 1) {
            throw new RuntimeException("Generator destination must be application-owned and inside the project.");
        }
        $root = realpath(base_path());
        if ($root === false) { throw new RuntimeException("Project root is missing."); }
        $path = $root;
        foreach (explode("/", $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) { throw new RuntimeException("Generator destination cannot contain symbolic links."); }
            if (file_exists($path)) {
                $resolved = realpath($path);
                $prefix = $root . DIRECTORY_SEPARATOR;
                if ($resolved === false || !str_starts_with(
                    PHP_OS_FAMILY === "Windows" ? strtolower($resolved) : $resolved,
                    PHP_OS_FAMILY === "Windows" ? strtolower($prefix) : $prefix
                )) {
                    throw new RuntimeException("Generator destination leaves the project.");
                }
            }
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            throw new RuntimeException("Cannot create generator directory.");
        }
        $stream = @fopen($path, "x+b");
        if ($stream === false) { throw new RuntimeException("Destination already exists or cannot be created: " . $relative); }
        $complete = false;
        try {
            if (fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
                throw new RuntimeException("Generated file could not be written completely: " . $relative);
            }
            $complete = true;
        } finally {
            fclose($stream);
            if (!$complete) { @unlink($path); }
        }
    }
}
