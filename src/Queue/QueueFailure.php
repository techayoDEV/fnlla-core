<?php

declare(strict_types=1);

namespace Fnlla\Php\Queue;

/** Persisted diagnostics contain codes only; the existing job ID links to private reports. */
final class QueueFailure
{
    public static function code(string $reason): string
    {
        return in_array($reason, ['job_failed', 'invalid_payload', 'lease_lost', 'attempts_exhausted', 'legacy_job_failed'], true)
            ? $reason : 'job_failed';
    }
}
