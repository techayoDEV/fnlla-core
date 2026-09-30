<?php
declare(strict_types=1);
namespace App\Services;
use Fnlla\Php\Database\DatabaseManager;
final class CommitNotifier {
    public function schedule(DatabaseManager $database, callable $notification): void { $database->afterCommit($notification); }
}
