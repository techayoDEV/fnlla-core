<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/common.php';

// This example must be copied into a disposable application's scripts/ directory.
// A random table name prevents collision with existing integration records.
$table = (string) config('auth.providers.users.table');
if (env('FNLLA_DX_DISPOSABLE') !== '1' || config('database.connections.mysql.database') !== 'fnlla_core_test'
    || preg_match('/^dx_[a-f0-9]{12}_users$/D', $table) !== 1) {
    throw new RuntimeException('Developer exercise requires the dedicated disposable database and random table.');
}
$pdo = app(\Fnlla\Php\Database\DatabaseManager::class)->connection();
$mode = $argv[1] ?? 'seed';
if ($mode === 'cleanup') { $pdo->exec("DROP TABLE IF EXISTS `{$table}`"); return; }
if ($mode === 'revoke') { $pdo->exec("DELETE FROM `{$table}` WHERE email='editor@example.test'"); return; }
if ($mode === 'verify' || $mode === 'verify-revoked') {
    $expected = $mode === 'verify' ? [[1, 'editor@example.test', 'editor'], [2, 'reader@example.test', 'reader']] : [[2, 'reader@example.test', 'reader']];
    $rows = $pdo->query("SELECT id,email,role,password FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $actual = array_map(static fn (array $row): array => [(int) $row['id'], $row['email'], $row['role']], $rows);
    if ($actual !== $expected || array_filter($rows, static fn (array $row): bool => !password_verify('local-demo-only', $row['password'])) !== []) {
        throw new RuntimeException('Developer exercise data changed.');
    }
    return;
}
if ($mode === 'restore-editor') {
    $insert = $pdo->prepare("INSERT INTO `{$table}` (id,email,password,role) VALUES (1,?,?,?)");
    $insert->execute(['editor@example.test', password_hash('local-demo-only', PASSWORD_DEFAULT), 'editor']);
    return;
}
if ($mode !== 'seed') { throw new RuntimeException('Unknown developer exercise operation.'); }
$pdo->exec("CREATE TABLE `{$table}` (id BIGINT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role VARCHAR(32) NOT NULL)");
$insert = $pdo->prepare("INSERT INTO `{$table}` (id,email,password,role) VALUES (?,?,?,?)");
foreach ([[1, 'editor@example.test', 'editor'], [2, 'reader@example.test', 'reader']] as [$id, $email, $role]) {
    $insert->execute([$id, $email, password_hash('local-demo-only', PASSWORD_DEFAULT), $role]);
}
