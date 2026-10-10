<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
spl_autoload_register(static function (string $class): void {
    $path = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen('Fnlla\\Php\\'))) . '.php';
    if (str_starts_with($class, 'Fnlla\\Php\\') && is_file($path)) { require $path; }
});
require APP_ROOT . '/src/Support/helpers.php';
config_set('app.log_path', $argv[1]);
config_set('logging.native_errors_enabled', false);
\Fnlla\Php\Exceptions\RuntimeErrorHandling::install();
ini_set('display_errors', '0');
if (($argv[2] ?? '') === 'fatal') { trigger_error('SYNTHETIC_PRIVATE_DIAGNOSTIC', E_USER_ERROR); }
if (($argv[2] ?? '') === 'parse') { eval('invalid SYNTHETIC_PRIVATE_DIAGNOSTIC'); }
throw new RuntimeException('SYNTHETIC_PRIVATE_DIAGNOSTIC');
