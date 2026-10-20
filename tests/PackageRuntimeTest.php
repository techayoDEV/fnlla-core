<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Fnlla\Php\Support\FrameworkUpdateTransaction;
use Fnlla\Php\View\View;

$root = sys_get_temp_dir() . '/fnlla-package-runtime-' . bin2hex(random_bytes(8));
foreach (['config', 'defaults', 'views', 'package/views', '.fnlla', 'storage/framework/cache'] as $directory) {
    mkdir($root . '/' . $directory, 0700, true);
}
define('APP_ROOT', $root);
define('VIEW_ROOT', $root . '/views');
define('FNLLA_CONFIG_DEFAULTS_ROOT', $root . '/defaults');
$checks = 0;
$check = static function (bool $value) use (&$checks): void {
    $checks++;
    if (!$value) { throw new RuntimeException('Package runtime assertion failed.'); }
};
$deny = static function (callable $operation) use ($check): void {
    try { $operation(); } catch (RuntimeException) { $check(true); return; }
    throw new RuntimeException('Unsafe package operation accepted.');
};
try {
    file_put_contents($root . '/defaults/sample.php', '<?php return ["nested" => ["a" => 1, "b" => 2], "list" => ["default-a", "default-b"]];');
    file_put_contents($root . '/config/sample.php', '<?php return ["nested" => ["a" => 3], "list" => ["application"]];');
    $config = load_config_directory($root . '/config');
    $check($config['sample']['nested'] === ['a' => 3, 'b' => 2]);
    $check($config['sample']['list'] === ['application']);
    file_put_contents($root . '/defaults/items.php', '<?php return ["first", "obsolete"];');
    file_put_contents($root . '/config/items.php', '<?php return ["replacement"];');
    file_put_contents($root . '/config/sample.php', '<?php return ["nested" => [], "list" => [], "disabled" => null];');
    $config = load_config_directory($root . '/config');
    $check($config['items'] === ['replacement']);
    $check($config['sample']['nested'] === [] && $config['sample']['list'] === []);
    $check(array_key_exists('disabled', $config['sample']) && $config['sample']['disabled'] === null);
    file_put_contents($root . '/config/invalid.php', '<?php return "not-an-array";');
    $deny(static fn () => load_config_directory($root . '/config'));
    unlink($root . '/config/invalid.php');
    // Cached package configuration must not preserve rotated or removed credentials.
    $cachePath = framework_config_cache_path();
    file_put_contents($root . '/defaults/package_only.php', '<?php return ["retained" => true];');
    file_put_contents($cachePath, '<?php return ["obsolete" => true];');
    $command = new \Fnlla\Php\Console\Commands\ConfigCacheCommand(new \Fnlla\Php\Container\Container());
    ob_start();
    try { $check($command->handle([]) === 0); }
    finally { ob_end_clean(); }
    $compiled = require $cachePath;
    $check($compiled['package_only'] === ['retained' => true]);
    $check($compiled['items'] === ['replacement']);
    $check($compiled['sample']['nested'] === [] && $compiled['sample']['list'] === []);
    $check(!array_key_exists('obsolete', $compiled));
    $check(load_config_directory($root . '/config') === $compiled);
    file_put_contents($root . '/config/app.php', '<?php return ["live_config_fields" => ["../escape" => ["users"]]];');
    $before = hash_file('sha256', $cachePath);
    $deny(static fn () => $command->handle([]));
    $check(hash_file('sha256', $cachePath) === $before);
    unlink($root . '/config/app.php');
    file_put_contents($cachePath, '<?php return ["app" => ["live_config_fields" => ["credentials" => ["users"]]], "credentials" => ["users" => "stale-synthetic"], "sample" => ["cached" => true]];');
    file_put_contents($root . '/defaults/credentials.php', '<?php return ["users" => "rotated-synthetic"];');
    file_put_contents($root . '/config/credentials.php', '<?php return ["enabled" => false];');
    $config = load_config_directory($root . '/config');
    $check(!array_key_exists('users', $config['credentials']));
    $check($config['sample'] === ['cached' => true]);
    file_put_contents($root . '/config/credentials.php', '<?php return ["users" => "application-synthetic"];');
    $check(load_config_directory($root . '/config')['credentials']['users'] === 'application-synthetic');
    unlink($root . '/defaults/credentials.php');
    unlink($root . '/config/credentials.php');
    $check(!array_key_exists('users', load_config_directory($root . '/config')['credentials']));
    // Core 3 requires explicit migration of the former product-specific exception.
    foreach ([[], ['app' => ['live_config_fields' => ['credentials' => ['users']]]],
        ['app' => ['live_config_fields' => ['developer_access' => []]]]] as $extraConfig) {
        file_put_contents($cachePath, '<?php return ' . var_export($extraConfig + [
            'developer_access' => ['users' => 'stale-synthetic', 'enabled' => true],
        ], true) . ';');
        file_put_contents($root . '/defaults/developer_access.php', '<?php return ["users" => "rotated-synthetic"];');
        file_put_contents($root . '/config/developer_access.php', '<?php return ["enabled" => false];');
        try { load_config_directory($root . '/config'); throw new LogicException('Unmigrated credentials were accepted.'); }
        catch (RuntimeException $exception) { $check(str_contains($exception->getMessage(), 'Core 3 migration requires')); }
    }
    file_put_contents($cachePath, '<?php return ' . var_export([
        'app' => ['live_config_fields' => ['developer_access' => ['users']]],
        'developer_access' => ['users' => 'stale-synthetic', 'enabled' => true],
    ], true) . ';');
    file_put_contents($root . '/config/developer_access.php', '<?php return ["users" => "rotated-synthetic"];');
    $config = load_config_directory($root . '/config');
    $check($config['developer_access']['users'] === 'rotated-synthetic');
    $check($config['developer_access']['enabled'] === true);
    // Fresh compilation also refuses an unmigrated declaration before changing the cache.
    $before = hash_file('sha256', $cachePath);
    $deny(static fn () => $command->handle([]));
    $check(hash_file('sha256', $cachePath) === $before);
    file_put_contents($root . '/config/app.php', '<?php return ["live_config_fields" => ["developer_access" => ["users"]]];');
    ob_start();
    try { $check($command->handle([]) === 0); }
    finally { ob_end_clean(); }
    $check(load_config_directory($root . '/config')['developer_access']['users'] === 'rotated-synthetic');
    file_put_contents($root . '/config/developer_access.php', '<?php return ["users" => "application-synthetic"];');
    $check(load_config_directory($root . '/config')['developer_access']['users'] === 'application-synthetic');
    unlink($root . '/defaults/developer_access.php');
    unlink($root . '/config/developer_access.php');
    $check(!array_key_exists('users', load_config_directory($root . '/config')['developer_access']));
    unlink($root . '/config/app.php');
    // Live-field declarations cannot traverse files or reload arbitrary namespaces.
    foreach (['invalid', ['../credentials' => ['users']], ['credentials' => ['../users']],
        ['credentials' => 'users'], ['credentials' => ['named' => 'users']],
        ['credentials' => [null]], ['credentials' => array_fill(0, 33, 'users')],
        array_fill_keys(array_map(static fn (int $n): string => 'group_' . $n, range(1, 33)), ['users'])] as $invalid) {
        file_put_contents($cachePath, '<?php return ' . var_export(['app' => ['live_config_fields' => $invalid]], true) . ';');
        $deny(static fn () => load_config_directory($root . '/config'));
    }
    file_put_contents($cachePath, '<?php return ["app" => ["live_config_fields" => ["credentials" => ["users"]]], "credentials" => "invalid"];');
    $deny(static fn () => load_config_directory($root . '/config'));
    file_put_contents($cachePath, '<?php return ["app" => ["live_config_fields" => ["credentials" => ["users"]]]];');
    file_put_contents($root . '/config/credentials.php', '<?php return "invalid";');
    $deny(static fn () => load_config_directory($root . '/config'));
    file_put_contents($root . '/config/credentials.php', '<?php return ["users" => null, "unselected" => "fresh"];');
    $config = load_config_directory($root . '/config');
    $check(array_key_exists('users', $config['credentials']) && $config['credentials']['users'] === null);
    $check(!array_key_exists('unselected', $config['credentials']));
    unlink($root . '/config/credentials.php');
    file_put_contents($root . '/outside-live.php', '<?php throw new RuntimeException("Linked configuration was evaluated.");');
    if (@symlink($root . '/outside-live.php', $root . '/config/credentials.php')) {
        // Check the denial diagnostic, so executing the target cannot pass this test.
        try { load_config_directory($root . '/config'); throw new LogicException('Linked live configuration accepted.'); }
        catch (RuntimeException $exception) { $check($exception->getMessage() === 'Linked live configuration is forbidden.'); }
        unlink($root . '/config/credentials.php');
    } elseif (getenv('FNLLA_REQUIRE_SYMLINK_TESTS') === '1') {
        throw new RuntimeException('Required live configuration symlink test unavailable.');
    } else {
        echo "[SKIP] Live configuration symlink (platform privileges unavailable).\n";
    }
    file_put_contents($cachePath, '<?php return ["credentials" => ["users" => "cached-without-opt-in"]];');
    file_put_contents($root . '/config/credentials.php', '<?php return ["users" => "not-selected"];');
    $check(load_config_directory($root . '/config')['credentials']['users'] === 'cached-without-opt-in');
    unlink($root . '/config/credentials.php');
    rename($root . '/defaults', $root . '/moved-defaults');
    try { $deny(static fn () => load_config_directory($root . '/config')); }
    finally { rename($root . '/moved-defaults', $root . '/defaults'); }
    unlink($cachePath);
    $GLOBALS['fnlla_config'] = ['view' => ['paths' => [$root . '/package/views']]];
    file_put_contents($root . '/package/views/screen.php', 'package screen');
    $check(View::render('screen', [], null) === 'package screen');
    file_put_contents($root . '/views/screen.php', 'application override');
    $check(View::render('screen', [], null) === 'application override');
    $GLOBALS['fnlla_config']['view']['paths'] = [null];
    $deny(static fn () => View::path('screen'));
    $GLOBALS['fnlla_config']['view']['paths'] = [$root . '/package/views'];
    $deny(static fn () => View::path('../config/sample'));
    $deny(static fn () => View::path('screen.php'));
    $deny(static fn () => View::path('/screen'));
    $deny(static fn () => View::path('screen\\other'));
    $deny(static fn () => View::path('missing'));
    $GLOBALS['fnlla_config']['view']['paths'] = [$root . '/nonexistent', $root . '/package/views'];
    unlink($root . '/views/screen.php');
    $check(View::render('screen', [], null) === 'package screen');
    foreach ([['named' => $root . '/package/views'], array_fill(0, 17, $root . '/package/views'), [null]] as $roots) {
        $GLOBALS['fnlla_config']['view']['paths'] = $roots;
        $deny(static fn () => View::path('screen'));
    }
    $GLOBALS['fnlla_config']['view']['paths'] = [$root . '/package/views'];
    file_put_contents($root . '/outside.php', 'private synthetic bytes');
    if (@symlink($root . '/outside.php', $root . '/package/views/escape.php')) {
        $deny(static fn () => View::path('escape'));
        unlink($root . '/package/views/escape.php');
    } elseif (getenv('FNLLA_REQUIRE_SYMLINK_TESTS') === '1') {
        throw new RuntimeException('Required package view symlink test unavailable.');
    } else {
        echo "[SKIP] Package view symlink escape (platform privileges unavailable).\n";
    }
    file_put_contents($root . '/.fnlla/package-distribution', 'synthetic');
    $deny(static fn () => new FrameworkUpdateTransaction($root));
    $deny(static fn () => new FrameworkUpdateTransaction($root, 'unknown'));
    $transaction = new FrameworkUpdateTransaction($root, 'package-publication');
    $deny(static fn () => $transaction->run(['app/Customer.php'], static fn () => null));
    $deny(static fn () => $transaction->run(['composer.lock'], static fn () => null));
    foreach (['.fnlla/update-transaction/recovery.php', 'public/uploads/customer.txt', 'config/../app/Customer.php', 'config/NUL.php'] as $path) {
        $invoked = false;
        $install = static function () use (&$invoked): void { $invoked = true; };
        $deny(static fn () => $transaction->run([$path], $install));
        $check(!$invoked);
    }
    $transaction->run(['config/published.php'], static function () use ($root): void {
        FrameworkUpdateTransaction::replace($root . '/config/published.php', 'approved package bytes');
    });
    $check(file_get_contents($root . '/config/published.php') === 'approved package bytes');
    $deny(static fn () => $transaction->run(['config/published.php'], static function () use ($root): void {
        FrameworkUpdateTransaction::replace($root . '/config/published.php', 'interrupted');
        throw new RuntimeException('Synthetic failure after publication.');
    }));
    $check(file_get_contents($root . '/config/published.php') === 'approved package bytes');
    $check(!is_file($root . '/.fnlla/update-transaction/journal.json'));
    unset($transaction);
    $child = $root . '/interrupted.php';
    file_put_contents($child, '<?php require ' . var_export(dirname(__DIR__) . '/src/Support/FrameworkUpdateTransaction.php', true)
        . '; $root=' . var_export($root, true) . '; $transaction=new \\Fnlla\\Php\\Support\\FrameworkUpdateTransaction($root,"package-publication");'
        . '$transaction->run(["config/published.php"],static function()use($root){file_put_contents($root."/config/published.php","hard-exit");exit(73);});');
    $process = proc_open([PHP_BINARY, $child], [0 => ['pipe', 'r'], 1 => ['file', $root . '/child.out', 'w'], 2 => ['file', $root . '/child.err', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run interrupted publication fixture.'); }
    fclose($pipes[0]); $check(proc_close($process) === 73);
    $check(is_file($root . '/.fnlla/update-transaction/journal.json'));
    $receipt = json_decode((string) file_get_contents($root . '/.fnlla/update-transaction/recovery.json'), true);
    $check($receipt['sha256'] === hash_file('sha256', $root . '/.fnlla/update-transaction/recovery.php'));
    $journalPath = $root . '/.fnlla/update-transaction/journal.json';
    $journal = (string) file_get_contents($journalPath);
    foreach (['framework', 'unknown', null] as $operation) {
        $damaged = json_decode($journal, true, 512, JSON_THROW_ON_ERROR);
        $damaged['operation'] = $operation;
        file_put_contents($journalPath, json_encode($damaged, JSON_THROW_ON_ERROR));
        $transaction = new FrameworkUpdateTransaction($root, 'package-publication');
        $deny(static fn () => $transaction->recover());
        $check(file_get_contents($root . '/config/published.php') === 'hard-exit' && is_file($journalPath));
        unset($transaction);
    }
    file_put_contents($journalPath, $journal);
    $recovery = $root . '/recover.php';
    file_put_contents($recovery, '<?php require ' . var_export($root . '/.fnlla/update-transaction/recovery.php', true)
        . '; (new \\Fnlla\\Php\\Support\\FrameworkUpdateTransaction(' . var_export($root, true) . ',"package-publication"))->recover();');
    $process = proc_open([PHP_BINARY, $recovery], [0 => ['pipe', 'r'], 1 => ['file', $root . '/recovery.out', 'w'], 2 => ['file', $root . '/recovery.err', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run standalone recovery fixture.'); }
    fclose($pipes[0]); $check(proc_close($process) === 0);
    $check(file_get_contents($root . '/config/published.php') === 'approved package bytes');
    $check(!is_file($root . '/.fnlla/update-transaction/journal.json'));
    echo 'Package runtime: PASS (' . $checks . " checks)\n";
} finally {
    unset($transaction);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
