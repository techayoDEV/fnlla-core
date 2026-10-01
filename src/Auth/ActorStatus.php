<?php

declare(strict_types=1);

namespace Fnlla\Php\Auth;

/** Shared status policy for session, authorization and deferred work identities. */
final class ActorStatus
{
    public static function active(?array $actor): bool
    {
        return $actor !== null
            && (!array_key_exists('active', $actor) || in_array($actor['active'], [true, 1, '1'], true))
            && (!array_key_exists('revoked_at', $actor) || in_array($actor['revoked_at'], [null, ''], true));
    }
}
