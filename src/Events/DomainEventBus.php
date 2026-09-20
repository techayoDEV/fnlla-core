<?php

declare(strict_types=1);

namespace Fnlla\Php\Events;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Queue\QueueManager;
use InvalidArgumentException;
use RuntimeException;

final class DomainEventBus
{
    /** @var array<string, array<string, array{version:int,mode:string,listener:callable|array|string}>> */
    private array $listeners = [];

    public function __construct(
        private Container $container,
        private Dispatcher $dispatcher,
        private QueueManager $queue
    ) {
    }

    public function listen(string $event, int $payloadVersion, string $listenerId, callable|array $listener): void
    {
        $this->register($event, $payloadVersion, $listenerId, "sync", $listener);
    }

    /** @param class-string $jobClass */
    public function queue(string $event, int $payloadVersion, string $listenerId, string $jobClass): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,254}$/D', $jobClass) !== 1) {
            throw new InvalidArgumentException("Invalid queued domain-event listener class.");
        }
        $this->register($event, $payloadVersion, $listenerId, "queued", $jobClass);
    }

    public function publish(DomainEvent $event): array
    {
        $results = [];
        foreach ($this->listeners[$event->name] ?? [] as $listenerId => $definition) {
            if ($definition["version"] !== $event->payloadVersion) {
                continue;
            }
            if ($definition["mode"] === "queued") {
                $this->queue->push((string) $definition["listener"], ["domain_event" => $event->toArray()], [
                    "correlation_id" => $event->context->correlationId,
                    "tenant_id" => $event->context->tenantId,
                    "actor_id" => $event->context->actorId,
                    "idempotency_key" => $event->id . ":" . $listenerId,
                ]);
                $results[$listenerId] = "queued";
                continue;
            }
            $results[$listenerId] = $this->container->call($definition["listener"], [
                "event" => $event,
                "domainEvent" => $event,
            ]);
        }
        $results["legacy_dispatcher"] = $this->dispatcher->dispatch($event, ["domainEvent" => $event]);
        return $results;
    }

    /** @return list<array{event:string,payload_version:int,listener_id:string,mode:string,target:string}> */
    public function inspect(): array
    {
        $rows = [];
        foreach ($this->listeners as $event => $listeners) {
            foreach ($listeners as $id => $definition) {
                $target = is_string($definition["listener"])
                    ? $definition["listener"]
                    : (is_array($definition["listener"])
                        ? (is_string($definition["listener"][0] ?? null) ? $definition["listener"][0] : get_debug_type($definition["listener"][0] ?? null)) . "@" . (string) ($definition["listener"][1] ?? "")
                        : "callable");
                $rows[] = [
                    "event" => $event,
                    "payload_version" => $definition["version"],
                    "listener_id" => $id,
                    "mode" => $definition["mode"],
                    "target" => $target,
                ];
            }
        }
        usort($rows, static fn (array $left, array $right): int => [$left["event"], $left["listener_id"]] <=> [$right["event"], $right["listener_id"]]);
        return $rows;
    }

    private function register(string $event, int $version, string $id, string $mode, callable|array|string $listener): void
    {
        foreach (["event" => $event, "listener" => $id] as $label => $value) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $value) !== 1) {
                throw new InvalidArgumentException("Invalid domain {$label} identifier.");
            }
        }
        if ($version < 1) {
            throw new InvalidArgumentException("Domain listener payload version must be positive.");
        }
        if (isset($this->listeners[$event][$id])) {
            throw new RuntimeException("Domain listener is already registered: {$event}/{$id}.");
        }
        $this->listeners[$event][$id] = [
            "version" => $version,
            "mode" => $mode,
            "listener" => $listener,
        ];
    }
}
