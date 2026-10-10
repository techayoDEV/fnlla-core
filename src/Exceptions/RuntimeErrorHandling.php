<?php

declare(strict_types=1);

namespace Fnlla\Php\Exceptions;

use Fnlla\Php\Support\Logger;
use Throwable;

final class RuntimeErrorHandling
{
    public static function install(): void
    {
        // PHP engine text bypasses application redaction. Native logging is explicit opt-in.
        $native = (bool) config('logging.native_errors_enabled', false);
        if ($native) {
            $path = (string) config('logging.native_error_path', storage_path('logs/native-errors.log'));
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('Cannot prepare private native diagnostics.');
            }
            if (is_link($path) || (!is_file($path) && file_put_contents($path, '') === false)) {
                throw new \RuntimeException('Cannot prepare private native diagnostics.');
            }
            @chmod($path, 0600);
            ini_set('error_log', $path);
        }
        ini_set('log_errors', $native ? '1' : '0');
        set_exception_handler(static function (Throwable $error): never {
            ExceptionReporting::report($error, ['request_id' => request_id(), 'phase' => 'uncaught']);
            if (PHP_SAPI === 'cli') { fwrite(STDERR, "FNLLA failed; inspect private diagnostics.\n"); }
            else {
                if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); }
                echo 'The application could not complete this request.';
            }
            exit(1);
        });
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!is_array($error) || !in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) { return; }
            try {
                Logger::write('critical', 'Fatal error', ['request_id' => request_id(), 'type' => $error['type'],
                    'file' => $error['file'] ?? null, 'line' => $error['line'] ?? null]);
            } catch (Throwable) { @error_log('FNLLA fatal diagnostics unavailable.'); }
        });
    }
}
