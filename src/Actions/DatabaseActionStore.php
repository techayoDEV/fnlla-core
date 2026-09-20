<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Database\DatabaseManager;
use PDO;
use RuntimeException;

final class DatabaseActionStore implements ActionStoreInterface
{
    private string $receiptsTable;
    private string $outboxTable;

    public function __construct(private DatabaseManager $database)
    {
        $this->receiptsTable = $this->table((string) config("actions.receipts_table", "fnlla_action_receipts"));
        $this->outboxTable = $this->table((string) config("actions.outbox_table", "fnlla_action_outbox"));
    }

    public function claim(string $idempotencyKey, string $actionId, string $requestHash, ActionContext $context): ?array
    {
        $this->assertTransaction();
        $statement = $this->database->connection()->prepare(
            "INSERT IGNORE INTO `{$this->receiptsTable}` "
            . "(idempotency_key, action_id, request_hash, context_json, status, result_json, created_at, completed_at) "
            . "VALUES (?, ?, ?, ?, 'pending', NULL, UTC_TIMESTAMP(6), NULL)"
        );
        $statement->execute([
            $idempotencyKey,
            $actionId,
            $requestHash,
            json_encode($context->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        if ($statement->rowCount() === 1) {
            return null;
        }

        $statement = $this->database->connection()->prepare(
            "SELECT action_id, request_hash, status, result_json FROM `{$this->receiptsTable}` WHERE idempotency_key = ? FOR UPDATE"
        );
        $statement->execute([$idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row["action_id"], $actionId)
            || !hash_equals((string) $row["request_hash"], $requestHash)) {
            throw new RuntimeException("Action idempotency key was reused with different input.");
        }
        if (($row["status"] ?? null) !== "completed" || !is_string($row["result_json"] ?? null)) {
            throw new RuntimeException("Action idempotency claim is incomplete.");
        }
        $result = json_decode($row["result_json"], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException("Stored action result is invalid.");
        }
        return $result;
    }

    public function complete(string $idempotencyKey, array $result): void
    {
        $this->assertTransaction();
        $statement = $this->database->connection()->prepare(
            "UPDATE `{$this->receiptsTable}` SET status = 'completed', result_json = ?, completed_at = UTC_TIMESTAMP(6) "
            . "WHERE idempotency_key = ? AND status = 'pending'"
        );
        $statement->execute([json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $idempotencyKey]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException("Action idempotency claim could not be completed.");
        }
    }

    public function append(string $id, string $kind, string $name, array $payload): void
    {
        $this->assertTransaction();
        if (!in_array($kind, ["audit", "domain_event"], true)) {
            throw new RuntimeException("Unsupported action outbox kind.");
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->database->connection()->prepare(
            "INSERT IGNORE INTO `{$this->outboxTable}` "
            . "(message_id, kind, message_name, payload_json, created_at, published_at) "
            . "VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), NULL)"
        );
        $statement->execute([$id, $kind, $name, $json]);
        if ($statement->rowCount() === 1) {
            return;
        }
        $statement = $this->database->connection()->prepare(
            "SELECT kind, message_name, payload_json FROM `{$this->outboxTable}` WHERE message_id = ? FOR UPDATE"
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row["kind"], $kind)
            || !hash_equals((string) $row["message_name"], $name)
            || !hash_equals((string) $row["payload_json"], $json)) {
            throw new RuntimeException("Outbox message ID collision.");
        }
    }

    public function pending(int $limit = 100): array
    {
        if ($this->database->hasActiveManagedTransaction()) {
            throw new RuntimeException("Outbox publication must run after commit.");
        }
        $limit = max(1, min(1000, $limit));
        $statement = $this->database->connection()->query(
            "SELECT message_id, kind, message_name, payload_json FROM `{$this->outboxTable}` "
            . "WHERE published_at IS NULL ORDER BY created_at, message_id LIMIT {$limit}"
        );
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) ($row["payload_json"] ?? ""), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException("Stored outbox payload is invalid.");
            }
            $rows[] = [
                "id" => (string) $row["message_id"],
                "kind" => (string) $row["kind"],
                "name" => (string) $row["message_name"],
                "payload" => $payload,
            ];
        }
        return $rows;
    }

    public function markPublished(string $id): void
    {
        if ($this->database->hasActiveManagedTransaction()) {
            throw new RuntimeException("Outbox acknowledgement must run after commit.");
        }
        $statement = $this->database->connection()->prepare(
            "UPDATE `{$this->outboxTable}` SET published_at = COALESCE(published_at, UTC_TIMESTAMP(6)) WHERE message_id = ?"
        );
        $statement->execute([$id]);
        if ($statement->rowCount() > 1) {
            throw new RuntimeException("Outbox acknowledgement was not unique.");
        }
    }

    public function installSchema(): void
    {
        if ($this->database->connection()->inTransaction()) {
            throw new RuntimeException("Install the action/outbox schema outside a transaction.");
        }
        $this->database->statement(
            "CREATE TABLE IF NOT EXISTS `{$this->receiptsTable}` ("
            . "idempotency_key VARCHAR(64) PRIMARY KEY, action_id VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, "
            . "context_json LONGTEXT NOT NULL, status VARCHAR(16) NOT NULL, result_json LONGTEXT NULL, "
            . "created_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL, INDEX action_completed (action_id, completed_at)"
            . ") ENGINE=InnoDB"
        );
        $this->database->statement(
            "CREATE TABLE IF NOT EXISTS `{$this->outboxTable}` ("
            . "message_id VARCHAR(96) PRIMARY KEY, kind VARCHAR(32) NOT NULL, message_name VARCHAR(128) NOT NULL, "
            . "payload_json LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL, published_at DATETIME(6) NULL, "
            . "INDEX outbox_pending (published_at, created_at, message_id)"
            . ") ENGINE=InnoDB"
        );
    }

    private function assertTransaction(): void
    {
        if (!$this->database->hasActiveManagedTransaction() || !$this->database->connection()->inTransaction()) {
            throw new RuntimeException("Action receipts and outbox writes require an active managed transaction.");
        }
    }

    private function table(string $table): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $table) !== 1) {
            throw new RuntimeException("Invalid action storage table name.");
        }
        return $table;
    }
}
