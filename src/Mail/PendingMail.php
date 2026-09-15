<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MAIL SOURCE
File: src\Mail\PendingMail.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained mail delivery helpers for framework flows and notifications.
*/

namespace Fnlla\Php\Mail;

final class PendingMail
{
    public function __construct(
        private Mailer $mailer,
        private array|string $recipients
    ) {
    }

    public function send(string $subject, string $html, string $text = ""): void
    {
        $this->mailer->send($this->recipients, $subject, $html, $text);
    }

    public function sendFormSubmission(string $subject, array $fields): void
    {
        $this->mailer->sendFormSubmission($this->recipients, $subject, $fields);
    }
}
