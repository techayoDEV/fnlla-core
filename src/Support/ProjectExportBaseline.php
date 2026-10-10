<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use RuntimeException;

/** Generator-owned receipt; never refresh it when updating an existing application. */
final class ProjectExportBaseline
{
    public const PATH = '.fnlla/export-baseline.json';

    /** @param list<string> $paths */
    public static function record(string $root, array $paths): void
    {
        $receipt = self::file($root, self::PATH);
        $files = [];
        if (is_file($receipt)) {
            if (filesize($receipt) > 1048576) { throw new RuntimeException('Export baseline exceeds its size limit.'); }
            $existing = json_decode((string) file_get_contents($receipt), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($existing) || ($existing['schema'] ?? null) !== 'fnlla.export_baseline.v1' || !is_array($existing['files'] ?? null)) {
                throw new RuntimeException('Invalid export baseline.');
            }
            $files = $existing['files'];
        }
        foreach ($paths as $relative) {
            self::assertPath($relative);
            $path = self::file($root, $relative);
            if (!is_file($path)) { throw new RuntimeException('Export baseline file is missing.'); }
            $files[$relative] = self::hashFile($relative, $path);
        }
        ksort($files);
        $path = self::file($root, self::PATH);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create export baseline directory.');
        }
        if (file_put_contents($path, json_encode(['schema' => 'fnlla.export_baseline.v1', 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write export baseline.');
        }
    }

    public static function matches(string $root, string $relative): bool
    {
        self::assertPath($relative);
        $receipt = self::file($root, self::PATH);
        if (!is_file($receipt)) { return false; }
        if (filesize($receipt) > 1048576) { throw new RuntimeException('Export baseline exceeds its size limit.'); }
        $data = json_decode((string) file_get_contents($receipt), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 'fnlla.export_baseline.v1' || !is_array($data['files'] ?? null)) {
            throw new RuntimeException('Invalid export baseline.');
        }
        $expected = $data['files'][$relative] ?? null;
        if (!is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) { return false; }
        $path = self::file($root, $relative);
        return is_file($path) && hash_equals($expected, self::hashFile($relative, $path));
    }

    public static function equivalent(string $relative, string $first, string $second): bool
    {
        return hash_equals(self::hash($relative, $first), self::hash($relative, $second));
    }

    public static function equivalentFiles(string $relative, string $first, string $second): bool
    {
        self::assertPath($relative);
        return hash_equals(self::hashFile($relative, $first), self::hashFile($relative, $second));
    }

    private static function hashFile(string $relative, string $path): string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot read export baseline file.'); }
        $hash = hash_init('sha256');
        $normalize = self::isText($relative);
        $carry = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) { throw new RuntimeException('Cannot read export baseline file.'); }
                if ($normalize) {
                    $chunk = $carry . $chunk;
                    $carry = str_ends_with($chunk, "\r") ? "\r" : '';
                    if ($carry !== '') { $chunk = substr($chunk, 0, -1); }
                    $chunk = str_replace("\r\n", "\n", $chunk);
                }
                hash_update($hash, $chunk);
            }
            hash_update($hash, $carry);
            return hash_final($hash);
        } finally { fclose($stream); }
    }

    private static function hash(string $relative, string $contents): string
    {
        // Only declared text files receive EOL normalization; binary data stays exact.
        if (self::isText($relative)) {
            $contents = str_replace("\r\n", "\n", $contents);
        }
        return hash('sha256', $contents);
    }

    private static function isText(string $relative): bool
    {
        return in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['php', 'json', 'md', 'neon', 'cmd', 'css', 'js', 'xml', 'example'], true)
            || in_array(basename($relative), ['.gitignore', '.gitattributes', '.editorconfig'], true);
    }

    private static function assertPath(string $relative): void
    {
        if ($relative === '' || str_contains($relative, '\\') || preg_match('~[\x00-\x1f:]|^/|(?:^|/)\.{1,2}(?:/|$)|//~', $relative)) {
            throw new RuntimeException('Unsafe export baseline path.');
        }
    }

    private static function file(string $root, string $relative): string
    {
        $path = rtrim($root, '/\\');
        if (is_link($path)) { throw new RuntimeException('Export baseline cannot use symbolic links.'); }
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) { throw new RuntimeException('Export baseline cannot use symbolic links.'); }
        }
        return $path;
    }
}
