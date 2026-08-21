<?php
declare(strict_types=1);

namespace Routlaw\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Outbound email via Apple iCloud SMTP (PHPMailer, STARTTLS on 587).
 *
 * Config comes from RL_SMTP_* env (set in config/secrets.local.php or hPanel).
 * Empty credentials => send() throws "email not configured" instead of silently
 * sending nowhere — safe for dev and explicit for prod.
 *
 * Cloudflare sits in front of the domain; Apple SMTP is the transport.
 */
final class Mailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $from;
    private string $fromName;

    public function __construct()
    {
        $this->host     = (string) ($_ENV['RL_SMTP_HOST'] ?? 'smtp.mail.me.com');
        $this->port     = (int) ($_ENV['RL_SMTP_PORT'] ?? 587);
        $this->username = (string) ($_ENV['RL_SMTP_USERNAME'] ?? '');
        $this->password = (string) ($_ENV['RL_SMTP_PASSWORD'] ?? '');
        $this->from     = (string) ($_ENV['RL_SMTP_FROM'] ?? '');
        $this->fromName = (string) ($_ENV['RL_SMTP_FROM_NAME'] ?? 'ROUTLAW');
    }

    /**
     * Send a plaintext email via iCloud SMTP.
     *
     * @param string $toRecipient  Recipient address.
     * @param string $subject      Subject line.
     * @param string $body         Plaintext body.
     * @param string $toName       Optional recipient name.
     * @return bool True on accept by the relay.
     * @throws \RuntimeException if SMTP is not configured or the send fails.
     */
    public function send(string $toRecipient, string $subject, string $body, string $toName = ''): bool
    {
        if ($this->username === '' || $this->password === '' || $this->from === '') {
            throw new \RuntimeException('Email not configured: set RL_SMTP_USERNAME/PASSWORD/FROM (iCloud app-specific password).');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->port;
            $mail->CharSet    = 'utf-8';

            $mail->setFrom($this->from, $this->fromName);
            $mail->addAddress($toRecipient, $toName);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->isHTML(false);

            return $mail->send();
        } catch (PHPMailerException $e) {
            throw new \RuntimeException('Email send failed: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
