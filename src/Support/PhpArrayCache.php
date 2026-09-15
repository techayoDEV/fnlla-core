<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use RuntimeException;

final class PhpArrayCache
{
    public static function write(string $path, array $payload): void
    {
        self::validate($payload);
        $contents = "<?php\n\nreturn " . var_export($payload, true) . ";\n";
        $directory = dirname($path);
        if (is_link($path) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException("Cannot create bootstrap cache destination.");
        }
        $temporary = tempnam($directory, ".bootstrap-");
        if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)) {
            if (is_string($temporary)) { unlink($temporary); }
            throw new RuntimeException("Cannot stage bootstrap cache in its destination directory.");
        }
        try {
            $stream = fopen($temporary, "wb");
            if ($stream === false) { throw new RuntimeException("Cannot open staged bootstrap cache."); }
            try {
                if (fwrite($stream, $contents) !== strlen($contents) || !fflush($stream) || !fsync($stream)) {
                    throw new RuntimeException("Cannot persist bootstrap cache.");
                }
            } finally {
                fclose($stream);
            }
            // Keep the old cache available until a complete, valid replacement is durable.
            $published = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                if (@rename($temporary, $path)) { $published = true; break; }
                if (PHP_OS_FAMILY !== "Windows") { break; }
                // Windows readers may briefly deny replacement; never unlink the live file.
                usleep(10000);
            }
            if (!$published) { throw new RuntimeException("Cannot publish bootstrap cache; previous cache preserved."); }
            clearstatcache(true, $path);
            if (function_exists("opcache_invalidate")) { opcache_invalidate($path, true); }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    public static function routes(string $path, string $profile): ?array
    {
        if (!is_file($path)) { return null; }
        $cached = require $path;
        if (!is_array($cached)) { throw new RuntimeException("Cached route file must return an array."); }
        if (($cached["schema"] ?? null) === "fnlla.routes.v1") {
            if (($cached["profile"] ?? null) !== $profile) { return null; }
            if (!is_array($cached["routes"] ?? null)) { throw new RuntimeException("Invalid cached route payload."); }
            return $cached["routes"];
        }
        // Read pre-profile-cache exports, but new publications keep data and profile together.
        $legacyProfile = is_file($path . ".profile") ? trim((string) file_get_contents($path . ".profile")) : "fnlla";
        return $legacyProfile === $profile ? $cached : null;
    }

    private static function validate(mixed $value, int $depth = 0): void
    {
        if ($depth > 64) { throw new RuntimeException("Bootstrap cache exceeds maximum nesting; recursive configuration is not supported."); }
        if (is_array($value)) {
            foreach ($value as $item) { self::validate($item, $depth + 1); }
        } elseif ($value !== null && (!is_scalar($value) || (is_float($value) && !is_finite($value)))) {
            throw new RuntimeException("Bootstrap cache supports only finite scalar values, null and arrays.");
        }
    }
}
