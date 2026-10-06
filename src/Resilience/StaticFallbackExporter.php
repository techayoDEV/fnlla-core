<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Fnlla\Php\Cache\FileCacheStore;

/** Export already approved anonymous LKG, never internally request private routes. */
final class StaticFallbackExporter
{
    public function __construct(private PublicPageCache $pages) {}

    public function export(array $paths, string $directory): array
    {
        // Reuse the cache directory's validation and atomic write conventions.
        new FileCacheStore($directory);
        $root = realpath($directory);
        if ($root === false) { throw new \RuntimeException('Fallback directory unavailable.'); }
        $manifest = [];
        foreach ($paths as $path) {
            if (!is_string($path) || preg_match('~^/(?:[A-Za-z0-9_-]+/?)*$~D', $path) !== 1) {
                throw new \InvalidArgumentException('Invalid public fallback path.');
            }
            $entry = $this->pages->snapshot($path);
            if ($entry === null) { throw new \RuntimeException('No valid approved public snapshot for fallback.'); }
            $name = hash('sha256', $path) . '.html';
            $this->write($root, $name, $entry['body']);
            $manifest[$path] = ['file' => $name, 'stored_at' => $entry['stored_at'], 'sha256' => hash('sha256', $entry['body'])];
        }
        $this->write($root, '503.html', self::emergency());
        $this->write($root, 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $manifest;
    }

    public static function emergency(string $message = 'The service is temporarily unavailable. Please try again shortly.'): string
    {
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Service unavailable</title><body><main><h1>We will be back shortly</h1><p>' . $message . '</p></main></body></html>';
    }

    private function write(string $root, string $name, string $content): void
    {
        $target = $root . '/' . $name;
        if (is_link($target)) { throw new \RuntimeException('Unsafe fallback destination.'); }
        $temporary = @tempnam($root, '.fallback-');
        if ($temporary === false) { throw new \RuntimeException('Cannot stage fallback.'); }
        try {
            if (realpath(dirname($temporary)) !== $root || @file_put_contents($temporary, $content) !== strlen($content)
                || !@rename($temporary, $target)) { throw new \RuntimeException('Cannot persist fallback.'); }
        } finally { if (is_file($temporary)) { @unlink($temporary); } }
    }
}
