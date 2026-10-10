<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Fnlla\Php\Support\MySqlDatabaseSnapshot;

// Only the isolated service-test credentials may be used. Never reads application .env.
$dsn = getenv('FNLLA_CORE_TEST_MYSQL_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('Requires explicitly configured isolated MySQL service-test credentials.');
}
$parts = [];
foreach (explode(';', substr($dsn, 6)) as $part) {
    $pair = explode('=', $part, 2);
    if (count($pair) === 2) { $parts[$pair[0]] = $pair[1]; }
}
$user = (string) getenv('FNLLA_CORE_TEST_MYSQL_USER');
$password = (string) getenv('FNLLA_CORE_TEST_MYSQL_PASSWORD');
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'fnlla_snapshot_it_' . bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . '/' . $name;
mkdir($directory, 0700);
$check = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
try {
    $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");
    $pdo->exec("USE `{$name}`");
    $pdo->exec('CREATE TABLE orders (id INT PRIMARY KEY, payload VARBINARY(255)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE audit (id INT PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TRIGGER orders_audit AFTER INSERT ON orders FOR EACH ROW INSERT INTO audit VALUES (NEW.id)');
    $pdo->exec("INSERT INTO orders VALUES (1, UNHEX('00ff1a'))");
    $pdo->exec('CREATE VIEW order_count AS SELECT COUNT(*) AS n FROM orders');
    $pdo->exec('CREATE PROCEDURE count_orders() SELECT COUNT(*) FROM orders');
    $pdo->exec("CREATE EVENT snapshot_event ON SCHEDULE EVERY 1 DAY DISABLE DO SELECT 1");
    $snapshot = new MySqlDatabaseSnapshot(['driver' => 'mysql', 'database' => $name,
        'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? '3306',
        'username' => $user, 'password' => $password], ['exclusive_database' => true]);
    $snapshot->capture($directory);
    $check(!glob($directory . '/.mysql-*'), 'Transient credentials survived capture.');
    $pdo->exec("INSERT INTO orders VALUES (2, 'new-order')");
    $pdo->exec('ALTER TABLE orders ADD COLUMN failed_migration INT');
    $pdo->exec('CREATE TABLE failed_new_table (id INT)');
    $pdo = null; // Restore drops/recreates the target; use a fresh connection afterward.
    $snapshot->restore($directory);
    $serverDsn = 'mysql:host=' . ($parts['host'] ?? '127.0.0.1') . ';port=' . ($parts['port'] ?? '3306') . ';dbname=' . $name;
    $pdo = new PDO($serverDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $check((int) $pdo->query('SELECT n FROM order_count')->fetchColumn() === 1, 'Original rows/view not restored.');
    $check($pdo->query('SELECT HEX(payload) FROM orders')->fetchColumn() === '00FF1A', 'Binary values not restored.');
    $check((int) $pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn() === 1, 'Trigger/audit snapshot inconsistent.');
    $check($pdo->query("SHOW TABLES LIKE 'failed_new_table'")->fetchColumn() === false, 'New table survived rollback.');
    $check($pdo->query("SHOW COLUMNS FROM orders LIKE 'failed_migration'")->fetchColumn() === false, 'Failed DDL survived rollback.');
    $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='{$name}'")->fetchColumn() === 1, 'Routine not restored.');
    $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='{$name}'")->fetchColumn() === 1, 'Event not restored.');
    $pdo->exec("INSERT INTO orders VALUES (3, 'after-restore')");
    $check((int) $pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn() === 2, 'Restored trigger does not execute.');
    echo "Real MySQL snapshot: rows, binary data, DDL, views, triggers, routines and events restored.\n";
} finally {
    $cleanup = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cleanup->exec("DROP DATABASE IF EXISTS `{$name}`");
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    foreach (glob($directory . '/.mysql-*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
}
