<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Actions\ActionStoreInterface;
use Fnlla\Php\Actions\ReliableOutboxStoreInterface;
use Fnlla\Php\Console\Command;
use Throwable;

final class OutboxStatusCommand extends Command
{
    public function name(): string { return "outbox:status"; }
    public function description(): string { return "Print bounded outbox delivery metadata without message payloads."; }
    public function handle(array $arguments): int
    {
        if ($arguments !== []) { $this->error("Usage: php fnlla outbox:status"); return 1; }
        try {
            $store = $this->container->make(ActionStoreInterface::class);
            if (!$store instanceof ReliableOutboxStoreInterface) { throw new \RuntimeException(); }
            $this->line(json_encode(["schema" => "fnlla.outbox.status.v1", "limit" => 100,
                "deliveries" => $store->deliveryStatus()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return 0;
        } catch (Throwable) { $this->error("Outbox status unavailable. Verify delivery capability and schema."); return 1; }
    }
}
