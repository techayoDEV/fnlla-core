<?php

declare(strict_types=1);

if (!in_array('--child', $argv, true)) {
    $arguments = [PHP_BINARY];
    if (!extension_loaded('Zend OPcache')) {
        $library = dirname(PHP_BINARY) . '/ext/php_opcache.dll';
        if (PHP_OS_FAMILY === 'Windows' && is_file($library)) { array_push($arguments, '-d', 'zend_extension=' . $library); }
        elseif (getenv('FNLLA_REQUIRE_OPCACHE_TESTS') === '1') { throw new RuntimeException('Required OPcache test module is unavailable.'); }
        else { echo "[SKIP] Configuration OPcache freshness (module unavailable).\n"; exit(0); }
    }
    array_push($arguments, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0',
        '-d', 'opcache.file_update_protection=0', '-d', 'opcache.file_cache=', __FILE__, '--child');
    foreach ([false, true] as $restricted) {
        $command = $arguments;
        if ($restricted) { array_splice($command, 1, 0, ['-d', 'opcache.restrict_api=' . sys_get_temp_dir() . '/fnlla-no-opcache-api']); $command[] = '--restricted'; }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start isolated OPcache configuration test.'); }
        fclose($pipes[0]);
        $exit = proc_close($process);
        if ($exit !== 0) { exit($exit); }
    }
    exit(0);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$restricted = in_array('--restricted', $argv, true);
if (!$restricted && (!function_exists('opcache_get_status') || (opcache_get_status(false)['opcache_enabled'] ?? false) !== true)) {
    throw new RuntimeException('OPcache configuration test did not enable bytecode caching.');
}
$root = sys_get_temp_dir() . '/fnlla-config-opcache-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
mkdir($root . '/storage/framework/cache', 0700, true);
define('APP_ROOT', $root);
$field = $root . '/config/credentials.php';
$cache = framework_config_cache_path();
$cases = [];
$check = static function (string $name, bool $value) use (&$cases): void {
    if (!$value) { throw new RuntimeException('OPcache configuration assertion failed: ' . $name); }
    $cases[] = $name;
};
try {
    file_put_contents($root . '/config/app.php', '<?php return ["live_config_fields"=>["credentials"=>["users"]]];');
    file_put_contents($cache, '<?php return ["app"=>["live_config_fields"=>["credentials"=>["users"]]],"credentials"=>["users"=>"compiled-old"]];');
    if ($restricted) {
        file_put_contents($field, '<?php $GLOBALS["live_file_evaluated"]=true; return ["users"=>"should-not-be-read"];');
        try { load_config_directory($root . '/config'); throw new LogicException('Restricted OPcache API accepted live configuration.'); }
        catch (RuntimeException $error) { $check('restricted bytecode API denies with a freshness diagnostic', $error->getMessage() === 'Configuration bytecode invalidation failed.'); }
        $check('restricted API does not evaluate credentials or fall back', !isset($GLOBALS['live_file_evaluated']));
        echo json_encode(['suite' => 'configuration-opcache-restricted', 'status' => 'passed', 'assertions' => $cases], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        return;
    }
    file_put_contents($field, '<?php return ["users"=>"old-synthetic"];');
    $check('fixture compiles the old live file', opcache_compile_file($field));
    $check('fixture returns old bytecode', (require $field)['users'] === 'old-synthetic');
    file_put_contents($field, '<?php return ["users"=>"rotated-synthetic"];');
    $check('fixture proves stale bytecode without loader', (require $field)['users'] === 'old-synthetic');
    $check('cached config refreshes rotated bytecode', load_config_directory($root . '/config')['credentials']['users'] === 'rotated-synthetic');
    $check('fresh config refreshes rotated bytecode', load_config_directory($root . '/config', false)['credentials']['users'] === 'rotated-synthetic');
    $check('fixture compiles before removal', opcache_compile_file($field));
    file_put_contents($field, '<?php return [];');
    $check('cached config removes a credential despite stale bytecode', !array_key_exists('users', load_config_directory($root . '/config')['credentials']));
    $check('fresh config removes a credential despite stale bytecode', !array_key_exists('users', load_config_directory($root . '/config', false)['credentials']));
    echo json_encode(['suite' => 'configuration-opcache', 'status' => 'passed', 'assertions' => $cases], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}
