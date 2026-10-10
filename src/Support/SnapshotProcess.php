<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use RuntimeException;

/** Stream large backup input/output without placing database contents in diagnostics. */
final class SnapshotProcess
{
    public static function run(array $command, string $cwd, int $timeout = 300, ?string $input = null, ?string $output = null): void
    {
        if ($command === [] || $timeout < 1) { throw new RuntimeException('Invalid snapshot command.'); }
        foreach ($command as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) { throw new RuntimeException('Invalid snapshot command argument.'); }
        }
        $error = tempnam(sys_get_temp_dir(), 'fnlla-snapshot-');
        if ($error === false) { throw new RuntimeException('Cannot prepare snapshot process.'); }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['file', $input ?? $null, 'r'],
            1 => ['file', $output ?? $null, 'w'], 2 => ['file', $error, 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
        try {
            if (!is_resource($process)) { throw new RuntimeException('Cannot start snapshot process.'); }
            $start = microtime(true);
            do {
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                clearstatcache(true, $error);
                if (microtime(true) - $start > $timeout || filesize($error) > 1048576) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('Snapshot process exceeded its execution limits.');
                }
                usleep(50000);
            } while (true);
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('Snapshot command failed. Inspect the database/service tooling privately; output is redacted.');
            }
            if ($output !== null) {
                $stream = fopen($output, 'ab');
                if ($stream === false) { throw new RuntimeException('Cannot persist snapshot process output.'); }
                try { if (!fflush($stream) || !fsync($stream)) { throw new RuntimeException('Cannot persist snapshot process output.'); } }
                finally { fclose($stream); }
            }
        } finally {
            if (is_resource($process)) { proc_close($process); }
            if (is_file($error)) { unlink($error); }
        }
    }
}
