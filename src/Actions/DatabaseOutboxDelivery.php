<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use PDO;
use RuntimeException;

/** MySQL 8+ delivery state is separate from the published receipt/outbox schema. */
trait DatabaseOutboxDelivery
{
    public function installDeliverySchema(): void
    {
        $this->assertOutsideDeliveryTransaction();
        $table = $this->deliveryTable();
        $this->database->statement(
            "CREATE TABLE IF NOT EXISTS {$table} ("
            . "message_id VARCHAR(96) PRIMARY KEY, state VARCHAR(16) NOT NULL, attempts INT NOT NULL DEFAULT 0, "
            . "token VARCHAR(64) NULL, leased_until DATETIME(6) NULL, available_at DATETIME(6) NOT NULL, "
            . "last_error_code VARCHAR(64) NULL, retry_count INT NOT NULL DEFAULT 0, last_retry_at DATETIME(6) NULL, "
            . "INDEX delivery_ready (state, available_at, leased_until)) ENGINE=InnoDB"
        );
    }

    public function claimDelivery(int $leaseSeconds, int $maximumAttempts): ?array
    {
        $this->assertOutsideDeliveryTransaction();
        $leaseSeconds = max(1, min(3600, $leaseSeconds));
        $maximumAttempts = max(1, min(100, $maximumAttempts));
        $delivery = $this->deliveryTable();
        $outbox = chr(96) . $this->outboxTable . chr(96);
        return $this->database->transaction(function () use ($leaseSeconds, $maximumAttempts, $delivery, $outbox): ?array {
            $pdo = $this->database->connection();
            $row = $pdo->query(
                "SELECT o.message_id, o.kind, o.message_name, o.payload_json, COALESCE(d.attempts, 0) AS attempts "
                . "FROM {$outbox} o LEFT JOIN {$delivery} d ON d.message_id = o.message_id "
                . "WHERE o.published_at IS NULL AND (d.message_id IS NULL "
                . "OR (d.state = 'pending' AND d.available_at <= UTC_TIMESTAMP(6)) "
                . "OR (d.state = 'processing' AND d.leased_until <= UTC_TIMESTAMP(6))) "
                . "ORDER BY o.created_at, o.message_id LIMIT 1 FOR UPDATE SKIP LOCKED"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) { return null; }
            $token = bin2hex(random_bytes(24));
            $attempts = min($maximumAttempts + 1, (int) $row["attempts"] + 1);
            $statement = $pdo->prepare(
                "INSERT INTO {$delivery} (message_id, state, attempts, token, leased_until, available_at) "
                . "VALUES (?, 'processing', ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL {$leaseSeconds} SECOND), UTC_TIMESTAMP(6)) "
                . "ON DUPLICATE KEY UPDATE state = 'processing', attempts = ?, token = ?, "
                . "leased_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL {$leaseSeconds} SECOND)"
            );
            $statement->execute([$row["message_id"], $attempts, $token, $attempts, $token]);
            $payload = json_decode((string) $row["payload_json"], true, 64);
            return ["id" => (string) $row["message_id"], "kind" => (string) $row["kind"],
                "name" => (string) $row["message_name"], "payload" => is_array($payload) ? $payload : null,
                "token" => $token, "attempts" => $attempts];
        });
    }

    public function ownsDelivery(string $id, string $token): bool
    {
        $this->assertOutsideDeliveryTransaction();
        $table = $this->deliveryTable();
        $statement = $this->database->connection()->prepare(
            "SELECT 1 FROM {$table} WHERE message_id = ? AND token = ? AND state = 'processing' AND leased_until > UTC_TIMESTAMP(6)"
        );
        $statement->execute([$id, $token]);
        return $statement->fetchColumn() !== false;
    }

