<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Closure;
use Fnlla\Php\Support\RuntimeDoctor;

final class HealthChecks
{
    private Closure $probe;

    public function __construct(private DisposableCache $cache, private DependencyHealth $health,
        private array $settings = [], ?Closure $probe = null)
    {
        foreach ((array) ($settings['dependencies'] ?? []) as $policy) {
            if (!in_array($policy, ['critical', 'degradable', 'optional'], true)) {
                throw new \InvalidArgumentException('Invalid health dependency policy.');
            }
        }
        $this->probe = $probe ?? static fn (): array => (new RuntimeDoctor())->report(0.5);
    }

    public function report(): array
    {
        $ttl = max(1, min(60, (int) ($this->settings['snapshot_ttl'] ?? 10)));
        $key = 'readiness:' . hash('sha256', json_encode($this->settings, JSON_THROW_ON_ERROR));
        $report = $this->cache->remember($key, $ttl, function (): array {
            $required = (array) ($this->settings['dependencies'] ?? []);
            try { $raw = $required === [] ? ['checks' => []] : ($this->probe)(); } catch (\Throwable) { $raw = ['checks' => []]; }
            $state = 'healthy';
            $checks = [];
            foreach ($required as $service => $policy) {
                $found = null;
                foreach (($raw['checks'] ?? []) as $check) { if (($check['service'] ?? null) === $service) { $found = $check['status'] ?? 'error'; } }
                $ready = $found === 'ready';
                $checks[] = ['service' => $service, 'status' => $ready ? 'ready' : 'unavailable'];
                if (!$ready) {
                    if ($policy === 'critical') { $state = 'unavailable'; }
                    elseif ($state === 'healthy') { $state = 'degraded'; }
                }
            }
            return ['status' => $state, 'checks' => $checks];
        });
        if (!is_array($report) || !in_array($report['status'] ?? null, ['healthy', 'degraded', 'unavailable'], true)
            || !is_array($report['checks'] ?? null)) { $report = ['status' => 'unavailable', 'checks' => []]; }
        $report['checks'] = array_values(array_map(static fn (array $check): array => [
            'service' => $check['service'], 'status' => $check['status'],
        ], array_filter($report['checks'], fn (mixed $check): bool => is_array($check)
            && is_string($check['service'] ?? null) && array_key_exists($check['service'], (array) ($this->settings['dependencies'] ?? []))
            && in_array($check['status'] ?? null, ['ready', 'unavailable'], true))));
        if ($this->health->status() === 'unavailable') { $report['status'] = 'unavailable'; }
        elseif ($this->health->status() === 'degraded' && $report['status'] === 'healthy') { $report['status'] = 'degraded'; }
        return $report;
    }
}
