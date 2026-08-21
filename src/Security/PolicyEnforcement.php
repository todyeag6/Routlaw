<?php
declare(strict_types=1);

namespace Routlaw\Security;

/**
 * PolicyEnforcement: the canonical authorization / tool / approval policy boundary
 * (FR-012 / FR-042 / ASVS L2). This is the state prompt-injected untrusted content
 * must NEVER be able to change.
 *
 * CRITICAL (FR-012 acceptance, AI-eval §19.2): this class exposes ONLY read access to
 * the policy. There is NO method on this class that untrusted content can call to grant
 * permissions, add tools, or bypass approval. Any attempt to mutate policy must go
 * through an explicit, separate, human/role-gated administration path — never through
 * content ingestion. Defense-in-depth: the snapshot() is a deep, comparable copy so a
 * caller can assert byte-for-byte immutability after processing untrusted text.
 *
 * The default policy below reflects the MVP baseline (ASVS Level 2). It is authoritative
 * and must not be derivable or overridable from email/doc/load text.
 */
final class PolicyEnforcement
{
    /** Canonical role -> permission map (subset; mirrors role_permissions seed). */
    private const BASE_PERMISSIONS = [
        'carrier' => ['carriers.view'],
        'dispatcher' => ['carriers.view', 'carriers.edit', 'loads.create', 'approvals.outbound'],
        'reviewer' => ['carriers.view', 'audit.view'],
        'company_admin' => [
            'users.manage', 'company.policy.manage', 'carriers.view', 'carriers.edit',
            'loads.create', 'approvals.outbound', 'documents.share', 'audit.view', 'agent.policy.change',
        ],
        'super_admin' => [
            'users.manage', 'company.policy.manage', 'carriers.view', 'carriers.edit',
            'loads.create', 'approvals.outbound', 'documents.share', 'audit.view', 'agent.policy.change',
        ],
    ];

    /** Tool allowlist for the agent harness (FR-012: injection cannot append to this). */
    private const TOOL_ALLOWLIST = ['load.search', 'carrier.lookup', 'extraction.run', 'draft.message'];

    /** Approval is ALWAYS required for outbound messages (FR-012: cannot be bypassed by content). */
    private const APPROVAL_REQUIRED = true;

    /** Whether the live AI guard is enabled (FR-012: cannot be disabled by content). */
    private const GUARD_ENABLED = true;

    /**
     * Immutable, deep, comparable snapshot of the policy boundary.
     * @return array{permissions: array<string,list<string>>, tool_allowlist: list<string>, approval_required: bool, guard_enabled: bool}
     */
    public function snapshot(): array
    {
        return [
            'permissions' => self::BASE_PERMISSIONS,
            'tool_allowlist' => self::TOOL_ALLOWLIST,
            'approval_required' => self::APPROVAL_REQUIRED,
            'guard_enabled' => self::GUARD_ENABLED,
        ];
    }

    /**
     * Authorization check used by the app (server-side, tenant/role scoped upstream).
     * Untrusted content has no path to influence this.
     */
    public function roleHasPermission(string $roleSlug, string $permission): bool
    {
        $perms = self::BASE_PERMISSIONS[$roleSlug] ?? [];
        return in_array($permission, $perms, true);
    }

    public function toolAllowed(string $toolName): bool
    {
        return in_array($toolName, self::TOOL_ALLOWLIST, true);
    }

    public function isApprovalRequired(): bool
    {
        return self::APPROVAL_REQUIRED;
    }

    public function isGuardEnabled(): bool
    {
        return self::GUARD_ENABLED;
    }
}
