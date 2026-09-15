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

    public function __construct(private string $root)
    {
        $this->root = realpath($root) ?: throw new RuntimeException("Update root does not exist.");
        if (is_file($this->root . "/.fnlla-release.json")) {
            throw new RuntimeException("Immutable deployment: stage and activate a new release instead of updating in place.");
        }
        if (is_file($this->root . "/.fnlla/package-distribution")) {
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
        $entries = [];
        foreach (array_unique($paths) as $relative) {
            $path = $this->path($relative);
            $exists = is_file($path);
            $backup = hash("sha256", $relative) . ".backup";
            if ($exists) {
                self::replace($this->path(".fnlla/update-transaction/" . $backup), (string) file_get_contents($path));
            }
            $entries[$relative] = ["exists" => $exists, "backup" => $backup,
                "hash" => $exists ? hash_file("sha256", $path) : null,
                "mode" => $exists ? fileperms($path) & 0777 : null];
        }
        self::replace($this->directory . "/journal.json", json_encode([
            "schema" => "fnlla.update_transaction.v1", "entries" => $entries,
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
        if (($journal["schema"] ?? null) !== "fnlla.update_transaction.v1" || !is_array($journal["entries"] ?? null)) {
            throw new RuntimeException("Invalid update journal.");
        }
        // Validate every backup before restoring anything. A damaged journal must fail closed.
        foreach ($journal["entries"] as $relative => $entry) {
            $this->path($relative);
            if (!is_bool($entry["exists"] ?? null) || ($entry["backup"] ?? null) !== hash("sha256", $relative) . ".backup") {
                throw new RuntimeException("Invalid backup entry.");
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
                if (PHP_OS_FAMILY !== "Windows") {
                    chmod($path, $entry["mode"]);
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
                chmod($temporary, is_file($path) ? fileperms($path) & 0777 : 0666 & ~umask());
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
        if (preg_match('~^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$~D', $relative) !== 1
            || array_intersect([".", ".."], explode("/", $relative)) !== []
            || $this->isPrivateEnvironmentPath($relative) || str_starts_with($relative, "storage/")
            || str_starts_with($relative, "public/uploads/") || str_starts_with($relative, ".git/")) {
            throw new RuntimeException("Unsafe update transaction path.");
        }
        $path = $this->root;
        foreach (explode("/", $relative) as $part) {
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

    private function isPrivateEnvironmentPath(string $relative): bool
    {
        if (!str_starts_with($relative, ".env")) {
            return false;
        }

        return !in_array($relative, [".env.example", ".env.platform.example"], true);
    }
}
