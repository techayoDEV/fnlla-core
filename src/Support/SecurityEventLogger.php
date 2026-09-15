<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

final class SecurityEventLogger
{
    public static function write(string $event, array $context = []): void
    {
        if (!(bool) config("security.events.enabled", true)) {
            return;
        }

        $path = storage_path((string) config("security.events.path", "logs/security.log"));
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $payload = [
            "timestamp" => gmdate(DATE_ATOM),
            "event" => preg_replace('/[^a-z0-9_.-]+/i', "_", $event),
            "request_id" => request_id(),
            "context" => self::redact($context),
        ];

        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function redact(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;
            $safe[$key] = preg_match('/(password|secret|token|cookie|session|credential|key|authorization|csrf)/i', $key) === 1
                ? "[redacted]"
                : (is_scalar($value) || $value === null ? $value : "[complex]");
        }

        return $safe;
    }
}
