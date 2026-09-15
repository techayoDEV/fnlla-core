<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MAIL SOURCE
File: src\Mail\Mailer.php
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

use RuntimeException;

final class Mailer
{
    public function to(array|string $recipients): PendingMail
    {
        return new PendingMail($this, $recipients);
    }

    public function send(array|string $recipients, string $subject, string $html, string $text = ""): void
    {
        $driver = (string) config("mail.default", "log");
        $recipients = $this->normaliseRecipients($recipients);
        $subject = $this->normaliseSubject($subject);
        $from = $this->normaliseAddress((string) config("mail.from.address", "no-reply@example.com"));
        $fromName = $this->normaliseHeaderPhrase((string) config("mail.from.name", "FNLLA"));
        $replyTo = trim((string) config("mail.reply_to.address", ""));

        if ($driver === "adapter") {
            app(MailTransportInterface::class)->send([
                "schema" => "fnlla.mail.message.v1", "to" => $recipients,
                "from" => ["address" => $from, "name" => $fromName],
                "reply_to" => $replyTo !== "" ? $this->normaliseAddress($replyTo) : null,
                "subject" => $subject, "html" => $html,
                "text" => $text !== "" ? $text : $this->htmlToText($html),
            ]);
            return;
        }

        if ($driver === "log") {
            $directory = storage_path((string) config("mail.log_path", "mail"));

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $path = $directory . DIRECTORY_SEPARATOR . gmdate("Ymd") . ".log";
            $payload = [
                "sent_at" => gmdate(DATE_ATOM),
                "to" => $recipients,
                "from" => $from,
                "subject" => $subject,
                "html" => $html,
                "text" => $text !== "" ? $text : $this->htmlToText($html),
            ];

            file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

            return;
        }

        if ($driver === "native") {
            if (!(bool) config("mail.native_enabled", false)) {
                throw new RuntimeException("Native mail delivery is disabled. Set MAIL_NATIVE_ENABLED=true only when the server mail transport is configured.");
            }

            $to = implode(", ", $recipients);
            $headers = [
                "MIME-Version: 1.0",
                "Content-type: text/html; charset=UTF-8",
                "From: " . ($fromName !== "" ? $fromName . " <" . $from . ">" : $from),
            ];

            if ($replyTo !== "") {
                $headers[] = "Reply-To: " . $this->normaliseAddress($replyTo);
            }

            if (!mail((string) $to, $subject, $html, implode("\r\n", $headers))) {
                throw new RuntimeException("Native mail delivery failed.");
            }

            return;
        }

        if ($driver === "http") {
            $this->sendViaHttpTransport($recipients, $from, $fromName, $replyTo, $subject, $html, $text);

            return;
        }

        throw new RuntimeException("Unsupported mail driver: " . $driver);
    }

    public function sendFormSubmission(array|string $recipients, string $subject, array $fields): void
    {
        $rows = [];
        $text = [];

        foreach ($fields as $label => $value) {
            $label = $this->normaliseFormLabel((string) $label);
            $value = $this->normaliseFormValue($value);
            $rows[] = "<tr><th align=\"left\" valign=\"top\">" . h($label) . "</th><td>" . nl2br(h($value)) . "</td></tr>";
            $text[] = $label . ": " . $value;
        }

        $html = "<table cellpadding=\"6\" cellspacing=\"0\" border=\"0\">" . implode("", $rows) . "</table>";
        $this->send($recipients, $subject, $html, implode(PHP_EOL, $text));
    }

    private function normaliseRecipients(array|string $recipients): array
    {
        $items = is_array($recipients) ? $recipients : [$recipients];
        $normalised = [];

        foreach ($items as $recipient) {
            if (!is_string($recipient)) {
                continue;
            }

            $normalised[] = $this->normaliseAddress($recipient);
        }

        $normalised = array_values(array_unique($normalised));
        $maxRecipients = max(1, (int) config("mail.max_recipients", 10));

        if ($normalised === []) {
            throw new RuntimeException("Mail requires at least one valid recipient.");
        }

        if (count($normalised) > $maxRecipients) {
            throw new RuntimeException("Mail recipient count exceeds configured limit.");
        }

        return $normalised;
    }

    private function normaliseAddress(string $address): string
    {
        $address = trim($address);

        if ($address === "" || str_contains($address, "\r") || str_contains($address, "\n") || str_contains($address, "\0")) {
            throw new RuntimeException("Mail addresses cannot contain control characters.");
        }

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException("Invalid mail address: " . $address);
        }

