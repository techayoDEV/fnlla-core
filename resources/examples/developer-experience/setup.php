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
if ($mode === 'verify') {
    if ((int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() !== 2) {
        throw new RuntimeException('Developer exercise data changed.');
    }
    return;
}
if ($mode !== 'seed') { throw new RuntimeException('Unknown developer exercise operation.'); }
$pdo->exec("CREATE TABLE `{$table}` (id BIGINT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role VARCHAR(32) NOT NULL)");
$insert = $pdo->prepare("INSERT INTO `{$table}` (id,email,password,role) VALUES (?,?,?,?)");
foreach ([[1, 'editor@example.test', 'editor'], [2, 'reader@example.test', 'reader']] as [$id, $email, $role]) {
    $insert->execute([$id, $email, password_hash('local-demo-only', PASSWORD_DEFAULT), $role]);
}
