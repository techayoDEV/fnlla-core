<?php

declare(strict_types=1);

namespace Fnlla\Php\Product {
    // Inject failure at the publication boundary without touching other filesystem operations.
    function rename(string $from, string $to): bool
    {
        if ($to === ($GLOBALS['module_atomic_path'] ?? null) && ($GLOBALS['module_atomic_fail'] ?? false)) {
            throw new \RuntimeException('Synthetic publication interruption');
        }
        return \rename($from, $to);
    }
    function unlink(string $path): bool
    {
        if ($path === ($GLOBALS['module_atomic_path'] ?? null)) {
            throw new \RuntimeException('Existing module state must never be deleted before publication');
        }
        return \unlink($path);
    }
}
namespace {
    if (!function_exists('config')) { require_once dirname(__DIR__) . '/vendor/autoload.php'; }
    if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }
    $atomicDirectory = sys_get_temp_dir() . '/fnlla-module-atomic-' . bin2hex(random_bytes(6));
    mkdir($atomicDirectory, 0700, true);
    $atomicPath = $atomicDirectory . '/state.json';
    $GLOBALS['module_atomic_path'] = $atomicPath;
    $atomicPayload = json_encode(['schema' => 'fnlla.product-module-state.v1', 'enabled' => []], JSON_THROW_ON_ERROR);
    file_put_contents($atomicPath, $atomicPayload);
    $atomicReflection = new ReflectionClass(\Fnlla\Php\Product\ProductModuleRegistry::class);
    $atomicModules = ['sample.module' => ['id' => 'sample.module', 'default_enabled' => true, 'depends_on' => []]];
    $atomicRegistry = static function () use ($atomicReflection, $atomicModules, $atomicPath): object {
        $registry = $atomicReflection->newInstanceWithoutConstructor();
        foreach (['statePath' => $atomicPath, 'snapshot' => ['report' => ['valid' => true], 'modules' => $atomicModules]] as $key => $value) {
            $atomicReflection->getProperty($key)->setValue($registry, $value);
        }
        return $registry;
    };
    try {
        $GLOBALS['module_atomic_fail'] = true;
        try {
            $atomicRegistry()->enable('sample.module');
            throw new LogicException('Expected publication failure.');
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'Synthetic publication interruption') { throw $error; }
        }
        if (file_get_contents($atomicPath) !== $atomicPayload || glob($atomicPath . '.tmp-*') !== []) {
            throw new RuntimeException('Failed replacement destroyed state or left staging data.');
        }
        if ($atomicReflection->getMethod('readState')->invoke($atomicRegistry(), $atomicModules) !== []) {
            throw new RuntimeException('Failed replacement enabled a default module.');
        }
        $GLOBALS['module_atomic_fail'] = false;
        $atomicRegistry()->enable('sample.module');
        if ($atomicReflection->getMethod('readState')->invoke($atomicRegistry(), $atomicModules) !== ['sample.module']) {
            throw new RuntimeException('Atomic replacement did not publish new state.');
        }
        $atomicRegistry()->disable('sample.module');
        if ($atomicReflection->getMethod('readState')->invoke($atomicRegistry(), $atomicModules) !== []) {
            throw new RuntimeException('Atomic disable failed.');
        }
        echo 'Product Module atomic publication passed on ' . PHP_OS_FAMILY . '.' . PHP_EOL;
    } finally {
        unset($GLOBALS['module_atomic_path'], $GLOBALS['module_atomic_fail']);
        foreach (glob($atomicDirectory . '/*') ?: [] as $file) { unlink($file); }
        rmdir($atomicDirectory);
    }
}
