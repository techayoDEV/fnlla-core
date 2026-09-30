<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Actions\ActionStoreInterface;
use Fnlla\Php\Actions\ReliableOutboxStoreInterface;
use Fnlla\Php\Console\Command;
use Throwable;

final class OutboxRetryCommand extends Command
{
    public function name(): string { return "outbox:retry"; }
    public function description(): string { return "Explicitly retry one quarantined delivery; downstream effects must be idempotent."; }
    public function handle(array $arguments): int
    {
        if (count($arguments) !== 2 || ($arguments[1] ?? "") !== "--confirm"
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D', (string) ($arguments[0] ?? "")) !== 1) {
            $this->error("Usage: php fnlla outbox:retry <message-id> --confirm"); return 1;
        }
        try {
            $store = $this->container->make(ActionStoreInterface::class);
            if (!$store instanceof ReliableOutboxStoreInterface || !$store->retryDelivery($arguments[0])) {
                $this->error("Delivery is not quarantined or has already been published."); return 1;
            }
            $this->line("Delivery scheduled for retry.");
            return 0;
        } catch (Throwable) { $this->error("Outbox retry failed."); return 1; }
    }
}
