<?php

declare(strict_types=1);

// Main suite provides the package smoke bootstrap and safe process/file helpers.
if (!function_exists('run_process')) { require __DIR__ . '/CorePackageSmokeTest.php'; }

$resilienceTarget = sys_get_temp_dir() . '/fnlla-resilience-export-' . bin2hex(random_bytes(6));
try {
    (new \Fnlla\Php\Support\CoreProjectExporter())->export($resilienceTarget, 'Synthetic resilience export', 'synthetic-resilience');
    foreach (['config/resilience.php', 'bootstrap/emergency.php', 'public/emergency/503.html', 'docs/resilience.md',
        'packages/fnlla-core/src/Resilience/PublicPageCache.php', 'packages/fnlla-core/docs/resilience.md'] as $file) {
        assert_true(is_file($resilienceTarget . '/' . $file), 'Export omitted resilience file: ' . $file);
    }
    foreach ([['health'], ['fallback:generate'], ['down', '--message=<script>private</script>']] as $arguments) {
        [$exit, $output] = run_process([PHP_BINARY, 'fnlla', ...$arguments], $resilienceTarget);
        assert_same(0, $exit, 'Exported CLI failed: ' . implode(' ', $arguments) . ' ' . $output);
    }
    assert_true(is_file($resilienceTarget . '/storage/framework/resilience/emergency/503.html'), 'Emergency CLI did not generate artifact.');
    $invoke = static function (string $method) use ($resilienceTarget): array {
        $code = 'ini_set("error_log",' . var_export($resilienceTarget . '/bootstrap-errors.log', true) . ');$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';$_SERVER["REQUEST_URI"]="/";ob_start();'
            . 'register_shutdown_function(function(){ $body=ob_get_clean(); echo json_encode(["status"=>http_response_code(),"body"=>$body]); });'
            . 'require "public/index.php";';
        [$exit, $output] = run_process([PHP_BINARY, '-r', $code], $resilienceTarget);
        assert_same(0, $exit, 'Exported HTTP entry failed: ' . $output);
        return json_decode($output, true, 8, JSON_THROW_ON_ERROR);
    };
    $maintenance = $invoke('GET');
    assert_same(503, $maintenance['status'], 'Maintenance did not serve 503.');
    assert_true(str_contains($maintenance['body'], '&lt;script&gt;') && !str_contains($maintenance['body'], '<script>'), 'Maintenance message was not escaped.');
    assert_same('', $invoke('HEAD')['body'], 'Maintenance HEAD included body.');
    [$exit, $output] = run_process([PHP_BINARY, 'fnlla', 'up'], $resilienceTarget);
    assert_same(0, $exit, 'Exported up command failed: ' . $output);
    assert_true(!is_file($resilienceTarget . '/storage/framework/resilience/down.html'), 'Maintenance marker remained.');
    file_put_contents($resilienceTarget . '/bootstrap/app.php', '<?php throw new RuntimeException("private bootstrap secret");');
    $failure = $invoke('GET');
    assert_same(503, $failure['status'], 'Bootstrap failure was not controlled.');
    assert_true(!str_contains($failure['body'], 'private bootstrap secret'), 'Bootstrap error leaked.');
    echo "Resilience export: CLI health/fallback/down/up and preboot GET/HEAD/error guards passed.\n";
} finally { remove_directory($resilienceTarget); }
