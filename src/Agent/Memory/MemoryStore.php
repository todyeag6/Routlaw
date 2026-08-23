<?php
declare(strict_types=1);

namespace Routlaw\Agent\Memory;

use Routlaw\Security\AuditLog;

/**
 * MemoryStore — T18 tenant/role-scoped agent memory (FR-034/035/036/037, + leakage gate).
 *
 * Retrieval filters by company_id AND min_role <= caller role in the SQL WHERE clause
 * (LLM02/PRIV-004, LLM08) — BEFORE any semantic/FTS ranking. Cross-tenant leakage is
 * structurally impossible because company_id is part of the key predicate, never a
 * post-filter. Expired entries are excluded on read (LLM04). Contradictions are recorded,
 * never silently overwritten (LLM04).
 *
 * The semantic ranking layer is pluggable: the default implementation scores by simple
 * relevance (no Python/Redis/LLM in the stack). Callers MUST use retrieve()/readForTenant()
 * which always scope by tenant+role — there is no unscoped read path.
 */
final class MemoryStore
{
    public function __construct(
        private \mysqli $db,
        private ?AuditLog $audit = null,
    ) {
    }

    /**
     * Write a memory entry (tenant-scoped by the entry's companyId).
     * @return int new entry id.
     */
    public function write(MemoryEntry $entry, ?int $contradictingId = null): int
    {
        $provJson = $entry->provenance !== null ? json_encode($entry->provenance, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $provJson = $provJson === false ? null : $provJson;
        $contra = $contradictingId ?? $entry->contradictionOf;

        $stmt = $this->db->prepare(
            'INSERT INTO memory_entries '
            . '(company_id, agent_run_id, min_role, content, provenance_json, contradiction_of, status, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare memory insert: ' . $this->db->error);
        }
        $types = 'iiississ';
        /** @var list<mixed> $params */
        $params = [
            $entry->companyId,
            $entry->agentRunId,
            $entry->minRole,
            $entry->content,
            $provJson,
            $contra,
            $entry->status,
            $entry->expiresAt,
        ];
        $this->assertBindArity($stmt, $types, $params);
        $bind = [(string) $types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        $stmt->bind_param(...$bind);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to write memory entry: ' . $this->db->error);
        }
        $id = (int) $this->db->insert_id;
        $stmt->close();

        // If this entry contradicts a prior one, flag the prior as contradicted.
        if ($contra !== null) {
            $this->flagContradicted($contra);
        }
        if ($this->audit !== null) {
            $this->audit->recordSystem('memory.write', $entry->companyId, 'agent', 'write', [
                'entry_id' => $id, 'min_role' => $entry->minRole, 'contradicts' => $contra,
            ], 'memory_entry', (string) $id);
        }
        return $id;
    }

    /**
     * Tenant + role + status + non-expired scoped retrieval (the §19.3 leakage=0 path).
     * Filtering happens in SQL, before ranking. contentMatch is a simple LIKE probe for
     * relevance scoring (pluggable semantic layer can replace it later).
     *
     * @return list<MemoryEntry>
     */
    public function retrieve(string $query, int $companyId, int $callerRole, string $contentMatch = ''): array
    {
        return $this->scopedSelect($companyId, $callerRole, $contentMatch, true);
    }

    /**
     * Read all in-scope entries for a tenant+role (no content filter). Used by tests and
     * by admin review surfaces that are themselves tenant-scoped.
     *
     * @return list<MemoryEntry>
     */
    public function readForTenant(int $companyId, int $callerRole, int $limit = 100): array
    {
        return $this->scopedSelect($companyId, $callerRole, '', false, $limit);
    }

    /**
     * @return list<MemoryEntry>
     */
    private function scopedSelect(int $companyId, int $callerRole, string $contentMatch, bool $useMatch, int $limit = 100): array
    {
        // company_id + min_role <= callerRole + active + not expired: all in WHERE (no
        // unscoped read). This is the structural guarantee behind leakage = 0.
        $sql = 'SELECT id, company_id, agent_run_id, min_role, content, provenance_json, '
            . 'contradiction_of, status, expires_at, revalidated_at FROM memory_entries '
            . 'WHERE company_id = ? AND min_role <= ? AND status = ? '
            . "AND (expires_at IS NULL OR expires_at > NOW())";
        $types = 'iis';
        /** @var list<mixed> $params */
        $params = [$companyId, $callerRole, 'active'];
        if ($useMatch && $contentMatch !== '') {
            $sql .= ' AND content LIKE ?';
            $types .= 's';
            $params[] = '%' . $contentMatch . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare memory select: ' . $this->db->error);
        }
        $this->assertBindArity($stmt, $types, $params);
        $bind = [(string) $types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        $stmt->bind_param(...$bind);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res !== false ? $res->fetch_assoc() : null) {
            $rows[] = $this->hydrate($row);
        }
        $stmt->close();
        return $rows;
    }

    private function flagContradicted(int $priorId): void
    {
        $stmt = $this->db->prepare("UPDATE memory_entries SET status = 'contradicted' WHERE id = ? AND status = 'active'");
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare contradiction flag: ' . $this->db->error);
        }
        $types = 'i';
        $params = [$priorId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to flag contradiction: ' . $this->db->error);
        }
        $stmt->close();
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): MemoryEntry
    {
        $prov = $row['provenance_json'] !== null ? json_decode((string) $row['provenance_json'], true) : null;
        return new MemoryEntry(
            companyId: (int) $row['company_id'],
            minRole: (int) $row['min_role'],
            content: (string) $row['content'],
            provenance: is_array($prov) ? $prov : null,
            agentRunId: $row['agent_run_id'] !== null ? (int) $row['agent_run_id'] : null,
            status: (string) $row['status'],
            expiresAt: $row['expires_at'],
            revalidatedAt: $row['revalidated_at'],
            id: (int) $row['id'],
            contradictionOf: $row['contradiction_of'] !== null ? (int) $row['contradiction_of'] : null,
        );
    }

    /**
     * @param array<int, mixed> $params
     */
    private function assertBindArity(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if (strlen($types) !== count($params)) {
            $stmt->close();
            throw new \LogicException(sprintf('bind_param arity mismatch: %d vs %d', strlen($types), count($params)));
        }
        $expected = ['i' => 'integer', 'd' => 'double', 's' => 'string', 'b' => 'string'];
        foreach ($params as $i => $p) {
            if ($p === null) {
                continue;
            }
            $want = $expected[$types[$i]] ?? null;
            if ($want !== null && gettype($p) !== $want) {
                $stmt->close();
                throw new \LogicException(sprintf('bind_param type mismatch at %d: "%s" expects %s, got %s', $i, $types[$i], $want, gettype($p)));
            }
        }
    }
}
