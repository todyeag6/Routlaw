<?php
declare(strict_types=1);

namespace Routlaw\Security;

/**
 * Immutable value object representing an authenticated session's scope.
 *
 * Carries the role/tenant-scoped identity established at login (SEC-002, SEC-010).
 * Passed back from Auth::login() so callers can assert scope without
 * trusting raw session state.
 */
final class UserSession
{
    public function __construct(
        public readonly int $userId,
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $companyLegalName,
        public readonly string $email,
        public readonly string $fullName,
        public readonly int $roleId,
        public readonly string $roleSlug,
    ) {
    }
}
