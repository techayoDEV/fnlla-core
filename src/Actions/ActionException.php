<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use RuntimeException;

/** Safe, transport-independent failure. Never include input or provider messages. */
final class ActionException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        $messages = [
            'unavailable' => 'The capability is unavailable.',
            'unauthorized' => 'The capability is unauthorized.',
            'invalid_input' => 'The capability input is invalid.',
            'invalid_output' => 'The capability result violates its contract.',
            'execution_failed' => 'The capability could not be completed.',
            'post_commit_failed' => 'The mutation committed but a post-commit callback failed.',
        ];
        parent::__construct($messages[$reason] ?? throw new \InvalidArgumentException('Unknown action failure code.'));
    }
}
