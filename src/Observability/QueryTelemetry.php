<?php

declare(strict_types=1);

namespace Fnlla\Php\Observability;

use PDOStatement;

final class QueryTelemetry
{
    private static bool $enabled = false;
    private static array $queries = [];
    private static int $count = 0;
    private static float $duration = 0;

    public static function reset(bool $enabled = false): void
    {
        self::$enabled = $enabled;
        self::$queries = [];
        self::$count = 0;
        self::$duration = 0;
    }

    public static function execute(PDOStatement $statement, array $bindings = []): bool
    {
        if (!self::$enabled) {
            return $statement->execute($bindings);
        }
        $started = hrtime(true);
        $ok = false;
        try {
            return $ok = $statement->execute($bindings);
        } finally {
            $duration = (hrtime(true) - $started) / 1000000;
            self::$count++;
            self::$duration += $duration;
            if (count(self::$queries) < 100) {
                // Never retain SQL text, bindings, identifiers or exception messages.
                preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i', $statement->queryString, $match);
                self::$queries[] = ["operation" => strtoupper($match[1] ?? "QUERY"), "duration_ms" => round($duration, 2), "ok" => $ok];
            }
        }
    }

    public static function snapshot(): array
    {
        return ["count" => self::$count, "duration_ms" => round(self::$duration, 2), "queries" => self::$queries];
    }
}
