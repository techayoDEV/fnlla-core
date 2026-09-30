<?php

declare(strict_types=1);

/** @param list<string> $operations @return list<int> */
function cache_parallel_workers(string $backend, string $location, array $operations): array
{
    $barrier = tempnam(sys_get_temp_dir(), "fnlla-cache-barrier-");
    if ($barrier === false) { throw new RuntimeException("Cannot create cache barrier."); }
    $workers = [];
    try {
        foreach ($operations as $operation) {
            $process = proc_open([PHP_BINARY, __DIR__ . "/cache-worker.php", $backend, $location, $barrier, $operation],
                [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
            if (!is_resource($process)) { throw new RuntimeException("Cannot start cache worker."); }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $workers[] = [$process, $pipes];
        }
        file_put_contents($barrier, "go");
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $output = "";
            $error = "";
            $deadline = microtime(true) + 30;
            do {
                $output .= stream_get_contents($pipes[1]);
                $error .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (microtime(true) > $deadline) { throw new RuntimeException("Cache worker timed out."); }
                if ($status["running"]) { usleep(1000); }
            } while ($status["running"]);
            $output .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if (($exit === -1 ? $status["exitcode"] : $exit) !== 0 || !ctype_digit(trim($output))) {
                throw new RuntimeException("Cache worker failed: " . $error . $output);
            }
            $results[] = (int) trim($output);
        }
        return $results;
    } finally {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                proc_close($process);
            }
        }
        unlink($barrier);
    }
}
