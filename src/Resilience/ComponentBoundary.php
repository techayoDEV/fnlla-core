<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

/** Fallback output is application-owned and must escape any untrusted content. */
final class ComponentBoundary
{
    public function __construct(private DependencyHealth $health, private ResilienceEvents $events) {}

    public function render(string $dependency, callable $render, callable $fallback): mixed
    {
        $level = ob_get_level();
        ob_start();
        try {
            $result = $render();
            $output = (string) ob_get_clean();
            return $result ?? $output;
        } catch (DependencyUnavailable $exception) {
            while (ob_get_level() > $level) { ob_end_clean(); }
            $this->health->failed($dependency, 'optional');
            $this->events->emit('fallback_used', ['dependency' => $dependency, 'fallback' => 'component']);
            return $fallback();
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
    }
}
