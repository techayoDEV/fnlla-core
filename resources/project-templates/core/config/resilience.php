<?php

declare(strict_types=1);

return [
    // Changing response semantics is opt-in for existing applications.
    'enabled' => (bool) env('RESILIENCE_ENABLED', false),
    'dependencies' => ['database' => 'critical', 'cache' => 'degradable', 'queue' => 'critical'],
    // Trusted class names => 'optional'; optional providers boot last, in isolation.
    'providers' => [],
    'page_cache' => [
        'enabled' => false,
        'paths' => [], // Exact, stateless, explicitly public HTML only.
        'namespace' => 'v1', // Change when public variants/configuration change.
        'fresh_ttl' => 300,
        'last_known_good_ttl' => 86400,
        'max_body_bytes' => 1048576,
    ],
    'cache' => ['primary' => 'file', 'fallback' => 'file'], // Disposable data ONLY.
    'circuit' => ['failure_threshold' => 5, 'cooldown_seconds' => 30, 'probe_lease_seconds' => 30],
    'retry' => ['max_attempts' => 1, 'delay_ms' => 50, 'budget_ms' => 500],
    'health' => [
        'enabled' => false,
        'snapshot_ttl' => 10,
        // Only explicitly required services influence readiness; declare application needs.
        'dependencies' => [],
    ],
];
