<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Support/ApplicationSnapshot.php';
require_once dirname(__DIR__) . '/src/Support/SnapshotProcess.php';

use Fnlla\Php\Support\ApplicationSnapshot;
use Fnlla\Php\Support\SnapshotProcess;

(static function (): void {
    $check = static function (bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    };
    $reject = static function (callable $operation): void {
        try { $operation(); } catch (RuntimeException) { return; }
        throw new RuntimeException('Unsafe snapshot operation was allowed.');
    };
    $root = sys_get_temp_dir() . '/fnlla-snapshot-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/app', 0700);
    mkdir($root . '/storage', 0700);
    mkdir($root . '/storage/empty', 0700);
    file_put_contents($root . '/.env', 'PRIVATE=original');
    file_put_contents($root . '/app/old.php', 'old');
    try {
        $snapshot = new ApplicationSnapshot($root);
        $reject(static fn () => $snapshot->begin(['../escape']));
        $directory = $snapshot->begin();
        $reject(static fn () => $snapshot->begin());
        $reject(static fn () => $snapshot->captureFiles());
        $snapshot->phase('capturing');
        $snapshot->captureFiles();
        $snapshot->phase('installing');
        $reject(static fn () => $snapshot->finish());
        file_put_contents($root . '/.env', 'PRIVATE=changed');
        unlink($root . '/app/old.php');
        mkdir($root . '/app/new');
        file_put_contents($root . '/app/new/new.php', 'new');
        rmdir($root . '/storage/empty');
        $snapshot->phase('restoring');
        $metadata = file_get_contents($directory . '/files.json');
        file_put_contents($directory . '/files.json', '{"schema":"fnlla.application_files.v1","entries":[]}');
        $reject(static fn () => $snapshot->restoreFiles());
        $check(file_get_contents($root . '/.env') === 'PRIVATE=changed', 'A changed manifest must not remove application files.');
        file_put_contents($directory . '/files.json', $metadata);
        $backup = $directory . '/files/' . hash('sha256', '.env');
        $original = file_get_contents($backup);
        file_put_contents($backup, 'damaged');
        $reject(static fn () => $snapshot->restoreFiles());
        $check(file_get_contents($root . '/.env') === 'PRIVATE=changed', 'Corruption must be detected before restore.');
        file_put_contents($backup, $original);
        $snapshot->restoreFiles();
        $snapshot->restoreFiles(); // interrupted recovery is repeatable while traffic is blocked
        $check(file_get_contents($root . '/.env') === 'PRIVATE=original', 'Private configuration was not restored.');
        $check(file_get_contents($root . '/app/old.php') === 'old', 'Deleted file was not restored.');
        $check(!is_dir($root . '/app/new') && is_dir($root . '/storage/empty'), 'New/empty directories were not restored.');
        $snapshot->phase('restored');
        $snapshot->finish();
        $check(is_file($directory . '/files.json'), 'Recovery must retain the snapshot.');
        $snapshot->begin();
        $snapshot->phase('capturing');
        $snapshot->captureFiles();
        $snapshot->phase('installing');
        $snapshot->phase('committed');
        $reject(static fn () => $snapshot->phase('restoring'));
        $reject(static fn () => $snapshot->restoreFiles());
        $snapshot->finish();
        $reject(static fn () => SnapshotProcess::run([PHP_BINARY, '-r', 'sleep(10);'], $root, 1));
        echo "Application snapshot rollback, corruption, retention and commit-boundary checks passed.\n";
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
})();
