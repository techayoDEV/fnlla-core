<?php

declare(strict_types=1);

require __DIR__ . "/lib/DeterministicZip.php";

$root = dirname(__DIR__);
$version = trim((string) ($argv[1] ?? ""));
$output = $argv[2] ?? ($root . "/dist/local/fnlla-core-" . $version);

if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
    fwrite(STDERR, "Usage: php scripts/build-local-artifact.php <semver> [output-directory]" . PHP_EOL);
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
if (($policy["schema"] ?? null) !== "fnlla.core_distribution.v1"
    || ($policy["package"] ?? null) !== "techayodev/fnlla-core"
    || ($policy["candidate"] ?? null) !== $version
    || ($policy["release_approved"] ?? true) !== false) {
    fwrite(STDERR, "Candidate version or release state does not match the Core distribution policy." . PHP_EOL);
    exit(2);
}

$baseCommit = trim(run(["git", "rev-parse", "HEAD"], $root));
$commitEpoch = (int) trim(run(["git", "show", "-s", "--format=%ct", "HEAD"], $root));
$patch = run(["git", "diff", "--binary", "HEAD", "--", "."], $root);
$status = run(["git", "status", "--porcelain=v1", "--untracked-files=all", "--", ".", ":(exclude)dist"], $root);
$builtAt = gmdate(DATE_ATOM, $commitEpoch);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
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
    if ($relative === "" || preg_match('~^(?:\.git|vendor|dist|storage)(?:/|$)~i', $relative) === 1) {
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

foreach ($files as $relative => $source) {
    $target = $output . "/" . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
        throw new RuntimeException("Cannot create artifact directory: " . dirname($target));
    }
    $contents = (string) file_get_contents($source);
    assert_no_secret($relative, $contents);
    if ($relative === "composer.json") {
        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $composer["version"] = $version;
        $contents = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($relative === "VERSION") {
        $contents = $version . PHP_EOL;
    }
    write_file($target, $contents);
}

$provenance = [
    "schema" => "fnlla.core.local-candidate.v2",
    "package" => "techayodev/fnlla-core",
    "version" => $version,
    "channel" => "local-review",
    "source_repository" => "https://github.com/techayoDEV/fnlla-core",
    "base_commit" => $baseCommit,
    "source_identifier" => "git+https://github.com/techayoDEV/fnlla-core#" . $baseCommit,
    "workspace_status_sha256" => hash("sha256", $status),
    "workspace_patch_sha256" => hash("sha256", $patch),
    "workspace_dirty" => trim($status) !== "",
    "source_date_epoch" => $commitEpoch,
    "built_at_utc" => $builtAt,
    "release_approved" => false,
    "purpose" => "K-02.C reproducible local distribution review; not an official release",
];
write_file(
    $output . "/FNLLA-PROVENANCE.json",
    json_encode($provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
);

$packageMetadata = [
    "schema" => "fnlla.package.v1",
    "package" => "techayodev/fnlla-core",
    "version" => $version,
    "channel" => "local-review",
    "release_approved" => false,
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
    json_encode($packageMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
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
    $manifest .= $hash . "  " . $relative . PHP_EOL;
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
write_file($archive . ".sha256", $archiveHash . "  " . basename($archive) . PHP_EOL);

fwrite(STDOUT, json_encode([
    "artifact" => $output,
    "archive" => $archive,
    "package" => "techayodev/fnlla-core",
    "version" => $version,
    "base_commit" => $baseCommit,
    "files" => count($hashes),
    "manifest_sha256" => hash("sha256", $manifest),
    "archive_sha256" => $archiveHash,
    "release_approved" => false,
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
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ["key", "pem", "p12", "pfx", "sql", "sqlite", "sqlite3", "bak", "log", "tmp", "zip"], true);
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
    $descriptorSpec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException("Cannot start: " . implode(" ", $command));
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(trim((string) $stderr) !== "" ? trim((string) $stderr) : "Command failed: " . implode(" ", $command));
    }
    return (string) $stdout;
}
