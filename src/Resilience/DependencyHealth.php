<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

/** Observed request-local status, not a parallel capability registry. */
final class DependencyHealth
{
    private array $failed = [];
    private array $bootFailures = [];

    public function __construct(private ResilienceEvents $events, private array $policies = []) {}

    public function observeWith(ResilienceEvents $events): void { $this->events = $events; }

    public function failed(string $dependency, ?string $policy = null): void
    {
        $before = $this->status();
        $policy ??= $this->policies[$dependency] ?? 'critical';
        if (!in_array($policy, ['critical', 'degradable', 'optional'], true)) {
            throw new \InvalidArgumentException('Invalid dependency policy.');
        }
        if (isset($this->failed[$dependency])) { return; }
        $this->failed[$dependency] = $policy;
        $this->events->emit('dependency_failed', ['dependency' => $dependency]);
        if ($dependency === 'database') { $this->events->emit('database_unavailable'); }
        if ($before !== $this->status()) { $this->events->emit('health_changed', ['status' => $this->status()]); }
    }

    public function status(): string
    {
        return in_array('critical', $this->failed, true) ? 'unavailable' : ($this->failed === [] ? 'healthy' : 'degraded');
    }

    public function bootFailed(string $dependency): void
    {
        $this->failed($dependency, 'optional');
        $this->bootFailures = $this->failed;
    }

    public function reset(): void { $this->failed = $this->bootFailures; }
}
