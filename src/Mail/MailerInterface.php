<?php
declare(strict_types=1);

namespace Routlaw\Mail;

/**
 * T14.4 — Mailer abstraction (DI enables test fakes; OutboundService never couples to SMTP).
 *
 * Real implementation is Routlaw\Mail\Mailer (Apple iCloud SMTP via PHPMailer).
 */
interface MailerInterface
{
    /** @return bool True on accept by the relay. @throws \RuntimeException on config/send failure. */
    public function send(string $to, string $subject, string $body, string $toName = ''): bool;
}
