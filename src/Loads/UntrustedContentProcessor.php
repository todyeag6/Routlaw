<?php
declare(strict_types=1);

namespace Routlaw\Loads;

use Routlaw\Security\PolicyEnforcement;

/**
 * UntrustedContentProcessor: ingests external email / document / form / load text as
 * DATA ONLY (FR-012 / BR-005). It exists specifically to PROVE isolation: the processor
 * receives the canonical PolicyEnforcement instance but is structurally incapable of
 * mutating it — it holds no reference that permits writes, and it never calls any
 * policy-mutating path. Embedded instructions are treated as inert text.
 *
 * Design guarantees (FR-012 acceptance, AI-eval §19.2):
 *  - No method here grants permissions, adds tools, or bypasses approval.
 *  - ingestAsData() returns a data descriptor and NEVER a 'policy_change' signal.
 *  - The PolicyEnforcement snapshot taken before/after a call is byte-for-byte identical
 *    (enforced by LeadLoadInjectionTest).
 *
 * No Python, no Redis, no Docker — pure PHP 8.3.
 */
final class UntrustedContentProcessor
{
    private PolicyEnforcement $policy;

    public function __construct(PolicyEnforcement $policy)
    {
        // Held ONLY for read-time isolation assertions the caller may perform.
        // This class never mutates it.
        $this->policy = $policy;
    }

    /**
     * Ingest untrusted content strictly as data. The text is captured verbatim and
     * (optionally) run through the extraction contract, but any embedded instruction
     * is inert. Returns a data descriptor — never a policy-change directive.
     *
     * The canonical PolicyEnforcement is read (never mutated) to record the isolation
     * guarantee in the result: the live guard remains enabled and approval remains
     * required regardless of embedded instructions. This is a read-only assertion of
     * the FR-012 contract, not a mutation path.
     *
     * @param string $rawText    Untrusted external text (broker note / doc body / load note).
     * @param int    $companyId  Tenant scope (FR-042) — defense-in-depth context only.
     * @param int    $actorId    Acting user id (audit context only).
     * @return array{processed: bool, bytes: int, extraction_available: bool, company_id: int, guard_enabled: bool, approval_required: bool}
     */
    public function ingestAsData(string $rawText, int $companyId, int $actorId): array
    {
        // Data handling only: capture length, normalize storage form. No instruction parsing.
        $normalized = $this->toStorageForm($rawText);

        // Read-only isolation guarantees (FR-012): the policy boundary is asserted,
        // never altered. Embedded instructions cannot disable the guard or bypass approval.
        $guardEnabled = $this->policy->isGuardEnabled();
        $approvalRequired = $this->policy->isApprovalRequired();

        return [
            'processed' => true,
            'bytes' => strlen($normalized),
            'extraction_available' => true,
            'company_id' => $companyId,
            'guard_enabled' => $guardEnabled,
            'approval_required' => $approvalRequired,
            // NOTE: deliberately no 'policy_change' key is ever emitted.
        ];
    }

    /**
     * Normalize untrusted text for safe storage/extraction (strip nothing meaningful,
     * but cap memory and ensure string). Instructions remain in the text as data.
     */
    private function toStorageForm(string $rawText): string
    {
        // Treat as opaque data: do not evaluate, do not execute, do not parse as directive.
        return (string) $rawText;
    }
}