    public function acknowledgeDelivery(string $id, string $token): void
    {
        $this->assertOutsideDeliveryTransaction();
        $table = $this->deliveryTable();
        $outbox = chr(96) . $this->outboxTable . chr(96);
        $this->database->transaction(function () use ($table, $outbox, $id, $token): void {
            $statement = $this->database->connection()->prepare(
                "UPDATE {$table} SET state = 'published', token = NULL, leased_until = NULL, last_error_code = NULL "
                . "WHERE message_id = ? AND token = ? AND state = 'processing' AND leased_until > UTC_TIMESTAMP(6)"
            );
            $statement->execute([$id, $token]);
            if ($statement->rowCount() !== 1) { throw new RuntimeException("Outbox delivery lease was lost."); }
            $statement = $this->database->connection()->prepare(
                "UPDATE {$outbox} SET published_at = COALESCE(published_at, UTC_TIMESTAMP(6)) WHERE message_id = ?"
            );
            $statement->execute([$id]);
            if ($statement->rowCount() !== 1) { throw new RuntimeException("Outbox message was not pending."); }
        });
    }

    public function failDelivery(string $id, string $token, string $code, bool $retryable, int $maximumAttempts, int $delaySeconds): void
    {
        $this->assertOutsideDeliveryTransaction();
        if (!in_array($code, ["invalid_payload", "invalid_kind", "attempts_exhausted", "delivery_failed"], true)) {
            throw new RuntimeException("Unsupported outbox error code.");
        }
        $table = $this->deliveryTable();
        $delay = max(1, min(3600, $delaySeconds));
        $maximum = $retryable ? max(1, min(100, $maximumAttempts)) : 0;
        $statement = $this->database->connection()->prepare(
            "UPDATE {$table} SET state = IF(attempts >= ?, 'failed', 'pending'), "
            . "available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL {$delay} SECOND), last_error_code = ?, token = NULL, leased_until = NULL "
            . "WHERE message_id = ? AND token = ? AND state = 'processing' AND leased_until > UTC_TIMESTAMP(6)"
        );
        $statement->execute([$maximum, $code, $id, $token]);
        if ($statement->rowCount() !== 1) { throw new RuntimeException("Outbox delivery lease was lost."); }
    }

    public function deliveryStatus(int $limit = 100): array
    {
        $this->assertOutsideDeliveryTransaction();
        $limit = max(1, min(1000, $limit));
        $table = $this->deliveryTable();
        $outbox = chr(96) . $this->outboxTable . chr(96);
        return $this->database->connection()->query(
            "SELECT o.message_id AS id, o.kind, COALESCE(d.state, 'pending') AS state, COALESCE(d.attempts, 0) AS attempts, "
            . "d.available_at, d.leased_until, d.last_error_code, COALESCE(d.retry_count, 0) AS retry_count, d.last_retry_at "
            . "FROM {$outbox} o LEFT JOIN {$table} d ON o.message_id = d.message_id "
            . "WHERE o.published_at IS NULL ORDER BY o.created_at, o.message_id LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function retryDelivery(string $id): bool
    {
        $this->assertOutsideDeliveryTransaction();
        $table = $this->deliveryTable();
        $outbox = chr(96) . $this->outboxTable . chr(96);
        $statement = $this->database->connection()->prepare(
            "UPDATE {$table} d INNER JOIN {$outbox} o ON d.message_id = o.message_id "
            . "SET d.state = 'pending', d.attempts = 0, d.available_at = UTC_TIMESTAMP(6), "
            . "d.retry_count = d.retry_count + 1, d.last_retry_at = UTC_TIMESTAMP(6) "
            . "WHERE d.message_id = ? AND d.state = 'failed' AND o.published_at IS NULL"
        );
        $statement->execute([$id]);
        return $statement->rowCount() === 1;
    }

    private function deliveryTable(): string
    {
        return chr(96) . $this->table((string) config("actions.delivery_table", "fnlla_outbox_deliveries")) . chr(96);
    }

    private function assertOutsideDeliveryTransaction(): void
    {
        if ($this->database->hasActiveManagedTransaction() || $this->database->connection()->inTransaction()) {
            throw new RuntimeException("Outbox delivery must run outside application transactions.");
        }
    }
}
