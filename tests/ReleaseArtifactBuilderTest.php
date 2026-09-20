<?php

declare(strict_types=1);

$releaseArtifactRoot = dirname(__DIR__);
$releaseArtifactVersion = trim((string) file_get_contents($releaseArtifactRoot . "/VERSION"));
$releaseArtifactCase = $releaseArtifactRoot . "/dist/test-release-artifact-"
    . getmypid() . "-" . bin2hex(random_bytes(4));
$releaseArtifactOutput = $releaseArtifactCase . "/fnlla-core-" . $releaseArtifactVersion;

try {
    [$releaseArtifactExit, $releaseArtifactBuildOutput] = release_artifact_run_process(
        [
            PHP_BINARY,
            $releaseArtifactRoot . "/scripts/build-local-artifact.php",
            $releaseArtifactVersion,
            $releaseArtifactOutput,
        ],
        $releaseArtifactRoot
    );
    release_artifact_assert(
        $releaseArtifactExit === 0,
        "Release artifact build failed: " . $releaseArtifactBuildOutput
    );

    $sourceComposer = (string) file_get_contents($releaseArtifactRoot . "/composer.json");
    $artifactComposerPath = $releaseArtifactOutput . "/composer.json";
    $artifactComposer = (string) file_get_contents($artifactComposerPath);
    $artifactComposerData = json_decode($artifactComposer, true, 512, JSON_THROW_ON_ERROR);
    release_artifact_assert(
        !array_key_exists("version", $artifactComposerData),
        "Packaged composer.json must not inject a version field."
    );
    release_artifact_assert(
        $artifactComposer === $sourceComposer,
        "RC and stable artifact generation must preserve composer.json byte-for-byte."
    );

    $package = release_artifact_json($releaseArtifactOutput . "/FNLLA-PACKAGE.json");
    $provenance = release_artifact_json($releaseArtifactOutput . "/FNLLA-PROVENANCE.json");
    release_artifact_assert(
        trim((string) file_get_contents($releaseArtifactOutput . "/VERSION")) === $releaseArtifactVersion,
        "Artifact VERSION does not preserve the release identity."
    );
    release_artifact_assert(
        ($package["version"] ?? null) === $releaseArtifactVersion
        && ($package["channel"] ?? null) === "stable"
        && ($package["release_approved"] ?? null) === true,
        "Package manifest does not preserve the approved stable identity."
    );
    release_artifact_assert(
        ($provenance["version"] ?? null) === $releaseArtifactVersion
        && ($provenance["channel"] ?? null) === "stable"
        && ($provenance["release_approved"] ?? null) === true
        && preg_match('/^[a-f0-9]{40}$/D', (string) ($provenance["base_commit"] ?? "")) === 1,
        "Provenance does not preserve the approved stable source identity."
    );

    $composerCommand = PHP_OS_FAMILY === "Windows"
        ? "composer validate --strict --no-interaction"
        : ["composer", "validate", "--strict", "--no-interaction"];
    [$composerExit, $composerOutput] = release_artifact_run_process(
        $composerCommand,
        $releaseArtifactOutput
    );
    release_artifact_assert(
        $composerExit === 0,
        "Packaged composer.json failed strict validation: " . $composerOutput
    );

    fwrite(STDOUT, "FNLLA Core release artifact builder tests passed." . PHP_EOL);
} finally {
    release_artifact_remove_tree($releaseArtifactCase);
}

/** @param list<string>|string $command
 *  @return array{0:int,1:string}
 */
function release_artifact_run_process(array|string $command, string $workingDirectory): array
{
    $descriptorSpec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        throw new RuntimeException("Unable to start release artifact validation process.");
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), trim((string) $stdout . ($stderr !== "" ? PHP_EOL . $stderr : ""))];
}

/** @return array<string,mixed> */
function release_artifact_json(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException("Expected a JSON object: " . $path);
    }
    return $value;
}

function release_artifact_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function release_artifact_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}
