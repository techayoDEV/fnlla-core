<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use RuntimeException;

final class BoundedPhpProcess
{
    /** Run a trusted PHP probe with private stdin, bounded time/output and no shell.
     * @param array<string, mixed> $input
     * @return array{status:string,exit_code:int,output:string}
     */
    public static function run(string $script, array $input, float $seconds): array
    {
        if (!function_exists("proc_open")) {
            return ["status" => "unavailable", "exit_code" => 1, "output" => ""];
        }
        $json = json_encode($input, JSON_THROW_ON_ERROR);
        if (strlen($json) > 4096 || !is_file($script) || $seconds <= 0 || $seconds > 30) {
            throw new RuntimeException("Invalid diagnostic probe input.");
        }
        $stdout = tempnam(sys_get_temp_dir(), "fnlla-probe-");
        $stderr = tempnam(sys_get_temp_dir(), "fnlla-probe-");
        if ($stdout === false || $stderr === false) {
            if (is_string($stdout)) { unlink($stdout); }
            if (is_string($stderr)) { unlink($stderr); }
            throw new RuntimeException("Cannot allocate diagnostic output.");
        }
        @chmod($stdout, 0600);
        @chmod($stderr, 0600);
        $process = null;
        $pipes = [];
        try {
            $process = proc_open([PHP_BINARY, "-d", "display_errors=0", "-d", "log_errors=0", $script],
                [0 => ["pipe", "r"], 1 => ["file", $stdout, "w"], 2 => ["file", $stderr, "w"]],
                $pipes, null, null, ["bypass_shell" => true, "create_process_group" => true]);
            if (!is_resource($process)) {
                return ["status" => "unavailable", "exit_code" => 1, "output" => ""];
            }
            if (fwrite($pipes[0], $json) !== strlen($json)) {
                throw new RuntimeException("Cannot submit diagnostic probe.");
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            $deadline = hrtime(true) + (int) ($seconds * 1e9);
            $status = "completed";
            $exitCode = 1;
            while (true) {
                $state = proc_get_status($process);
                if (!$state["running"]) { $exitCode = $state["exitcode"]; break; }
                clearstatcache(true, $stdout);
                clearstatcache(true, $stderr);
                if (filesize($stdout) > 16384 || filesize($stderr) > 16384) {
                    $status = "output_limit";
                    break;
                }
                if (hrtime(true) >= $deadline) { $status = "timeout"; break; }
                usleep(10000);
            }
            if ($status !== "completed") { proc_terminate($process, 9); }
            $closed = proc_close($process);
            $process = null;
            clearstatcache(true, $stdout);
            clearstatcache(true, $stderr);
            if (filesize($stdout) > 16384 || filesize($stderr) > 16384) { $status = "output_limit"; }
            if ($exitCode < 0) { $exitCode = $closed; }
            return ["status" => $status, "exit_code" => $exitCode,
                "output" => $status === "completed" ? (string) file_get_contents($stdout, false, null, 0, 16384) : ""];
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
            unlink($stdout);
            unlink($stderr);
        }
    }
}