        return $address;
    }

    private function normaliseSubject(string $subject): string
    {
        $subject = trim($subject);

        if ($subject === "" || str_contains($subject, "\r") || str_contains($subject, "\n") || str_contains($subject, "\0")) {
            throw new RuntimeException("Mail subject cannot be empty or contain control characters.");
        }

        return substr($subject, 0, 180);
    }

    private function normaliseHeaderPhrase(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new RuntimeException("Mail header phrases cannot contain control characters.");
        }

        return substr($value, 0, 120);
    }

    private function normaliseFormLabel(string $label): string
    {
        $label = trim(preg_replace('/[\r\n\0]+/', " ", $label) ?? "");

        return $label !== "" ? substr($label, 0, 80) : "Field";
    }

    private function normaliseFormValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return substr(trim(strip_tags((string) $value)), 0, 5000);
        }

        return substr(trim(strip_tags(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))), 0, 5000);
    }

    private function htmlToText(string $html): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(["<br>", "<br/>", "<br />"], PHP_EOL, $html)), ENT_QUOTES, "UTF-8"));
    }

    private function sendViaHttpTransport(array $recipients, string $from, string $fromName, string $replyTo, string $subject, string $html, string $text): void
    {
        $endpoint = $this->normaliseHttpEndpoint((string) config("mail.http.endpoint", ""));
        $token = $this->normaliseHeaderPhrase((string) config("mail.http.token", ""));
        $payload = [
            "schema" => "fnlla.mail.message.v1",
            "sent_at" => gmdate(DATE_ATOM),
            "to" => $recipients,
            "from" => [
                "address" => $from,
                "name" => $fromName,
            ],
            "reply_to" => $replyTo !== "" ? $this->normaliseAddress($replyTo) : null,
            "subject" => $subject,
            "html" => $html,
            "text" => $text !== "" ? $text : $this->htmlToText($html),
        ];
        $headers = [
            "Content-Type: application/json",
            "Accept: application/json",
            "User-Agent: FNLLA-Mailer/1",
        ];

        if ($token !== "") {
            $headers[] = "Authorization: Bearer " . $token;
        }

        $context = stream_context_create([
            "http" => [
                "method" => "POST",
                "header" => implode("\r\n", $headers),
                "content" => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                "ignore_errors" => true,
                "timeout" => max(1, (int) config("mail.http.timeout_seconds", 10)),
            ],
        ]);
        $http_response_header = [];
        $response = @file_get_contents($endpoint, false, $context);
        $status = $this->httpStatusCode($http_response_header);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException("HTTP mail transport failed with status " . ($status > 0 ? (string) $status : "unknown") . ".");
        }
    }

    private function normaliseHttpEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if ($endpoint === "" || str_contains($endpoint, "\r") || str_contains($endpoint, "\n") || str_contains($endpoint, "\0")) {
            throw new RuntimeException("MAIL_HTTP_ENDPOINT must be configured for the HTTP mail transport.");
        }

        $parts = parse_url($endpoint);
        $scheme = strtolower((string) ($parts["scheme"] ?? ""));
        $host = strtolower((string) ($parts["host"] ?? ""));

        if (!in_array($scheme, ["http", "https"], true) || $host === "") {
            throw new RuntimeException("MAIL_HTTP_ENDPOINT must be an absolute HTTP(S) URL.");
        }

        $isLocal = in_array($host, ["localhost", "127.0.0.1", "::1"], true);

        if ((bool) config("mail.http.require_https", true) && $scheme !== "https" && !$isLocal) {
            throw new RuntimeException("MAIL_HTTP_ENDPOINT must use HTTPS unless it targets localhost.");
        }

        $allowedHosts = (array) config("mail.http.allowed_hosts", []);

        if ($allowedHosts !== [] && !$this->hostAllowed($host, $allowedHosts)) {
            throw new RuntimeException("MAIL_HTTP_ENDPOINT host is not in MAIL_HTTP_ALLOWED_HOSTS.");
        }

        return $endpoint;
    }

    private function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower(trim((string) $allowedHost));

            if ($allowedHost === $host) {
                return true;
            }

            if (str_starts_with($allowedHost, "*.") && str_ends_with($host, substr($allowedHost, 1))) {
                return true;
            }
        }

        return false;
    }

    private function httpStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
