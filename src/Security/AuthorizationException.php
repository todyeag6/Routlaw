<?php
declare(strict_types=1);

namespace Routlaw\Security;

use RuntimeException;

/**
 * Thrown when an authorization check fails (FR-003, SEC-010, FR-021 cannot-self-send).
 *
 * The message is safe to expose to the caller (it is a controlled policy statement,
 * not a stack trace or secret); the detail field carries the machine code.
 */
final class AuthorizationException extends RuntimeException
{
    public function __construct(string $detail, string $message = '')
    {
        parent::__construct($message !== '' ? $message : ('denied: ' . $detail));
    }
}
