<?php

declare(strict_types=1);

use Fnlla\Php\Support\FrameworkUpdateTransaction;

$upgradeRoot = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($upgradeRoot): void {
    $prefix = "Fnlla\\Php\\";
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $upgradeRoot . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR
        . str_replace("\\", DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . ".php";
    if (is_file($path)) {
        require_once $path;
    }
});
$upgradeWorkspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "fnlla-core-upgrade-" . bin2hex(random_bytes(4));
$upgradeSource = $upgradeWorkspace . DIRECTORY_SEPARATOR . "framework";
$cleanProject = $upgradeWorkspace . DIRECTORY_SEPARATOR . "clean-core";
$modifiedProject = $upgradeWorkspace . DIRECTORY_SEPARATOR . "modified-core";
$transactionProject = $upgradeWorkspace . DIRECTORY_SEPARATOR . "transaction-core";

fnlla_upgrade_write_fixture($upgradeSource, $cleanProject, false, $upgradeRoot);

[$planExit, $planOutput] = fnlla_upgrade_run_process([
    PHP_BINARY,
    "fnlla",
    "fnlla:upgrade",
    "--project=" . $cleanProject,
    "--source=" . $upgradeSource,
    "--json",
], $upgradeRoot);
fnlla_upgrade_assert_same(0, $planExit, "Clean generated Core should produce an upgrade-ready plan: " . $planOutput);
$plan = json_decode($planOutput, true, 512, JSON_THROW_ON_ERROR);
fnlla_upgrade_assert_same("upgrade-ready", $plan["status"] ?? null, "Clean generated Core was not classified as upgrade-ready.");
fnlla_upgrade_assert_true(isset($plan["updates"]["config/actions.php"]), "Clean Core-owned configuration was not classified as an update.");
fnlla_upgrade_assert_true(!isset($plan["conflicts"]["config/actions.php"]), "Clean Core-owned configuration was classified as a conflict.");

$projectRoute = (string) file_get_contents($cleanProject . DIRECTORY_SEPARATOR . "routes" . DIRECTORY_SEPARATOR . "web.php");
[$applyExit, $applyOutput] = fnlla_upgrade_run_process([
    PHP_BINARY,
    "fnlla",
    "fnlla:upgrade",
    "--project=" . $cleanProject,
    "--source=" . $upgradeSource,
    "--apply",
    "--json",
], $upgradeRoot);
fnlla_upgrade_assert_same(0, $applyExit, "Clean generated Core upgrade failed: " . $applyOutput);
$applied = json_decode($applyOutput, true, 512, JSON_THROW_ON_ERROR);
fnlla_upgrade_assert_same("applied", $applied["status"] ?? null, "Clean generated Core upgrade was not applied.");
fnlla_upgrade_assert_same(
    hash_file("sha256", $upgradeSource . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "actions.php"),
    hash_file("sha256", $cleanProject . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "actions.php"),
    "Core-owned configuration did not update to the Framework source."
);
fnlla_upgrade_assert_same(
    $projectRoute,
    (string) file_get_contents($cleanProject . DIRECTORY_SEPARATOR . "routes" . DIRECTORY_SEPARATOR . "web.php"),
    "Application-owned route content was replaced."
);
fnlla_upgrade_assert_same("fnlla", trim((string) file_get_contents($cleanProject . DIRECTORY_SEPARATOR . ".fnlla" . DIRECTORY_SEPARATOR . "project-profile")), "Applied upgrade did not change the project profile.");
fnlla_upgrade_assert_true(!is_file($cleanProject . DIRECTORY_SEPARATOR . ".fnlla" . DIRECTORY_SEPARATOR . "update-transaction" . DIRECTORY_SEPARATOR . "journal.json"), "Successful upgrade left an active transaction journal.");

fnlla_upgrade_write_fixture($upgradeSource, $modifiedProject, true, $upgradeRoot);
[$conflictExit, $conflictOutput] = fnlla_upgrade_run_process([
    PHP_BINARY,
    "fnlla",
    "fnlla:upgrade",
    "--project=" . $modifiedProject,
    "--source=" . $upgradeSource,
    "--json",
], $upgradeRoot);
fnlla_upgrade_assert_same(1, $conflictExit, "Modified Core-owned configuration should block automatic upgrade.");
$conflictPlan = json_decode($conflictOutput, true, 512, JSON_THROW_ON_ERROR);
fnlla_upgrade_assert_same("conflicts", $conflictPlan["status"] ?? null, "Modified Core-owned configuration did not produce a conflict plan.");
fnlla_upgrade_assert_true(isset($conflictPlan["updates"]["config/actions.php"]), "Mixed plan lost the clean Core-owned update.");
fnlla_upgrade_assert_true(isset($conflictPlan["conflicts"]["config/auth.php"]), "Mixed plan did not protect modified Core-owned content.");
fnlla_upgrade_assert_same("<?php return ['owner' => 'application'];\n", (string) file_get_contents($modifiedProject . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "auth.php"), "Conflict planning changed application content.");

