<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use RuntimeException;

/** Opt-in whole-database MySQL backup/restore. Requires exclusive ownership and stopped writers. */
final class MySqlDatabaseSnapshot
{
    public function __construct(private array $connection, private array $tools)
    {
        if (($connection['driver'] ?? '') !== 'mysql' || ($tools['exclusive_database'] ?? false) !== true
            || preg_match('/^[A-Za-z0-9_]+$/D', (string) ($connection['database'] ?? '')) !== 1) {
            throw new RuntimeException('Snapshot requires an exclusively owned MySQL database with a safe database name.');
        }
        if (trim((string) ($connection['read']['host'] ?? '')) !== '') {
            throw new RuntimeException('Automatic database snapshots do not coordinate read replicas.');
        }
        foreach (['mysql_command' => ['mysql'], 'mysqldump_command' => ['mysqldump']] as $name => $default) {
            $command = $tools[$name] ?? $default;
            if (!is_array($command) || $command === [] || ($command[0] ?? '') === '') {
                throw new RuntimeException('MySQL tooling must be a nonempty argv array.');
            }
            foreach ($command as $argument) {
                if (!is_string($argument) || str_contains($argument, "\0")) { throw new RuntimeException('Invalid MySQL tool argument.'); }
            }
        }
    }

    public function capture(string $directory): void
    {
        $this->execute($directory, false);
        $dump = $directory . '/database.sql';
        if (!is_file($dump) || filesize($dump) === 0) { throw new RuntimeException('Empty database snapshot.'); }
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($dump, 0600)) { throw new RuntimeException('Cannot protect database snapshot.'); }
        ApplicationSnapshot::write($directory . '/database.json', ['schema' => 'fnlla.mysql_snapshot.v1',
            'database' => $this->connection['database'], 'sha256' => hash_file('sha256', $dump)]);
        $this->verify($directory);
    }

    public function verify(string $directory): void
    {
        $record = ApplicationSnapshot::read($directory . '/database.json');
        $dump = $directory . '/database.sql';
        if (($record['schema'] ?? '') !== 'fnlla.mysql_snapshot.v1'
            || ($record['database'] ?? '') !== $this->connection['database'] || is_link($dump)
            || !is_file($dump) || hash_file('sha256', $dump) !== ($record['sha256'] ?? null)) {
            throw new RuntimeException('Database snapshot target or checksum mismatch.');
        }
    }

    public function restore(string $directory): void
    {
        $this->verify($directory);
        $this->execute($directory, true);
    }

    private function execute(string $directory, bool $restore): void
    {
        $credentials = tempnam($directory, '.mysql-');
        if ($credentials === false) { throw new RuntimeException('Cannot prepare private MySQL options.'); }
        try {
            $contents = "[client]\n";
            foreach (['host' => 'host', 'port' => 'port', 'username' => 'user', 'password' => 'password'] as $key => $option) {
                $value = (string) ($this->connection[$key] ?? '');
                if (preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new RuntimeException('Unsupported MySQL credential encoding.'); }
                $contents .= $option . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . "\"\n";
            }
            if (($this->connection['ssl']['enabled'] ?? false) === true) {
                $contents .= 'ssl-mode=' . (($this->connection['ssl']['verify_server_cert'] ?? true) ? 'VERIFY_IDENTITY' : 'REQUIRED') . "\n";
                foreach (['ca', 'cert', 'key'] as $key) {
                    $value = (string) ($this->connection['ssl'][$key] ?? '');
                    if ($value === '') { continue; }
                    if (preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new RuntimeException('Unsupported TLS option encoding.'); }
                    $contents .= 'ssl-' . $key . '=' . '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . "\"\n";
                }
            }
            if (file_put_contents($credentials, $contents) !== strlen($contents)) { throw new RuntimeException('Cannot write private MySQL options.'); }
            $prefix = (array) ($this->tools[$restore ? 'mysql_command' : 'mysqldump_command'] ?? [$restore ? 'mysql' : 'mysqldump']);
            if ($prefix === []) { throw new RuntimeException('MySQL tool command is missing.'); }
            $command = [...$prefix,
                '--defaults-file=' . $credentials, '--default-character-set=utf8mb4'];
            if ($restore) { $command = [...$command, '--binary-mode', '--skip-force']; }
            if (!$restore) {
                // DROP DATABASE ensures tables created by a failed migration cannot survive rollback.
                $command = [...$command, '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob',
                    '--set-gtid-purged=OFF', '--add-drop-database', '--databases', $this->connection['database']];
            }
            SnapshotProcess::run($command, $directory, (int) ($this->tools['timeout'] ?? 900),
                $restore ? $directory . '/database.sql' : null, $restore ? null : $directory . '/database.sql');
        } finally { if (is_file($credentials)) { unlink($credentials); } }
    }
}
