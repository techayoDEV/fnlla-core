<?php

declare(strict_types=1);

require __DIR__ . "/lib/DeterministicZip.php";

$root = dirname(__DIR__);
$version = trim((string) ($argv[1] ?? ""));
$output = $argv[2] ?? ($root . "/dist/local/fnlla-core-" . $version);
$localReview = in_array("--local-review", $argv, true);

if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
    fwrite(STDERR, "Usage: php scripts/build-local-artifact.php <semver> [output-directory] [--local-review]" . PHP_EOL);
    exit(2);
}

$output = normalize_path($output);
$archive = $output . ".zip";
$allowedRoot = normalize_path($root . "/dist");
if (!str_starts_with(strtolower($output . "/"), strtolower($allowedRoot . "/"))) {
    fwrite(STDERR, "Artifact output must stay under the Core dist directory." . PHP_EOL);
    exit(2);
}
foreach ([$output, $archive, $archive . ".sha256"] as $path) {
    if (file_exists($path)) {
        fwrite(STDERR, "Artifact output already exists: " . $path . PHP_EOL);
        exit(2);
    }
}

$policy = json_decode((string) file_get_contents($root . "/resources/package-distribution.json"), true, 512, JSON_THROW_ON_ERROR);
if ($localReview) {
    if (!str_contains($version, "-") || $version === ($policy["published_baseline"] ?? null)) {
        throw new RuntimeException("Local review requires a distinct prerelease version.");
    }
    $policy["candidate"] = $version;
    $policy["channel"] = "local-review";
    $policy["release_approved"] = false;
}
$channel = $policy["channel"] ?? null;
$releaseApproved = $policy["release_approved"] ?? null;
$stableVersion = preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1;
if (($policy["schema"] ?? null) !== "fnlla.core_distribution.v1"
    || ($policy["package"] ?? null) !== "techayodev/fnlla-core"
    || ($policy["candidate"] ?? null) !== $version
    || !is_bool($releaseApproved)
    || !in_array($channel, ["local-review", "stable"], true)
    || ($channel === "stable") !== $releaseApproved
    || $releaseApproved !== $stableVersion) {
    fwrite(STDERR, "Candidate version or release state does not match the Core distribution policy." . PHP_EOL);
    exit(2);
}

$baseCommit = trim(run(["git", "rev-parse", "HEAD"], $root));
$commitEpoch = (int) trim(run(["git", "show", "-s", "--format=%ct", "HEAD"], $root));
$patch = run(["git", "diff", "--binary", "HEAD", "--", "."], $root);
$status = run(["git", "status", "--porcelain=v1", "--untracked-files=all", "--", ".", ":(exclude)dist"], $root);
$builtAt = gmdate(DATE_ATOM, $commitEpoch);

if ($releaseApproved) {
    if (trim($status) !== "" || trim((string) file_get_contents($root . "/VERSION")) !== $version) {
        throw new RuntimeException("Stable artifacts require clean committed sources and a matching VERSION.");
    }
    foreach (array_slice($argv, 3) as $argument) {
        if (str_starts_with($argument, "--expected-commit=") && substr($argument, 18) !== $baseCommit) {
            throw new RuntimeException("Stable artifact commit does not match the requested source commit.");
        }
    }
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $item) use ($root): bool {
            $relative = ltrim(str_replace("\\", "/", substr($item->getPathname(), strlen($root))), "/");
            // Prune excluded trees before traversal, especially large consumer snapshots.
            return preg_match('~^(?:\\.git|vendor|node_modules|dist|storage)(?:/|$)~i', $relative) !== 1;
        }
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);
$files = [];
foreach ($iterator as $item) {
    if (!$item->isFile()) {
        continue;
    }
    if ($item->isLink()) {
        throw new RuntimeException("Distribution does not follow symbolic links: " . $item->getPathname());
    }
    $relative = ltrim(str_replace("\\", "/", substr($item->getPathname(), strlen($root))), "/");
    if ($relative === "" || preg_match('~^(?:\.git|vendor|node_modules|dist|storage)(?:/|$)~i', $relative) === 1) {
        continue;
    }
    if (is_sensitive_path($relative)) {
        continue;
    }
    if (str_contains($relative, "../") || str_starts_with($relative, "/")) {
        throw new RuntimeException("Unsafe artifact path: " . $relative);
    }
    $files[$relative] = $item->getPathname();
}
ksort($files, SORT_STRING);