fnlla_upgrade_write($transactionProject . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "actions.php", "original\n");
$transaction = new FrameworkUpdateTransaction($transactionProject);
try {
    $transaction->run(["config/actions.php", "config/new.php"], static function () use ($transactionProject): void {
        FrameworkUpdateTransaction::replace($transactionProject . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "actions.php", "changed\n");
        FrameworkUpdateTransaction::replace($transactionProject . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "new.php", "new\n");
        throw new RuntimeException("induced upgrade failure");
    });
    fnlla_upgrade_fail("Induced upgrade failure did not trigger rollback.");
} catch (RuntimeException $exception) {
    fnlla_upgrade_assert_true(str_contains($exception->getMessage(), "previous framework files were restored"), "Upgrade failure did not report successful rollback.");
}
fnlla_upgrade_assert_same("original\n", (string) file_get_contents($transactionProject . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "actions.php"), "Rollback did not restore the replaced Core-owned file.");
fnlla_upgrade_assert_true(!is_file($transactionProject . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "new.php"), "Rollback did not remove a newly added file.");
fnlla_upgrade_assert_true(!is_file($transactionProject . DIRECTORY_SEPARATOR . ".fnlla" . DIRECTORY_SEPARATOR . "update-transaction" . DIRECTORY_SEPARATOR . "journal.json"), "Rollback left an active recovery journal.");
unset($transaction);

fnlla_upgrade_remove_directory($upgradeWorkspace);

fwrite(STDOUT, "FNLLA Core to Framework upgrade classification test passed." . PHP_EOL);

function fnlla_upgrade_write_fixture(string $source, string $project, bool $modified, string $root): void
{
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "VERSION", "3.0.0\n");
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "fnlla-runtime" . DIRECTORY_SEPARATOR . "VERSION", "3.0.0\n");
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "export-files.json", json_encode([
        "schema" => "fnlla.project_export.v1",
        "files" => ["config/actions.php", "config/auth.php", "config/framework.php", "routes/web.php"],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "actions.php", "<?php return ['mode' => 'framework'];\n");
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "auth.php", "<?php return ['guard' => 'framework'];\n");
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "framework.php", "<?php return ['enabled' => true];\n");
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "routes" . DIRECTORY_SEPARATOR . "web.php", "<?php // framework route\n");

    foreach (["bootstrap/common.php", "bootstrap/app.php", "bootstrap/router.php"] as $relative) {
        fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relative), "<?php // framework " . $relative . "\n");
        fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $relative), "<?php // core " . $relative . "\n");
    }
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "fnlla.cmd", "@php fnlla %*\r\n");
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "phpstan.neon", "parameters:\n    level: 8\n");
    fnlla_upgrade_write($source . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "v1" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "phpstan.neon", "parameters:\n    level: 5\n");

    fnlla_upgrade_write($project . DIRECTORY_SEPARATOR . ".fnlla" . DIRECTORY_SEPARATOR . "project-profile", "core\n");
    fnlla_upgrade_write($project . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "actions.php", (string) file_get_contents($root . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "actions.php"));
    fnlla_upgrade_write(
        $project . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "auth.php",
        $modified
            ? "<?php return ['owner' => 'application'];\n"
            : (string) file_get_contents($root . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR . "project-templates" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "auth.php")
    );
    fnlla_upgrade_write($project . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "app.php", "<?php return ['providers' => [\\Fnlla\\Php\\Providers\\CoreServiceProvider::class]];\n");
    fnlla_upgrade_write($project . DIRECTORY_SEPARATOR . "composer.json", json_encode([
        "name" => "example/core-project",
        "require" => ["techayodev/fnlla-core" => "2.3.1"],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    fnlla_upgrade_write($project . DIRECTORY_SEPARATOR . "routes" . DIRECTORY_SEPARATOR . "web.php", "<?php // application-owned route\n");
    fnlla_upgrade_write($project . DIRECTORY_SEPARATOR . ".env.example", "APP_NAME=Upgrade Fixture\n");
}

function fnlla_upgrade_write(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        fnlla_upgrade_fail("Cannot create fixture directory: " . $directory);
    }
    if (file_put_contents($path, $contents) === false) {
        fnlla_upgrade_fail("Cannot write fixture file: " . $path);
    }
}

/** @return array{0:int, 1:string} */
function fnlla_upgrade_run_process(array $command, string $cwd): array
{
    $process = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $cwd);
    if (!is_resource($process)) {
        return [1, "Unable to start process."];
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

function fnlla_upgrade_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fnlla_upgrade_fail($message);
    }
}

function fnlla_upgrade_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fnlla_upgrade_fail($message . " Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ".");
    }
}

function fnlla_upgrade_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function fnlla_upgrade_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}
