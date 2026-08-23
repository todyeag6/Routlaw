<?php
declare(strict_types=1);

namespace Routlaw\Agent\Memory;

/**
 * MemoryEntry — T18 tenant/role-scoped memory value object (FR-034/035/036/037).
 *
 * Provenance is retained (agent_run_id, tool_call_id, source) so every memory can be
 * traced. min_role is the LEAST-privileged role that may read it (lower id = higher
 * privilege; super_admin=1 .. reviewer=5). Entries are never silently overwritten: a
 * conflicting write links contradiction_of and flips the prior to 'contradicted'.
 */
final class MemoryEntry
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $minRole,
        public readonly string $content,
        /** @var array<string,mixed>|null */
        public readonly ?array $provenance,
        public readonly ?int $agentRunId,
        public readonly string $status,
        public readonly ?string $expiresAt,
        public readonly ?string $revalidatedAt,
        public readonly ?int $id = null,
        public readonly ?int $contradictionOf = null,
    ) {
    }
}
