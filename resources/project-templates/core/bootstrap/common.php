<?php

declare(strict_types=1);

define("APP_ROOT", dirname(__DIR__));
$autoload = APP_ROOT . "/vendor/autoload.php";
if (is_file($autoload)) {
    require_once $autoload;
} else {
    // Offline bootstrap uses the bundled package; Composer owns resolution after installation.
    spl_autoload_register(static function (string $class): void {
        foreach (["Fnlla\\Php\\" => ["src/", "packages/fnlla-core/src/", "packages/fnlla/src/"], "App\\" => ["app/"], "Database\\Seeders\\" => ["database/seeders/"], "Database\\Factories\\" => ["database/factories/"]] as $prefix => $directories) {
            if (str_starts_with($class, $prefix)) {
                foreach ($directories as $directory) {
                    $file = APP_ROOT . "/" . $directory . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
                    if (is_file($file)) {
                        require $file;
                        return;
                    }
                }
            }
        }
    });
}
define("FNLLA_ENGINE_ROOT", dirname((new ReflectionClass(\Fnlla\Php\Container\Container::class))->getFileName(), 3));
return require FNLLA_ENGINE_ROOT . "/bootstrap/common.php";
