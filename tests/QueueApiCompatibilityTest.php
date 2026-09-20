<?php

declare(strict_types=1);

$queueApiCoreRoot = dirname(__DIR__);
$queueApiFixture = __DIR__ . "/fixtures/queue-v224-consumer.php";

/** @return array{0:int,1:string} */
function queue_api_run_process(array $command, string $workingDirectory): array
{
    $descriptorSpec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        throw new RuntimeException("Unable to start the v2.2.4 queue consumer fixture.");
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, trim((string) $stdout . ($stderr !== "" ? PHP_EOL . $stderr : ""))];
}

[$queueApiExit, $queueApiOutput] = queue_api_run_process(
    [PHP_BINARY, $queueApiFixture, $queueApiCoreRoot],
    $queueApiCoreRoot
);
if ($queueApiExit !== 0) {
    throw new RuntimeException(
        "Published v2.2.4 QueueStoreInterface consumer fixture failed with exit {$queueApiExit}: {$queueApiOutput}"
    );
}

$queueApiReport = json_decode($queueApiOutput, true, 512, JSON_THROW_ON_ERROR);
if (($queueApiReport["status"] ?? null) !== "PASS"
    || ($queueApiReport["push_argument_count"] ?? null) !== 2
    || ($queueApiReport["handled"] ?? null) !== 1
    || ($queueApiReport["context_rejected_before_push"] ?? null) !== true) {
    throw new RuntimeException("Published v2.2.4 queue consumer fixture returned an invalid result.");
}

fwrite(STDOUT, "Queue API compatibility test passed." . PHP_EOL);