if ($releaseApproved) {
    // Commit blobs, rather than checkout bytes, make Windows/Linux builds identical
    // and prevent ignored or untracked files from entering an approved package.
    $files = [];
    foreach (explode("\0", run(["git", "ls-tree", "-r", "-z", $baseCommit], $root)) as $entry) {
        if ($entry === "") { continue; }
        if (preg_match('/^(100644|100755) blob ([a-f0-9]{40})\t(.+)$/sD', $entry, $match) !== 1) {
            throw new RuntimeException("Stable source cannot contain symlinks or submodules.");
        }
        $relative = $match[3];
        if (preg_match('~^(?:vendor|node_modules|dist|storage)(?:/|$)~i', $relative) === 1 || is_sensitive_path($relative)) { continue; }
        if (preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $relative) !== 1) {
            throw new RuntimeException("Unsafe committed artifact path.");
        }
        $files[$relative] = $match[2];
    }
    ksort($files, SORT_STRING);
}

foreach ($files as $relative => $source) {
    $target = $output . "/" . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
        throw new RuntimeException("Cannot create artifact directory: " . dirname($target));
    }
    $contents = $releaseApproved ? run(["git", "cat-file", "blob", $source], $root) : (string) file_get_contents($source);
    assert_no_secret($relative, $contents);
    if ($relative === "VERSION") {
        $contents = $version . "\n";
    }
    if ($localReview && $relative === "resources/package-distribution.json") {
        $contents = json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
    write_file($target, $contents);
}

