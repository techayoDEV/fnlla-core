<?php

declare(strict_types=1);

use Fnlla\Php\Queue\FileQueueStore;

$diagnosticRoot = sys_get_temp_dir() . '/fnlla-diagnostics-' . bin2hex(random_bytes(6));
mkdir($diagnosticRoot, 0700);
try {
    foreach (['exception', 'parse', 'fatal'] as $mode) {
        $log = $diagnosticRoot . '/' . $mode . '.log';
        [$exit, $output] = run_process([PHP_BINARY, __DIR__ . '/fixtures/runtime-diagnostics.php', $log, $mode], dirname(__DIR__));
        assert_true($exit !== 0, 'Uncaught failures must have nonzero exit status.');
        assert_true(!str_contains($output, 'SYNTHETIC_PRIVATE_DIAGNOSTIC'), 'Uncaught output exposed exception text.');
        $contents = (string) file_get_contents($log);
        assert_true(!str_contains($contents, 'SYNTHETIC_PRIVATE_DIAGNOSTIC'), 'Uncaught report exposed exception text.');
        assert_true(str_contains($contents, 'runtime-diagnostics.php'), 'Safe diagnostic location was lost.');
    }
    $queue = new FileQueueStore($diagnosticRoot . '/queue');
    $queue->push('SyntheticDiagnosticsJob');
    $job = $queue->pop();
    assert_true($job !== null, 'Synthetic diagnostic job was not reserved.');
    $job['last_error'] = 'Provider failed Bearer SYNTHETIC_PRIVATE_QUEUE';
    $file = $queue->fail($job);
    $record = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    assert_same('job_failed', $record['last_error'], 'Direct queue store accepted raw exception text.');
    assert_true(!str_contains((string) file_get_contents($file), 'SYNTHETIC_PRIVATE_QUEUE'), 'Queue persistence exposed credentials.');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($diagnosticRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($diagnosticRoot);
}
echo "Runtime diagnostics retain locations and exclude raw secrets.\n";
