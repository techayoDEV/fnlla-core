<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Actions\ActionStoreInterface;
use Fnlla\Php\Actions\ReliableOutboxStoreInterface;
use Fnlla\Php\Audit\AuditLoggerInterface;
use Fnlla\Php\Console\Command;
use Fnlla\Php\Events\DomainEventBus;
use Fnlla\Php\Events\OutboxWorker;
use Throwable;

final class OutboxWorkCommand extends Command
{
    public function name(): string { return "outbox:work"; }
    public function description(): string { return "Deliver committed outbox messages with leases, bounded retries and quarantine."; }
    public function handle(array $arguments): int
    {
        $limit = 100; $seconds = 30;
        foreach ($arguments as $argument) {
            if (preg_match('/^--limit=([1-9][0-9]{0,3}|10000)$/D', $argument, $match) === 1) { $limit = (int) $match[1]; }
            elseif (preg_match('/^--max-seconds=([1-9][0-9]{0,2}|[12][0-9]{3}|3[0-5][0-9]{2}|3600)$/D', $argument, $match) === 1) { $seconds = (int) $match[1]; }
            else { $this->error("Usage: php fnlla outbox:work [--limit=100] [--max-seconds=30]"); return 1; }
        }
        try {
            if (!(bool) config("actions.reliable_outbox", false)) {
                $this->error("Enable actions.reliable_outbox after installing the delivery schema and stopping legacy publishers."); return 1;
            }
            $store = $this->container->make(ActionStoreInterface::class);
            if (!$store instanceof ReliableOutboxStoreInterface) {
                $this->error("Configured Action store does not support reliable outbox delivery."); return 1;
            }
            $worker = new OutboxWorker($store, $this->container->make(AuditLoggerInterface::class),
                $this->container->make(DomainEventBus::class), $this->container);
            $result = $worker->work($limit, $seconds);
            $this->line(json_encode(["schema" => "fnlla.outbox.work.v1", ...$result], JSON_THROW_ON_ERROR));
            return $result["failed"] > 0 ? 1 : 0;
        } catch (Throwable) {
            $this->error("Outbox delivery unavailable. Verify configuration, schema migration and lease ownership.");
            return 1;
        }
    }
}
