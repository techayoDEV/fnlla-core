<?php

declare(strict_types=1);

$releaseArtifactSourceRoot = dirname(__DIR__);
// Exercise stable publication using an isolated synthetic identity even when
// this suite is running from a prerelease package.
$releaseArtifactVersion = "9.8.7";
$releaseArtifactCase = $releaseArtifactSourceRoot . "/dist/test-release-artifact-"
    . getmypid() . "-" . bin2hex(random_bytes(4));
$releaseArtifactRoot = $releaseArtifactCase . "/source";
$releaseArtifactOutput = $releaseArtifactRoot . "/dist/fnlla-core-" . $releaseArtifactVersion;

try {
    foreach (["scripts/build-local-artifact.php", "scripts/lib/DeterministicZip.php", "composer.json", "VERSION", "resources/package-distribution.json"] as $relative) {
        if (!is_dir(dirname($releaseArtifactRoot . "/" . $relative))) { mkdir(dirname($releaseArtifactRoot . "/" . $relative), 0700, true); }
        copy($releaseArtifactSourceRoot . "/" . $relative, $releaseArtifactRoot . "/" . $relative);
    }
    file_put_contents($releaseArtifactRoot . "/VERSION", $releaseArtifactVersion . "\n");
    $fixturePolicy = release_artifact_json($releaseArtifactRoot . "/resources/package-distribution.json");
    $fixturePolicy["candidate"] = $releaseArtifactVersion;
    $fixturePolicy["channel"] = "stable";
    $fixturePolicy["release_approved"] = true;
    file_put_contents($releaseArtifactRoot . "/resources/package-distribution.json",
        json_encode($fixturePolicy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($releaseArtifactRoot . "/.gitignore", "/dist/\n/ignored.tmp\n");
    foreach ([["git", "init", "-q"], ["git", "config", "user.name", "Synthetic release test"],
        ["git", "config", "user.email", "release-test@example.invalid"], ["git", "config", "core.autocrlf", "false"],
        ["git", "add", "."], ["git", "-c", "core.hooksPath=/dev/null", "-c", "commit.gpgsign=false", "commit", "-q", "-m", "Synthetic stable fixture"]] as $command) {
        [$code, $output] = release_artifact_run_process($command, $releaseArtifactRoot);
        release_artifact_assert($code === 0, "Cannot prepare isolated release fixture: " . $output);
    }
    file_put_contents($releaseArtifactRoot . "/ignored.tmp", "must never ship");
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
    release_artifact_assert(($provenance["workspace_dirty"] ?? true) === false && !is_file($releaseArtifactOutput . "/ignored.tmp"),
        "Stable artifact included dirty/ignored source.");
    [$againCode] = release_artifact_run_process([PHP_BINARY, $releaseArtifactRoot . "/scripts/build-local-artifact.php",
        $releaseArtifactVersion, $releaseArtifactRoot . "/dist/reproducible"], $releaseArtifactRoot);
    release_artifact_assert($againCode === 0 && hash_file("sha256", $releaseArtifactOutput . ".zip")
        === hash_file("sha256", $releaseArtifactRoot . "/dist/reproducible.zip"), "Stable artifacts are not reproducible.");
    foreach ([
        [$releaseArtifactOutput],
        [$releaseArtifactRoot . "/dist/wrong-commit", "--expected-commit=" . str_repeat("0", 40)],
    ] as $arguments) {
        [$code] = release_artifact_run_process([PHP_BINARY, $releaseArtifactRoot . "/scripts/build-local-artifact.php", $releaseArtifactVersion, ...$arguments], $releaseArtifactRoot);
        release_artifact_assert($code !== 0, "Stable output overwrite or mismatched commit accepted.");
    }
    file_put_contents($releaseArtifactRoot . "/unreviewed.php", "<?php // synthetic untracked input\n");
    [$dirtyCode] = release_artifact_run_process([PHP_BINARY, $releaseArtifactRoot . "/scripts/build-local-artifact.php", $releaseArtifactVersion,
        $releaseArtifactRoot . "/dist/dirty"], $releaseArtifactRoot);
    release_artifact_assert($dirtyCode !== 0, "Untracked source was approved for stable release.");
    unlink($releaseArtifactRoot . "/unreviewed.php");
    file_put_contents($releaseArtifactRoot . "/VERSION", "0.0.0\n");
    [$dirtyCode] = release_artifact_run_process([PHP_BINARY, $releaseArtifactRoot . "/scripts/build-local-artifact.php", $releaseArtifactVersion,
        $releaseArtifactRoot . "/dist/modified"], $releaseArtifactRoot);
    release_artifact_assert($dirtyCode !== 0, "Modified source was approved for stable release.");

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
    // Local-review traversal must also prune ignored build dependencies. Keeping
    // @scoped packages breaks immutable manifests and bloats shipped artifacts.
    mkdir($releaseArtifactRoot . '/node_modules/@synthetic/library', 0700, true);
    file_put_contents($releaseArtifactRoot . '/node_modules/@synthetic/library/package.json', '{}');
    $localOutput = $releaseArtifactRoot . '/dist/local-review';
    [$localExit, $localText] = release_artifact_run_process([PHP_BINARY, $releaseArtifactRoot . '/scripts/build-local-artifact.php',
        '9.8.8-alpha.1', $localOutput, '--local-review'], $releaseArtifactRoot);
    release_artifact_assert($localExit === 0 && !is_dir($localOutput . '/node_modules'), 'Local artifact shipped build dependencies: ' . $localText);

    fwrite(STDOUT, "FNLLA Core release artifact builder tests passed." . PHP_EOL);
} finally {
    release_artifact_remove_tree($releaseArtifactCase);
}

/** @param list<string>|string $command
 *  @return array{0:int,1:string}
 */
function release_artifact_run_process(array|string $command, string $workingDirectory): array
{
    $output = tmpfile();
    if ($output === false) { throw new RuntimeException("Cannot allocate test output."); }
    $descriptorSpec = [0 => ["pipe", "r"], 1 => $output, 2 => $output];
    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        throw new RuntimeException("Unable to start release artifact validation process.");
    }

    fclose($pipes[0]);
    $exit = proc_close($process);
    rewind($output);
    $text = stream_get_contents($output);
    fclose($output);
    return [$exit, trim((string) $text)];
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
    $resolved = str_replace("\\", "/", (string) realpath($path));
    $allowed = str_replace("\\", "/", (string) realpath(dirname(__DIR__) . "/dist")) . "/test-release-artifact-";
    if (!str_starts_with(strtolower($resolved), strtolower($allowed))) { throw new RuntimeException("Unsafe fixture cleanup path."); }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            chmod($item->getPathname(), 0600);
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}
