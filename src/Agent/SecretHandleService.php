<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * SecretHandleService — T17 secrets isolation (HAR-06, LLM07, SEC-008/009).
 *
 * Tools that need a credential receive an OPAQUE handle (a random `sec_*` token) instead
 * of the raw secret. The real secret lives only in the server-side store (resolved from
 * config/secrets.local.php at runtime) and is resolvable ONLY through this service.
 *
 * A handle is:
 *   - unguessable: 256-bit random token, not derived from the secret;
 *   - non-reversible: it carries no encoding of the secret (random, one-time mapping).
 *
 * The model can never see or reconstruct a secret; a handle it fabricates is rejected.
 * The handle->secret map is ephemeral (per service instance / per run) and is never
 * written to logs, audit, or model context.
 */
final class SecretHandleService
{
    /** @var array<string,string> handle => secret */
    private array $handles = [];

    /**
     * @param array<string,string> $secrets name => secret (resolved server-side only).
     */
    public function __construct(private array $secrets = [])
    {
    }

    /**
     * Issue an opaque handle for a named secret. Returns the handle (never the secret).
     * @throws \InvalidArgumentException if the named secret does not exist.
     */
    public function issueHandle(string $name): string
    {
        if (!array_key_exists($name, $this->secrets)) {
            throw new \InvalidArgumentException("Unknown secret name: {$name}");
        }
        $handle = 'sec_' . bin2hex(random_bytes(32));
        $this->handles[$handle] = $this->secrets[$name];
        return $handle;
    }

    /**
     * Resolve an opaque handle to its real secret (server-side only).
     * @throws \OutOfBoundsException if the handle was not issued by this service.
     */
    public function resolve(string $handle): string
    {
        if (!array_key_exists($handle, $this->handles)) {
            throw new \OutOfBoundsException('Handle not recognized (possible model fabrication).');
        }
        return $this->handles[$handle];
    }

    /**
     * Whether a value is one of our opaque handles (so callers can recognize handles
     * vs raw secrets in tool input without exposing the secret).
     */
    public function isHandle(string $value): bool
    {
        return str_starts_with($value, 'sec_') && array_key_exists($value, $this->handles);
    }
}
