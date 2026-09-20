<?php

declare(strict_types=1);

namespace Fnlla\Php\Database;

use RuntimeException;

/** The database commit succeeded; a deferred external callback failed afterward. */
final class PostCommitCallbackException extends RuntimeException
{
}
