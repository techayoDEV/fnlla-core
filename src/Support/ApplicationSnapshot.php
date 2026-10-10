<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use RuntimeException;

/** Private, durable application file snapshot. The caller must hold the update writer lock. */
final class ApplicationSnapshot
{
    private string $root;
    private string $directory;

    public function __construct(string $root)
    {
        $this->root = realpath($root) ?: throw new RuntimeException('Snapshot root does not exist.');
        $this->directory = $this->path('.fnlla/application-update');
        self::directory($this->directory);
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($this->directory, 0700)) {
            throw new RuntimeException('Cannot protect private application snapshots.');
        }
    }

    public function active(): ?array
    {
        $file = $this->directory . '/active.json';
        if (!is_file($file)) { return null; }
        $state = self::read($file);
        if (($state['schema'] ?? '') !== 'fnlla.application_snapshot.v1'
            || ($state['root'] ?? '') !== $this->root
            || !is_string($state['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['id']) !== 1
            || !in_array($state['phase'] ?? '', ['quiescing', 'capturing', 'installing', 'restoring', 'committed', 'restored'], true)) {
            throw new RuntimeException('Invalid application recovery state; traffic must remain stopped.');
        }
        return $state;
    }

    public function begin(array $exclusions = [], array $recoveryPlan = []): string
    {
        if ($this->active() !== null) { throw new RuntimeException('Application recovery is required before another update.'); }
        $exclusions = array_values(array_unique(array_merge(['.fnlla/application-update', '.fnlla/update-transaction'], $exclusions)));
        foreach ($exclusions as $relative) { $this->path($relative); }
        $id = bin2hex(random_bytes(16));
        $directory = $this->directory . '/snapshots/' . $id;
        self::directory($directory);
        self::write($directory . '/scope.json', ['exclusions' => $exclusions]);
        self::write($directory . '/plan.json', $recoveryPlan);
        self::write($this->directory . '/active.json', ['schema' => 'fnlla.application_snapshot.v1',
            'id' => $id, 'root' => $this->root, 'phase' => 'quiescing', 'created_at' => gmdate('c')]);
        return $directory;
    }

    public function directoryPath(): string
    {
        $state = $this->active() ?? throw new RuntimeException('No active application snapshot.');
        return $this->path('.fnlla/application-update/snapshots/' . $state['id']);
    }

    public function phase(string $phase): void
    {
        $state = $this->active() ?? throw new RuntimeException('No active application snapshot.');
        $allowed = ['quiescing' => ['capturing'], 'capturing' => ['installing'],
            'installing' => ['restoring', 'committed'], 'restoring' => ['restored'],
            'committed' => [], 'restored' => []];
        if (!in_array($phase, $allowed[$state['phase']], true)) { throw new RuntimeException('Unsafe snapshot state transition.'); }
        if ($phase === 'installing') {
            $state['metadata_hashes'] = [];
            foreach (['scope.json', 'files.json', 'plan.json', 'database.json'] as $name) {
                $path = $this->directoryPath() . '/' . $name;
                if ($name === 'database.json' && !is_file($path)) { continue; }
                self::read($path);
                $state['metadata_hashes'][$name] = hash_file('sha256', $path);
            }
        }
        $state['phase'] = $phase;
        self::write($this->directoryPath() . '/status.json', $state);
        // active.json is the only authoritative phase; commit it last.
        self::write($this->directory . '/active.json', $state);
    }

    public function captureFiles(): void
    {
        if (($this->active()['phase'] ?? '') !== 'capturing') { throw new RuntimeException('Snapshot capture requires quiescence.'); }
        $directory = $this->directoryPath();
        $entries = $this->inventory($this->exclusions());
        foreach ($entries as $relative => &$entry) {
            if ($entry['type'] !== 'file') { continue; }
            $source = $this->path($relative);
            $backup = $this->path('.fnlla/application-update/snapshots/' . $this->active()['id'] . '/files/' . hash('sha256', $relative));
            self::copy($source, $backup, 0600);
            $entry['hash'] = hash_file('sha256', $backup);
            if ($entry['hash'] !== hash_file('sha256', $source)) { throw new RuntimeException('A file changed during snapshot capture.'); }
        }
        unset($entry);
        self::write($directory . '/files.json', ['schema' => 'fnlla.application_files.v1', 'entries' => $entries]);
        $this->verifyFiles();
    }

    public function verifyFiles(): array
    {
        $this->verifyMetadata();
        $directory = $this->directoryPath();
        $manifest = self::read($directory . '/files.json');
        if (($manifest['schema'] ?? '') !== 'fnlla.application_files.v1' || !is_array($manifest['entries'] ?? null)) {
            throw new RuntimeException('Invalid application file snapshot.');
        }
        $excluded = $this->exclusions();
        foreach ($manifest['entries'] as $relative => $entry) {
            $this->path($relative);
            if ($this->excluded($relative, $excluded) || !in_array($entry['type'] ?? '', ['file', 'directory'], true)
                || !is_int($entry['mode'] ?? null) || $entry['mode'] < 0 || $entry['mode'] > 0777) {
                throw new RuntimeException('Unsafe application snapshot entry.');
            }
            if ($entry['type'] === 'file') {
                $backup = $this->path('.fnlla/application-update/snapshots/' . $this->active()['id'] . '/files/' . hash('sha256', $relative));
                if (is_link($backup) || !is_file($backup) || hash_file('sha256', $backup) !== ($entry['hash'] ?? null)) {
                    throw new RuntimeException('Application snapshot checksum mismatch.');
                }
            }
        }
        return $manifest['entries'];
    }

    public function verifyMetadata(): void
    {
        $state = $this->active() ?? throw new RuntimeException('No active application snapshot.');
        if (in_array($state['phase'], ['quiescing', 'capturing'], true)) { return; }
        $hashes = $state['metadata_hashes'] ?? null;
        if (!is_array($hashes) || array_diff(['scope.json', 'files.json', 'plan.json'], array_keys($hashes)) !== []) {
            throw new RuntimeException('Snapshot metadata seal is missing.');
        }
        foreach ($hashes as $name => $hash) {
            if (!in_array($name, ['scope.json', 'files.json', 'plan.json', 'database.json'], true)) {
                throw new RuntimeException('Invalid snapshot metadata seal.');
            }
            $path = $this->directoryPath() . '/' . $name;
            self::read($path);
            if (hash_file('sha256', $path) !== $hash) { throw new RuntimeException('Snapshot metadata checksum mismatch.'); }
        }
    }

    public function restoreFiles(): void
    {
        if (($this->active()['phase'] ?? '') !== 'restoring') { throw new RuntimeException('Restore forbidden after application commit.'); }
        $entries = $this->verifyFiles();
        $current = $this->inventory($this->exclusions()); // validates all paths before any mutation
        foreach (array_reverse($current, true) as $relative => $entry) {
            if (isset($entries[$relative]) && $entries[$relative]['type'] === $entry['type']) { continue; }
            $path = $this->path($relative);
            if (!($entry['type'] === 'file' ? unlink($path) : rmdir($path))) {
                throw new RuntimeException('Cannot remove post-snapshot application entry.');
            }
        }
        foreach ($entries as $relative => $entry) {
            $path = $this->path($relative);
            if ($entry['type'] === 'directory') { self::directory($path); }
            else { self::copy($this->directoryPath() . '/files/' . hash('sha256', $relative), $path, $entry['mode']); }
        }
        // Apply directory permissions after restoring descendants.
        if (PHP_OS_FAMILY !== 'Windows') {
            foreach (array_reverse($entries, true) as $relative => $entry) {
                if ($entry['type'] === 'directory' && !chmod($this->path($relative), $entry['mode'])) {
                    throw new RuntimeException('Cannot restore directory permissions.');
                }
            }
        }
    }

    public function finish(): void
    {
        if (!in_array($this->active()['phase'] ?? '', ['quiescing', 'capturing', 'committed', 'restored'], true)) {
            throw new RuntimeException('Cannot reopen traffic before commit or verified recovery.');
        }
        if (!unlink($this->directory . '/active.json')) { throw new RuntimeException('Cannot finish application recovery.'); }
        // Retain snapshots. Reopening traffic permanently disables automatic DB rollback.
    }

    private function exclusions(): array
    {
        $scope = self::read($this->directoryPath() . '/scope.json');
        if (!is_array($scope['exclusions'] ?? null)
            || !in_array('.fnlla/application-update', $scope['exclusions'], true)
            || !in_array('.fnlla/update-transaction', $scope['exclusions'], true)) {
            throw new RuntimeException('Invalid snapshot scope.');
        }
        foreach ($scope['exclusions'] as $relative) { $this->path($relative); }
        return $scope['exclusions'];
    }

    private function inventory(array $exclusions): array
    {
        $entries = [];
        $visit = function (string $directory, string $prefix) use (&$visit, &$entries, $exclusions): void {
            $names = scandir($directory);
            if ($names === false) { throw new RuntimeException('Cannot inventory application directory.'); }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $relative = $prefix . $name;
                if ($this->excluded($relative, $exclusions)) { continue; }
                $path = $this->path($relative);
                if (!is_dir($path) && !is_file($path)) { throw new RuntimeException('Unsupported filesystem entry in snapshot.'); }
                $entries[$relative] = ['type' => is_dir($path) ? 'directory' : 'file', 'mode' => fileperms($path) & 0777];
                if (is_dir($path)) { $visit($path, $relative . '/'); }
            }
        };
        $visit($this->root, '');
        return $entries;
    }

    private function excluded(string $path, array $exclusions): bool
    {
        foreach ($exclusions as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) { return true; }
        }
        return false;
    }

    private function path(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '\\') || str_starts_with($relative, '/')
            || preg_match('/[\x00-\x1f:]/', $relative) || array_intersect(['.', '..', ''], explode('/', $relative))) {
            throw new RuntimeException('Unsafe application snapshot path.');
        }
        $path = $this->root;
        foreach (explode('/', $relative) as $part) {
            if (PHP_OS_FAMILY === 'Windows' && (str_ends_with($part, '.') || str_ends_with($part, ' '))) {
                throw new RuntimeException('Ambiguous Windows snapshot path.');
            }
            $path .= '/' . $part;
            if (is_link($path)) { throw new RuntimeException('Snapshot symlinks are not supported.'); }
            if (file_exists($path)) {
                $resolved = realpath($path);
                $prefix = $this->root . DIRECTORY_SEPARATOR;
                if ($resolved === false || !str_starts_with(PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved,
                    PHP_OS_FAMILY === 'Windows' ? strtolower($prefix) : $prefix)) {
                    throw new RuntimeException('Snapshot path leaves the application.');
                }
            }
        }
        return $path;
    }

    public static function write(string $path, array $value): void
    {
        self::directory(dirname($path));
        $temporary = tempnam(dirname($path), '.snapshot-');
        if ($temporary === false) { throw new RuntimeException('Cannot stage snapshot metadata.'); }
        try {
            $stream = fopen($temporary, 'wb');
            if ($stream === false) { throw new RuntimeException('Cannot write snapshot metadata.'); }
            try {
                $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
                    throw new RuntimeException('Cannot persist snapshot metadata.');
                }
            } finally { fclose($stream); }
            if (!rename($temporary, $path)) { throw new RuntimeException('Cannot activate snapshot metadata.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }

    public static function read(string $path): array
    {
        if (is_link($path) || !is_file($path)) { throw new RuntimeException('Snapshot metadata is missing or unsafe.'); }
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new RuntimeException('Invalid snapshot metadata.'); }
        return $value;
    }

    private static function directory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true))) {
            throw new RuntimeException('Cannot create private snapshot directory.');
        }
    }

    private static function copy(string $source, string $destination, int $mode): void
    {
        self::directory(dirname($destination));
        $temporary = tempnam(dirname($destination), '.snapshot-');
        if ($temporary === false) { throw new RuntimeException('Cannot stage snapshot file.'); }
        try {
            if (!copy($source, $temporary)) { throw new RuntimeException('Cannot copy snapshot file.'); }
            $stream = fopen($temporary, 'ab');
            if ($stream === false) { throw new RuntimeException('Cannot open snapshot file.'); }
            try { if (!fflush($stream) || !fsync($stream)) { throw new RuntimeException('Cannot persist snapshot file.'); } }
            finally { fclose($stream); }
            if (PHP_OS_FAMILY !== 'Windows' && !chmod($temporary, $mode)) { throw new RuntimeException('Cannot protect snapshot file.'); }
            if (!rename($temporary, $destination)) { throw new RuntimeException('Cannot restore snapshot file.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}
