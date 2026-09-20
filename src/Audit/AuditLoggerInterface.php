<?php

declare(strict_types=1);

namespace Fnlla\Php\Audit;

interface AuditLoggerInterface
{
    public function record(AuditEvent $event): void;
}
