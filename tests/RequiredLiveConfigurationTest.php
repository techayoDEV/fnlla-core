<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/fnlla-required-live-' . bin2hex(random_bytes(8));
foreach (['config', 'defaults', 'storage/framework/cache'] as $directory) { mkdir($root . '/' . $directory, 0700, true); }
define('APP_ROOT', $root);
define('FNLLA_CONFIG_DEFAULTS_ROOT', $root . '/defaults');
define('FNLLA_REQUIRED_LIVE_CONFIG_FIELDS', ['credentials' => ['users']]);
$cases = [];
$check = static function (string $name, bool $value) use (&$cases): void {
    if (!$value) { throw new RuntimeException('Required live configuration assertion failed: ' . $name); }
    $cases[] = $name;
};
$deny = static function (string $name, callable $operation) use ($check): void {
    try { $operation(); } catch (RuntimeException) { $check($name, true); return; }
    throw new RuntimeException('Invalid required configuration was accepted: ' . $name);
};
$cache = framework_config_cache_path();
try {
    file_put_contents($root . '/config/app.php', '<?php return ["name"=>"Old application"];');
    file_put_contents($root . '/defaults/credentials.php', '<?php return ["users"=>"revoked-package-default"];');
    file_put_contents($root . '/config/credentials.php', '<?php return ["users"=>"current-synthetic"];');
    file_put_contents($cache, '<?php return ["app"=>["name"=>"Old cache"],"credentials"=>["users"=>"stale-synthetic"]];');
    foreach ([true, false] as $cached) {
        $mode = $cached ? 'cached' : 'fresh';
        $config = load_config_directory($root . '/config', $cached);
        $check($mode . ': old application declarations retain required freshness', $config['credentials']['users'] === 'current-synthetic');
        $check($mode . ': effective declaration is exposed to integration guards', $config['app']['live_config_fields'] === ['credentials' => ['users']]);
    }
    file_put_contents($root . '/config/app.php', '<?php return ["live_config_fields"=>[]];');
    $check('empty application declaration cannot disable required fields', load_config_directory($root . '/config', false)['credentials']['users'] === 'current-synthetic');
    file_put_contents($root . '/config/credentials.php', '<?php return [];');
    foreach ([true, false] as $cached) {
        $check(($cached ? 'cached' : 'fresh') . ': required freshness never restores a package default', !array_key_exists('users', load_config_directory($root . '/config', $cached)['credentials']));
    }
    file_put_contents($root . '/config/app.php', '<?php return ["live_config_fields"=>null];');
    $deny('required policy cannot repair an invalid application declaration', static fn () => load_config_directory($root . '/config', false));
    $many = ['credentials' => array_map(static fn (int $n): string => 'field_' . $n, range(1, 32))];
    file_put_contents($root . '/config/app.php', '<?php return ' . var_export(['live_config_fields' => $many], true) . ';');
    $deny('combined fields remain bounded to32', static fn () => load_config_directory($root . '/config', false));
    $many = array_fill_keys(array_map(static fn (int $n): string => 'namespace_' . $n, range(1, 32)), ['field']);
    file_put_contents($root . '/config/app.php', '<?php return ' . var_export(['live_config_fields' => $many], true) . ';');
    $deny('combined namespaces remain bounded to32', static fn () => load_config_directory($root . '/config', false));
    echo json_encode(['suite' => 'required-live-configuration', 'status' => 'passed', 'assertions' => $cases], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
