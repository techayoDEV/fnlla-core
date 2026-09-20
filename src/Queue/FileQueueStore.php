<?php

declare(strict_types=1);

namespace Fnlla\Php\Queue;

use RuntimeException;

/** Local-disk, at-least-once queue. Persist the reservation before work begins. */
final class FileQueueStore implements ReliableQueueStoreInterface
{
    public function __construct(private string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create queue directory.");
        }
        $this->directory = (string) realpath($directory);
    }

    public function push(string $jobClass, array $payload = []): string
    {
        return $this->pushWithMetadata($jobClass, $payload);
    }

    public function pushWithMetadata(string $jobClass, array $payload = [], array $metadata = []): string
    {
        return $this->locked(function () use ($jobClass, $payload, $metadata): string {
            $id = gmdate("YmdHis") . "_" . bin2hex(random_bytes(8));
            $this->write($this->path($id), [...JobEnvelope::create($id, $jobClass, $payload, $metadata),
                "attempts" => 0, "max_attempts" => max(1, (int) config("queue.max_attempts", 1)),
                "available_at" => time(), "state" => "pending"]);
            return $id;
        });
    }

    public function pop(): ?array
    {
        return $this->locked(function (): ?array {
            $files = glob($this->directory . "/*.job") ?: [];
            sort($files);
            foreach ($files as $file) {
                try {
                    $payload = $this->read($file);
                } catch (QueuePayloadException $exception) {
                    $this->quarantine($file, "poison");
                    continue;
                }
                if (($payload["state"] ?? "pending") === "failed") {
                    $this->quarantine($file);
                    continue;
                }
                if ((int) ($payload["available_at"] ?? 0) > time()
                    || (int) ($payload["reserved_until"] ?? 0) > time()) {
                    continue;
                }
                $attempts = (int) ($payload["attempts"] ?? 0);
                $maximum = max(1, (int) ($payload["max_attempts"] ?? config("queue.max_attempts", 1)));
                if ($attempts >= $maximum) {
                    $payload["state"] = "failed";
                    $payload["last_error"] = "Reservation expired after the final attempt.";
                    $this->write($file, $payload);
                    $this->quarantine($file);
                    continue;
                }
                $payload["attempts"] = $attempts + 1;
                $payload["max_attempts"] = $maximum;
                $payload["state"] = "reserved";
                $payload["reservation"] = bin2hex(random_bytes(16));
                $payload["reserved_until"] = time() + max(1, (int) config("queue.visibility_timeout_seconds", 300));
                $this->write($file, $payload);
                return array_merge($payload, ["id" => pathinfo($file, PATHINFO_FILENAME), "source" => $file]);
            }
            return null;
        });
    }

    public function complete(array $job): void
    {
        $this->locked(function () use ($job): void {
            [$file] = $this->owned($job);
            if (!unlink($file)) { throw new RuntimeException("Cannot acknowledge queued job."); }
        });
    }

    public function fail(array $job): string
    {
        return $this->locked(function () use ($job): string {
            [$file, $payload] = $this->owned($job);
            $payload["last_error"] = substr((string) ($job["last_error"] ?? "Job failed."), 0, 4000);
            unset($payload["reservation"], $payload["reserved_until"]);
            $payload["state"] = $payload["attempts"] < $payload["max_attempts"] ? "pending" : "failed";
            $payload["available_at"] = time() + max(1, (int) config("queue.retry_backoff_seconds", 30));
            $this->write($file, $payload);
            return $payload["state"] === "failed" ? $this->quarantine($file) : $file;
        });
    }

    public function reject(array $job, string $reason): string
    {
        return $this->locked(function () use ($job, $reason): string {
            [$file, $payload] = $this->owned($job);
            $payload["last_error"] = substr($reason, 0, 4000);
            $payload["state"] = "failed";
            unset($payload["reservation"], $payload["reserved_until"]);
            $this->write($file, $payload);
            return $this->quarantine($file, "rejected");
        });
    }

    public function owns(array $job): bool
    {
        return $this->locked(function () use ($job): bool {
            try {
                $this->owned($job);
                return true;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function renew(array $job, int $leaseSeconds): array
    {
        return $this->locked(function () use ($job, $leaseSeconds): array {
            [$file, $payload] = $this->owned($job);
            $payload["reserved_until"] = time() + max(1, $leaseSeconds);
            $this->write($file, $payload);
            return array_merge($payload, [
                "id" => (string) $job["id"],
                "source" => $file,
            ]);
        });
    }

    public function beginIdempotent(array $job): string
    {
        $key = $this->idempotencyKey($job);
        if ($key === null) {
            return "untracked";
        }
        return $this->locked(function () use ($job, $key): string {
            $path = $this->idempotencyPath($key);
            $record = $this->readIdempotency($path);
            if (($record["state"] ?? null) === "completed" && (int) ($record["expires_at"] ?? 0) > time()) {
                return "completed";
            }
            if (($record["state"] ?? null) === "processing" && (int) ($record["expires_at"] ?? 0) > time()) {
                return "busy";
            }
            $this->writeIdempotency($path, [
                "state" => "processing",
                "reservation" => (string) ($job["reservation"] ?? ""),
                "expires_at" => time() + max(1, (int) config("queue.visibility_timeout_seconds", 300)),
            ]);
            return "claimed";
        });
    }

    public function completeIdempotent(array $job): void
    {
        $key = $this->idempotencyKey($job);
        if ($key === null) {
            return;
        }
        $this->locked(function () use ($job, $key): void {
            $path = $this->idempotencyPath($key);
            $record = $this->readIdempotency($path);
            if (($record["state"] ?? null) !== "processing"
                || !hash_equals((string) ($record["reservation"] ?? ""), (string) ($job["reservation"] ?? ""))) {
                throw new RuntimeException("Queue idempotency claim is missing or owned by another worker.");
            }
            $this->writeIdempotency($path, [
                "state" => "completed",
                "expires_at" => time() + max(1, (int) config("queue.idempotency_ttl_seconds", 86400)),
            ]);
        });
    }

    public function releaseIdempotent(array $job): void
    {
        $key = $this->idempotencyKey($job);
        if ($key === null) {
            return;
        }
        $this->locked(function () use ($job, $key): void {
            $path = $this->idempotencyPath($key);
            $record = $this->readIdempotency($path);
            if (($record["state"] ?? null) === "processing"
                && hash_equals((string) ($record["reservation"] ?? ""), (string) ($job["reservation"] ?? ""))) {
                @unlink($path);
            }
        });
    }

    public function pendingCount(): int
    {
        return $this->locked(function (): int {
            return count(array_filter(glob($this->directory . "/*.job") ?: [],
                fn (string $file): bool => ($this->read($file)["state"] ?? "pending") !== "failed"));
        });
    }

    public function failedCount(): int
    {
        return $this->locked(function (): int {
            $count = count(glob($this->directory . "/failed/*.failed.job") ?: []);
            foreach (glob($this->directory . "/*.job") ?: [] as $file) {
                if (($this->read($file)["state"] ?? "pending") === "failed") { $count++; }
            }
            return $count;
        });
    }

    private function owned(array $job): array
    {
        $file = $this->path((string) ($job["id"] ?? ""));
        $payload = is_file($file) ? $this->read($file) : [];
        if (($payload["state"] ?? "") !== "reserved" || !is_string($job["reservation"] ?? null)
            || !hash_equals((string) ($payload["reservation"] ?? ""), $job["reservation"])
            || (int) ($payload["reserved_until"] ?? 0) <= time()) {
            throw new RuntimeException("Queue reservation is missing, expired or owned by another worker.");
        }
        return [$file, $payload];
    }

    private function path(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $id) !== 1) { throw new RuntimeException("Invalid queued job ID."); }
        return $this->directory . DIRECTORY_SEPARATOR . $id . ".job";
    }

    private function read(string $file): array
    {
        if (is_link($file) || filesize($file) > 2097152) { throw new QueuePayloadException("Unsafe queued job file."); }
        try {
            $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new QueuePayloadException("Invalid queued job JSON.", 0, $exception);
        }
        if (!is_array($payload)) {
            throw new QueuePayloadException("Invalid queued job payload.");
        }
        return JobEnvelope::normalize(
            $payload,
            (bool) config("queue.accept_legacy_payloads", true),
            pathinfo($file, PATHINFO_FILENAME)
        );
    }

    private function write(string $file, array $payload): void
    {
        if (is_link($file)) { throw new RuntimeException("Queue files cannot be symbolic links."); }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 2097152) { throw new RuntimeException("Queued job exceeds 2 MiB."); }
        $temporary = tempnam($this->directory, ".job-");
        if ($temporary === false) { throw new RuntimeException("Cannot stage queued job."); }
        try {
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
                throw new RuntimeException("Cannot persist queued job.");
            }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    private function quarantine(string $file, string $suffix = "failed"): string
    {
        $directory = $this->directory . "/failed";
        if (is_link($directory)) { throw new RuntimeException("Failed queue directory cannot be a symbolic link."); }
        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create failed queue directory.");
        }
        $destination = $directory . "/" . pathinfo($file, PATHINFO_FILENAME) . "." . $suffix . ".failed.job";
        if (file_exists($destination) || !rename($file, $destination)) {
            throw new RuntimeException("Cannot quarantine queued job.");
        }
        return $destination;
    }

    private function locked(callable $operation): mixed
    {
        $path = $this->directory . "/.queue.lock";
        if (is_link($path)) { throw new RuntimeException("Queue lock cannot be a symbolic link."); }
        $lock = fopen($path, "c+b");
        if ($lock === false) { throw new RuntimeException("Cannot open queue lock."); }
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException("Cannot lock queue."); }
            clearstatcache();
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function idempotencyKey(array $job): ?string
    {
        $value = $job["context"]["idempotency_key"] ?? null;
        if (!is_string($value) || $value === "") {
            return null;
        }
        return hash("sha256", implode("\0", [
            (string) ($job["job_type"] ?? ""),
            (string) ($job["job_version"] ?? ""),
            (string) ($job["context"]["tenant_id"] ?? ""),
            $value,
        ]));
    }

    private function idempotencyPath(string $key): string
    {
        $directory = $this->directory . DIRECTORY_SEPARATOR . "idempotency";
        if (is_link($directory)) {
            throw new RuntimeException("Queue idempotency directory cannot be a symbolic link.");
        }
        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create queue idempotency directory.");
        }
        return $directory . DIRECTORY_SEPARATOR . $key . ".json";
    }

    private function readIdempotency(string $path): array
    {
        if (!is_file($path) || is_link($path)) {
            return [];
        }
        $record = json_decode((string) file_get_contents($path), true);
        return is_array($record) ? $record : [];
    }

    private function writeIdempotency(string $path, array $record): void
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = tempnam(dirname($path), ".idempotency-");
        if ($temporary === false) {
            throw new RuntimeException("Cannot stage queue idempotency record.");
        }
        try {
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
                throw new RuntimeException("Cannot persist queue idempotency record.");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
