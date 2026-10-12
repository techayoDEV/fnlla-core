<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use RuntimeException;
use Throwable;

/** File transaction only: database migrations and live application data are never rolled back. */
final class FrameworkUpdateTransaction
{
    private string $directory;
    private $lock;

    public function __construct(private string $root, private string $operation = 'framework')
    {
        $this->root = realpath($root) ?: throw new RuntimeException("Update root does not exist.");
        if (is_file($this->root . "/.fnlla-release.json")) {
            throw new RuntimeException("Immutable deployment: stage and activate a new release instead of updating in place.");
        }
        if (!in_array($this->operation, ['framework', 'package-publication'], true)) {
            throw new RuntimeException('Unknown file transaction operation.');
        }
        if (is_file($this->root . "/.fnlla/package-distribution") && $this->operation !== 'package-publication') {
            throw new RuntimeException("Composer distribution: update the reviewed packages and composer.lock, not the legacy file tree.");
        }
        $this->directory = $this->path(".fnlla/update-transaction");
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true)) {
            throw new RuntimeException("Cannot create update transaction directory.");
        }
        $lockPath = $this->path(".fnlla/update-transaction/lock");
        // A browser-initiated update must release its own reader lease before upgrading.
        $reader = $GLOBALS["fnlla_update_read_lease"] ?? null;
        if (is_array($reader) && realpath((string) ($reader["root"] ?? "")) === $this->root
            && is_resource($reader["stream"] ?? null)) {
            fclose($reader["stream"]);
            unset($GLOBALS["fnlla_update_read_lease"]);
        }
        $this->lock = fopen($lockPath, "c+b");
        if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException("Another framework update or recovery is running.");
        }
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }

    public function assertReady(): void
    {
        if (is_file($this->directory . "/journal.json")) {
            throw new RuntimeException("An interrupted update needs recovery. Run php scripts/rollback-framework-update.php.");
        }
    }

    public function run(array $paths, callable $install): mixed
    {
        $this->assertReady();
        // Standalone recovery must survive a broken application bootstrap/autoloader.
        $component = file_get_contents(__FILE__);
        if ($component === false) { throw new RuntimeException('Cannot retain the recovery component.'); }
        self::replace($this->directory . '/recovery.php', $component);
        self::replace($this->directory . '/recovery.json', json_encode(['sha256' => hash('sha256', $component)], JSON_THROW_ON_ERROR));
        $entries = [];
        foreach (array_unique($paths) as $relative) {
            $this->assertPayloadPath($relative);
            $path = $this->path($relative);
            if (file_exists($path) && !is_file($path)) {
                throw new RuntimeException("Update target is not a regular file.");
            }
            $exists = is_file($path);
            $backup = hash("sha256", $relative) . ".backup";
            if ($exists) {
                $contents = file_get_contents($path);
                if ($contents === false) { throw new RuntimeException("Cannot read update target for backup."); }
                self::replace($this->path(".fnlla/update-transaction/" . $backup), $contents);
            }
            $entries[$relative] = ["exists" => $exists, "backup" => $backup,
                "hash" => $exists ? hash_file("sha256", $path) : null,
                "mode" => $exists ? fileperms($path) & 0777 : null];
        }
        self::replace($this->directory . "/journal.json", json_encode([
            "schema" => "fnlla.update_transaction.v1", 'operation' => $this->operation, "entries" => $entries,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        try {
            $result = $install();
        } catch (Throwable $error) {
            try {
                $this->recover();
            } catch (Throwable $recoveryError) {
                throw new RuntimeException("Update failed; automatic rollback failed. Keep the journal and backups for recovery. " . $recoveryError->getMessage(), 0, $error);
            }
            throw new RuntimeException("Update failed and previous framework files were restored: " . $error->getMessage(), 0, $error);
        }
        // Removing the journal is the commit point; orphaned backup files are inert.
        if (!unlink($this->directory . "/journal.json")) {
            throw new RuntimeException("Cannot commit update journal; recovery is required.");
        }
        $this->cleanBackups($entries);
        return $result;
    }

    public function recover(): void
    {
        $journalPath = $this->path(".fnlla/update-transaction/journal.json");
        if (!is_file($journalPath)) {
            throw new RuntimeException("No interrupted update to recover.");
        }
        $journal = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        if (($journal["schema"] ?? null) !== "fnlla.update_transaction.v1" || !is_array($journal["entries"] ?? null)
            || (array_key_exists('operation', $journal) ? $journal['operation'] : 'framework') !== $this->operation) {
            throw new RuntimeException("Invalid update journal.");
        }
        // Validate every backup before restoring anything. A damaged journal must fail closed.
        foreach ($journal["entries"] as $relative => $entry) {
            $this->assertPayloadPath($relative);
            $this->path($relative);
            if (!is_array($entry) || !is_bool($entry["exists"] ?? null)
                || ($entry["backup"] ?? null) !== hash("sha256", $relative) . ".backup"
                || ($entry["exists"] && (!is_string($entry["hash"] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $entry["hash"]) !== 1
                    || !is_int($entry["mode"] ?? null) || $entry["mode"] < 0 || $entry["mode"] > 0777))
                || (!$entry["exists"] && (($entry["hash"] ?? null) !== null || ($entry["mode"] ?? null) !== null))) {
                throw new RuntimeException("Invalid backup entry.");
            }
            $target = $this->path($relative);
            if (file_exists($target) && !is_file($target)) {
                throw new RuntimeException("Recovery target is not a regular file.");
            }
            $backup = $this->path(".fnlla/update-transaction/" . $entry["backup"]);
            if ($entry["exists"] && (!is_file($backup) || hash_file("sha256", $backup) !== $entry["hash"])) {
                throw new RuntimeException("Backup checksum mismatch: " . $relative);
            }
        }
        foreach (array_reverse($journal["entries"], true) as $relative => $entry) {
            $path = $this->path($relative);
            if ($entry["exists"]) {
                self::replace($path, (string) file_get_contents($this->directory . "/" . $entry["backup"]));
                if (PHP_OS_FAMILY !== "Windows" && !chmod($path, $entry["mode"])) {
                    throw new RuntimeException("Cannot restore update file permissions.");
                }
            } elseif (is_file($path) && !unlink($path)) {
                throw new RuntimeException("Cannot remove newly installed file: " . $relative);
            }
        }
        if (!unlink($journalPath)) {
            throw new RuntimeException("Cannot finalize rollback journal.");
        }
        $this->cleanBackups($journal["entries"]);
    }

    public static function replace(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException("Cannot create update directory.");
        }
        $temporary = tempnam($directory, ".fnlla-");
        if ($temporary === false) {
            throw new RuntimeException("Cannot stage update file.");
        }
        try {
            $stream = fopen($temporary, "wb");
            if ($stream === false) {
                throw new RuntimeException("Cannot open staged update file.");
            }
            try {
                if (fwrite($stream, $contents) !== strlen($contents) || !fflush($stream) || !fsync($stream)) {
                    throw new RuntimeException("Cannot persist staged update file.");
                }
            } finally {
                fclose($stream);
            }
            if (PHP_OS_FAMILY !== "Windows") {
                if (!chmod($temporary, is_file($path) ? fileperms($path) & 0777 : 0666 & ~umask())) {
                    throw new RuntimeException("Cannot preserve update file permissions.");
                }
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException("Cannot replace update file.");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function path(string $relative): string
    {
        // Apply a portable policy before resolving: Windows aliases case and trailing dots.
        $portable = strtolower($relative);
        if (preg_match('~^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$~D', $relative) !== 1
            || array_intersect([".", ".."], explode("/", $relative)) !== []
            || $this->isPrivateEnvironmentPath($portable) || $portable === 'storage'
            || str_starts_with($portable, "storage/") || $portable === 'public/uploads'
            || str_starts_with($portable, "public/uploads/") || $portable === '.git'
            || str_starts_with($portable, ".git/")) {
            throw new RuntimeException("Unsafe update transaction path.");
        }
        $path = $this->root;
        foreach (explode("/", $relative) as $part) {
            if (str_ends_with($part, '.')
                || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/iD', $part) === 1) {
                throw new RuntimeException('Non-portable update transaction path.');
            }
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) {
                throw new RuntimeException("Symlinks are not allowed in update paths.");
            }
            if (file_exists($path)) {
                $resolved = realpath($path);
                $prefix = $this->root . DIRECTORY_SEPARATOR;
                if ($resolved === false || (PHP_OS_FAMILY === "Windows"
                    ? !str_starts_with(strtolower($resolved), strtolower($prefix))
                    : !str_starts_with($resolved, $prefix))) {
                    throw new RuntimeException("Update path leaves the project.");
                }
            }
        }
        return $path;
    }

    private function cleanBackups(array $entries): void
    {
        foreach ($entries as $entry) {
            $path = $this->path(".fnlla/update-transaction/" . $entry["backup"]);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Recovery metadata is never a framework payload, even in a damaged journal. */
    private function assertPayloadPath(mixed $relative): void
    {
        if (!is_string($relative) || strtolower($relative) === '.fnlla/update-transaction'
            || str_starts_with(strtolower($relative), '.fnlla/update-transaction/')) {
            throw new RuntimeException("Update payload cannot modify transaction metadata.");
        }
        if ($this->operation === 'package-publication'
            && $relative !== '.fnlla/package-publication.json'
            && !str_starts_with($relative, 'config/') && !str_starts_with($relative, 'routes/')
            && !str_starts_with($relative, 'views/') && !str_starts_with($relative, 'public/assets/')
            && !str_starts_with($relative, 'public/vendor/fnlla-runtime/')) {
            throw new RuntimeException('Package publication cannot change application code, dependencies or private data.');
        }
    }

    private function isPrivateEnvironmentPath(string $relative): bool
    {
        if (!str_starts_with($relative, ".env")) {
            return false;
        }

        return !in_array($relative, [".env.example", ".env.platform.example"], true);
    }
}
