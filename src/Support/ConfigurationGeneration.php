<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use RuntimeException;

/** Process-local fence; deployment still drains writers before changing inputs. */
final class ConfigurationGeneration
{
    private static ?array $generation = null;

    public static function capture(): void
    {
        // Never allow a stale worker to adopt a new generation in place.
        if (self::$generation !== null) { self::assertCurrent(); return; }
        self::$generation = self::snapshot();
    }

    public static function assertCurrent(): void
    {
        if (self::$generation !== null && self::$generation !== self::snapshot()) {
            throw new RuntimeException('Configuration generation changed. Drain and restart this process before continuing.');
        }
    }

    private static function snapshot(): array
    {
        $paths = [env_file_path(), framework_config_cache_path(), base_path('composer.lock')];
        $directories = [base_path('config')];
        if (defined('FNLLA_CONFIG_DEFAULTS_ROOT')) { $directories[] = FNLLA_CONFIG_DEFAULTS_ROOT; }
        $state = [];
        foreach ($directories as $directory) {
            if (!is_string($directory)) {
                throw new RuntimeException('Configuration generation directory is unavailable.');
            }
            clearstatcache(true, $directory);
            if (!file_exists($directory) && !is_link($directory)) {
                // The public loader supports config-free minimal consumers.
                // Watch absence too: adding the directory changes generation.
                $state['directory:' . $directory] = null;
                continue;
            }
            if (!is_dir($directory) || !is_readable($directory)) {
                throw new RuntimeException('Configuration generation directory is unavailable.');
            }
            $state['directory:' . $directory] = 'present';
            $files = glob(rtrim($directory, '\\/') . '/*.php');
            if ($files === false) { throw new RuntimeException('Cannot enumerate configuration generation.'); }
            $paths = array_merge($paths, $files);
        }
        foreach (array_unique($paths) as $path) {
            clearstatcache(true, $path);
            if (is_link($path)) { throw new RuntimeException('Linked configuration generation input is forbidden.'); }
            if (!file_exists($path)) { $state[$path] = null; continue; }
            if (!is_file($path) || !is_readable($path)) { throw new RuntimeException('Configuration generation input is unavailable.'); }
            $hash = hash_file('sha256', $path);
            if ($hash === false) { throw new RuntimeException('Cannot read configuration generation input.'); }
            $state[$path] = $hash;
        }
        ksort($state);
        return $state;
    }
}