$provenance = [
    "schema" => $releaseApproved ? "fnlla.core.release.v2" : "fnlla.core.local-candidate.v2",
    "package" => "techayodev/fnlla-core",
    "version" => $version,
    "channel" => $channel,
    "source_repository" => "https://github.com/techayoDEV/fnlla-core",
    "base_commit" => $baseCommit,
    "source_identifier" => "git+https://github.com/techayoDEV/fnlla-core#" . $baseCommit,
    "workspace_status_sha256" => hash("sha256", $status),
    "workspace_patch_sha256" => hash("sha256", $patch),
    "workspace_dirty" => trim($status) !== "",
    "source_date_epoch" => $commitEpoch,
    "built_at_utc" => $builtAt,
    "release_approved" => $releaseApproved,
    "purpose" => $releaseApproved
        ? "Reproducible immutable FNLLA Core stable release"
        : "Reproducible local FNLLA Core release-candidate validation; not an official release",
];
write_file(
    $output . "/FNLLA-PROVENANCE.json",
    json_encode($provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

$packageMetadata = [
    "schema" => "fnlla.package.v1",
    "package" => "techayodev/fnlla-core",
    "version" => $version,
    "channel" => $channel,
    "release_approved" => $releaseApproved,
    "source" => [
        "repository" => $provenance["source_repository"],
        "commit" => $baseCommit,
        "provenance" => "FNLLA-PROVENANCE.json",
    ],
    "requirements" => $policy["requirements"],
    "compatibility" => ["framework" => $policy["framework_compatibility"]],
    "deprecations" => $policy["deprecations"],
    "rights" => $policy["rights"],
    "release_order" => $policy["release_order"],
];
write_file(
    $output . "/FNLLA-PACKAGE.json",
    json_encode($packageMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

$hashes = [];
$manifestIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($manifestIterator as $item) {
    if (!$item->isFile() || $item->getFilename() === "FNLLA-MANIFEST.sha256") {
        continue;
    }
    $relative = ltrim(str_replace("\\", "/", substr($item->getPathname(), strlen($output))), "/");
    $hashes[$relative] = hash_file("sha256", $item->getPathname());
}
ksort($hashes, SORT_STRING);
$manifest = "";
foreach ($hashes as $relative => $hash) {
    $manifest .= $hash . "  " . $relative . "\n";
}
write_file($output . "/FNLLA-MANIFEST.sha256", $manifest);

$archiveEntries = [];
$archiveRoot = "fnlla-core-" . $version;
$archiveIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($archiveIterator as $item) {
    if (!$item->isFile() || $item->isLink()) {
        continue;
    }
    $relative = ltrim(str_replace("\\", "/", substr($item->getPathname(), strlen($output))), "/");
    $archiveEntries[$archiveRoot . "/" . $relative] = (string) file_get_contents($item->getPathname());
}
DeterministicZip::create($archive, $archiveEntries);
$archiveHash = hash_file("sha256", $archive);
write_file($archive . ".sha256", $archiveHash . "  " . basename($archive) . "\n");

fwrite(STDOUT, json_encode([
    "artifact" => $output,
    "archive" => $archive,
    "package" => "techayodev/fnlla-core",
    "version" => $version,
    "base_commit" => $baseCommit,
    "files" => count($hashes),
    "manifest_sha256" => hash("sha256", $manifest),
    "archive_sha256" => $archiveHash,
    "release_approved" => $releaseApproved,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

function is_sensitive_path(string $relative): bool
{
    $name = strtolower(basename($relative));
    if (str_starts_with($name, ".env") && !in_array($name, [".env.example", ".env.platform.example"], true)) {
        return true;
    }
    if (in_array($name, ["auth.json", ".npmrc", ".pypirc", "id_rsa", "id_ed25519"], true)) {
        return true;
    }
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ["key", "pem", "p12", "pfx", "sql", "sqlite", "sqlite3", "bak", "log", "tmp", "zip", "pyc", "pyo"], true);
}

function assert_no_secret(string $relative, string $contents): void
{
    $patterns = [
        '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        '/\bgh[pousr]_[A-Za-z0-9_]{30,}\b/',
        '/\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b/',
        '/\bAKIA[A-Z0-9]{16}\b/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            throw new RuntimeException("Potential secret found in distribution input: " . $relative);
        }
    }
}

function write_file(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException("Cannot write artifact file: " . $path);
    }
}

function normalize_path(string $path): string
{
    $path = str_replace("\\", "/", $path);
    if (preg_match('/^[A-Za-z]:\//', $path) !== 1 && !str_starts_with($path, "/")) {
        $path = str_replace("\\", "/", (string) getcwd()) . "/" . $path;
    }
    $segments = [];
    foreach (explode("/", $path) as $segment) {
        if ($segment === "" || $segment === ".") {
            continue;
        }
        if ($segment === "..") {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    $normalized = implode("/", $segments);
    if (preg_match('/^[A-Za-z]:$/', $segments[0] ?? "") === 1) {
        return $normalized;
    }
    return "/" . $normalized;
}

/** @param list<string> $command */
function run(array $command, string $cwd): string
{
    // File-backed output avoids stdout/stderr pipe deadlocks on Windows.
    $stdout = tmpfile(); $stderr = tmpfile();
    if ($stdout === false || $stderr === false) { throw new RuntimeException("Cannot allocate process output."); }
    $process = null;
    try {
        $process = proc_open($command, [1 => $stdout, 2 => $stderr], $pipes, $cwd, null, ["bypass_shell" => true]);
        if (!is_resource($process)) { throw new RuntimeException("Cannot start artifact input command."); }
        $deadline = microtime(true) + 120;
        do {
            $state = proc_get_status($process);
            if (microtime(true) > $deadline || fstat($stdout)["size"] > 33554432 || fstat($stderr)["size"] > 1048576) {
                throw new RuntimeException("Artifact input command exceeded time/output limits.");
            }
            if ($state["running"]) { usleep(10000); }
        } while ($state["running"]);
        $closed = proc_close($process); $process = null;
        $exit = $state["exitcode"] >= 0 ? $state["exitcode"] : $closed;
        rewind($stdout); rewind($stderr);
        if ($exit !== 0) {
            throw new RuntimeException(trim((string) stream_get_contents($stderr, 1048576)) ?: "Artifact input command failed.");
        }
        return (string) stream_get_contents($stdout, 33554432);
    } finally {
        if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
        fclose($stdout); fclose($stderr);
    }
}
