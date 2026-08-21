<?php
declare(strict_types=1);

namespace Routlaw\Security;

/**
 * Cloudflare Turnstile verification for public forms (bot defense).
 *
 * Preferred over reCAPTCHA when the site is behind Cloudflare. Site key is
 * safe in HTML; the secret key stays server-side (RL_TURNSTILE_SECRET_KEY).
 *
 * If both keys are empty (dev / not yet configured), verify() returns true
 * so local form flows are not blocked — production MUST set the keys.
 *
 * Reference: https://developers.cloudflare.com/turnstile/
 */
final class Turnstile
{
    private string $siteKey;
    private string $secretKey;
    private string $verifyUrl;

    public function __construct()
    {
        $this->siteKey   = (string) ($_ENV['RL_TURNSTILE_SITE_KEY'] ?? '');
        $this->secretKey = (string) ($_ENV['RL_TURNSTILE_SECRET_KEY'] ?? '');
        $this->verifyUrl = (string) ($_ENV['RL_TURNSTILE_VERIFY_URL'] ?? 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    }

    /**
     * Whether the widget is configured (keys present).
     */
    public function isEnabled(): bool
    {
        return $this->siteKey !== '' && $this->secretKey !== '';
    }

    /**
     * Verify a Turnstile token submitted by a public form.
     *
     * @param string $token  The `cf-turnstile-response` value from the form.
     * @param string $ip     Optional client IP (forwarded by Cloudflare).
     * @return bool True if valid (or if Turnstile is not configured => dev bypass).
     */
    public function verify(string $token, string $ip = ''): bool
    {
        if (!$this->isEnabled()) {
            // Not configured => do not block dev flows. Production sets the keys.
            return true;
        }
        if ($token === '') {
            return false;
        }

        $postData = http_build_query([
            'secret'   => $this->secretKey,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $postData,
                'timeout' => 10,
            ],
        ]);
        $result = @file_get_contents($this->verifyUrl, false, $ctx);
        if ($result === false) {
            return false;
        }
        $json = json_decode($result, true);
        return isset($json['success']) && $json['success'] === true;
    }

    /**
     * Site key for embedding the widget in HTML forms.
     */
    public function siteKey(): string
    {
        return $this->siteKey;
    }
}
