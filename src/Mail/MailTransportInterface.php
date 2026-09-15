<?php

declare(strict_types=1);

namespace Fnlla\Php\Mail;

interface MailTransportInterface
{
    /** Receives the validated fnlla.mail.message.v1 envelope; throws on failure. */
    public function send(array $message): void;
}
