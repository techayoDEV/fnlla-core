<?php

declare(strict_types=1);

namespace Fnlla\Php\Database;

use RuntimeException;

/** The database commit succeeded; a deferred external callback failed afterward. */
final class PostCommitCallbackException extends RuntimeException
{
    /** @param array<int, \Throwable> $failures Callback positions are one-based. */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, private array $failures = [])
    {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<int, \Throwable> */
    public function failures(): array { return $this->failures; }
}
