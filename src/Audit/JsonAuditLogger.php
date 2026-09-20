<?php

declare(strict_types=1);

namespace Fnlla\Php\Audit;

use RuntimeException;

final class JsonAuditLogger implements AuditLoggerInterface
{
    /** @param list<string> $allowedFields */
    public function __construct(
        private string $path,
        private array $allowedFields = [],
        private int $retentionDays = 90,
        private int $maximumEntries = 10000
    ) {
        $this->allowedFields = array_values(array_unique(array_filter(
            $this->allowedFields,
            static fn (mixed $field): bool => is_string($field) && preg_match('/^[a-z][a-z0-9_.-]*$/D', $field) === 1
        )));
        $this->retentionDays = max(1, $this->retentionDays);
        $this->maximumEntries = max(1, $this->maximumEntries);
    }

    public function record(AuditEvent $event): void
    {
        $payload = $event->toArray();
        $payload["before"] = $this->filterState((array) $payload["before"]);
        $payload["after"] = $this->filterState((array) $payload["after"]);

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create the audit log directory.");
        }

        $handle = fopen($this->path, "c+");
        if (!is_resource($handle)) {
            throw new RuntimeException("Unable to open the audit log.");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Unable to lock the audit log.");
            }
            rewind($handle);
            $existing = stream_get_contents($handle);
            $entries = $this->retainedEntries(is_string($existing) ? $existing : "");
            $entries[] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $entries = array_slice($entries, -$this->maximumEntries);
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, implode(PHP_EOL, $entries) . PHP_EOL) === false || !fflush($handle)) {
                throw new RuntimeException("Unable to write the audit log.");
            }
            @chmod($this->path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string, scalar|null> */
    private function filterState(array $state): array
    {
        $filtered = [];
        foreach ($state as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $this->allowedFields, true)) {
                continue;
            }
            $filtered[$key] = preg_match('/(password|secret|token|cookie|session|credential|authorization|csrf|key)/i', $key) === 1
                ? "[redacted]"
                : (is_scalar($value) || $value === null ? $value : "[complex]");
        }
        ksort($filtered, SORT_STRING);
        return $filtered;
    }

    /** @return list<string> */
    private function retainedEntries(string $contents): array
    {
        $cutoff = time() - ($this->retentionDays * 86400);
        $retained = [];
        foreach (preg_split('/\r?\n/', trim($contents)) ?: [] as $line) {
            if ($line === "") {
                continue;
            }
            $decoded = json_decode($line, true);
            $timestamp = is_array($decoded) ? strtotime((string) ($decoded["occurred_at"] ?? "")) : false;
            if ($timestamp !== false && $timestamp >= $cutoff) {
                $retained[] = $line;
            }
        }
        return array_slice($retained, -max(0, $this->maximumEntries - 1));
    }
}
