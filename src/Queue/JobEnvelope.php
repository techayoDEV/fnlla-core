<?php

declare(strict_types=1);

namespace Fnlla\Php\Queue;

final class JobEnvelope
{
    public const SCHEMA = "fnlla.queue.v1";
    public const VERSION = 1;

    /** @param array<string, mixed> $metadata */
    public static function create(string $id, string $jobClass, array $payload, array $metadata = []): array
    {
        $context = is_array($metadata["context"] ?? null) ? $metadata["context"] : [];
        return self::normalize([
            "schema" => self::SCHEMA,
            "id" => $id,
            "job" => $jobClass,
            "job_type" => $metadata["job_type"] ?? $jobClass,
            "job_version" => $metadata["job_version"] ?? self::VERSION,
            "payload" => $payload,
            "context" => [
                "correlation_id" => $context["correlation_id"] ?? self::randomIdentifier(),
                "tenant_id" => $context["tenant_id"] ?? null,
                "actor_id" => $context["actor_id"] ?? null,
                "idempotency_key" => $context["idempotency_key"] ?? null,
            ],
        ], false);
    }

    /** @param array<string, mixed> $envelope */
    public static function normalize(array $envelope, bool $allowLegacy, ?string $fallbackId = null): array
    {
        if (!array_key_exists("schema", $envelope)) {
            if (!$allowLegacy) {
                throw new QueuePayloadException("Legacy queued job payload is disabled.");
            }
            $envelope = [
                ...$envelope,
                "schema" => self::SCHEMA,
                "id" => $envelope["id"] ?? $fallbackId,
                "job_type" => $envelope["job_type"] ?? ($envelope["job"] ?? null),
                "job_version" => $envelope["job_version"] ?? self::VERSION,
                "context" => $envelope["context"] ?? [
                    "correlation_id" => self::randomIdentifier(),
                    "tenant_id" => null,
                    "actor_id" => null,
                    "idempotency_key" => null,
                ],
                "legacy" => true,
            ];
        }

        if (($envelope["schema"] ?? null) !== self::SCHEMA) {
            throw new QueuePayloadException("Unsupported queued job envelope schema.");
        }
        self::identifier($envelope["id"] ?? null, "job ID", 160);
        self::jobClass($envelope["job"] ?? null);
        self::identifier($envelope["job_type"] ?? null, "job type", 160);
        if (($envelope["job_version"] ?? null) !== self::VERSION) {
            throw new QueuePayloadException("Unsupported queued job type version.");
        }
        if (!is_array($envelope["payload"] ?? null)) {
            throw new QueuePayloadException("Queued job payload must be an array.");
        }
        self::safeValue($envelope["payload"], 0);

        $context = $envelope["context"] ?? null;
        if (!is_array($context)) {
            throw new QueuePayloadException("Queued job context must be an object.");
        }
        self::identifier($context["correlation_id"] ?? null, "correlation ID", 128);
        foreach (["tenant_id", "actor_id", "idempotency_key"] as $key) {
            if (($context[$key] ?? null) !== null) {
                self::identifier($context[$key], str_replace("_", " ", $key), 128);
            }
            $context[$key] = $context[$key] ?? null;
        }
        $envelope["context"] = $context;

        return $envelope;
    }

    private static function identifier(mixed $value, string $label, int $maximum): string
    {
        if (!is_string($value) || strlen($value) < 1 || strlen($value) > $maximum
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@\\\\\/-]*$/D', $value) !== 1) {
            throw new QueuePayloadException("Invalid queued job " . $label . ".");
        }
        return $value;
    }

    private static function jobClass(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,254}$/D', $value) !== 1) {
            throw new QueuePayloadException("Invalid queued job class.");
        }
        return $value;
    }

    private static function safeValue(mixed $value, int $depth): void
    {
        if ($depth > 32) {
            throw new QueuePayloadException("Queued job payload nesting exceeds 32 levels.");
        }
        if (is_float($value) && !is_finite($value)) {
            throw new QueuePayloadException("Queued job payload contains a non-finite number.");
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return;
        }
        if (!is_array($value)) {
            throw new QueuePayloadException("Queued job payload contains an unsupported value.");
        }
        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                throw new QueuePayloadException("Queued job payload contains an invalid key.");
            }
            self::safeValue($item, $depth + 1);
        }
    }

    private static function randomIdentifier(): string
    {
        return "queue-" . bin2hex(random_bytes(16));
    }
}
