<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Closure;
use Fnlla\Php\Cache\FileCacheStore;

/** A shared filesystem coordinates workers; no network filesystem is assumed. */
final class CircuitBreaker
{
    private Closure $clock;

    public function __construct(private string $directory, private ResilienceEvents $events,
        private int $threshold = 5, private int $cooldown = 30, private int $probeLease = 30, ?Closure $clock = null)
    {
        if ($threshold < 1 || $threshold > 100 || $cooldown < 1 || $cooldown > 3600 || $probeLease < 1 || $probeLease > 3600) {
            throw new \InvalidArgumentException('Invalid circuit limits.');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function run(string $dependency, callable $operation): mixed
    {
        $token = bin2hex(random_bytes(16));
        $admitted = $this->update($dependency, function (array $state) use ($token): array {
            $now = ($this->clock)();
            if (($state['open_until'] ?? 0) > $now || ($state['probe_until'] ?? 0) > $now) { return [$state, false]; }
            if (($state['open_until'] ?? 0) > 0) {
                $state['probe_until'] = $now + $this->probeLease;
                $state['token'] = $token;
            }
            return [$state, true];
        });
        if (!$admitted) { throw new DependencyUnavailable($dependency); }
        try {
            $result = $operation();
        } catch (DependencyUnavailable $exception) {
            $this->finish($dependency, $token, false);
            throw $exception;
        } catch (\Throwable $exception) {
            // A programming error is not a dependency failure. Release a probe.
            $this->finish($dependency, $token, true);
            throw $exception;
        }
        $this->finish($dependency, $token, true);
        return $result;
    }

    private function finish(string $dependency, string $token, bool $success): void
    {
        $transition = $this->update($dependency, function (array $state) use ($token, $success): array {
            if (isset($state['token']) && $state['token'] !== $token) { return [$state, null]; }
            if ($success) {
                // An older closed request may not close a newly opened circuit.
                if (($state['open_until'] ?? 0) > 0 && !isset($state['token'])) { return [$state, null]; }
                return [[], ($state['open_until'] ?? 0) > 0 ? 'circuit_closed' : null];
            }
            $state['failures'] = ($state['failures'] ?? 0) + 1;
            if (isset($state['token']) || $state['failures'] >= $this->threshold) {
                $state = ['failures' => $state['failures'], 'open_until' => ($this->clock)() + $this->cooldown];
                return [$state, 'circuit_opened'];
            }
            return [$state, null];
        });
        if (is_string($transition)) { $this->events->emit($transition, ['dependency' => $dependency]); }
    }

    private function update(string $dependency, callable $change): mixed
    {
        $lock = null;
        try {
            $store = new FileCacheStore($this->directory);
            $path = $this->directory . '/' . hash('sha256', $dependency) . '.circuit-lock';
            if (is_link($path)) { throw new \RuntimeException('Unsafe circuit lock.'); }
            $lock = @fopen($path, 'c+b');
            if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Circuit store unavailable.'); }
            [$next, $result] = $change((array) $store->get('circuit:' . $dependency, []));
            $store->put('circuit:' . $dependency, $next, max($this->cooldown, $this->probeLease) * 2 + 60);
            return $result;
        } catch (\Throwable $exception) {
            throw new DependencyUnavailable($dependency, $exception);
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}
