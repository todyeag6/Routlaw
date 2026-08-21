<?php
declare(strict_types=1);

namespace Routlaw\Leads;

use Routlaw\Security\AuditLog;

/**
 * LeadService: T7 typed lead capture + conservative dedup (build-plan §4 Phase 3 T7,
 * FR-008/FR-009/BR-003).
 *
 * FR-008: create typed leads (carrier/broker/shipper/general/document); each carries
 *   tenant, source, status, timestamps, and a raw submission reference.
 * FR-009: flag potential duplicates via normalized identifiers + conservative similarity;
 *   never auto-merge (reviewable only).
 *
 * Tenant-scoped from day one (FR-042, BR-020): every query carries company_id.
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class LeadService
{
    private \mysqli $db;

    /** Permitted lead types (FR-008). */
    private const LEAD_TYPES = ['carrier', 'broker', 'shipper', 'general', 'document'];

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Normalize a contact into stable comparison keys (FR-009 dedup input).
     * Email -> lowercase trimmed; phone -> digits only; name -> lowercase, collapsed ws.
     *
     * @param array<string, mixed> $contact
     * @return array{email:?string, phone:?string, name:?string}
     */
    public function normalizeContact(array $contact): array
    {
        $emailRaw = $contact['email'] ?? null;
        $email = is_string($emailRaw) ? strtolower(trim($emailRaw)) : '';
        $phoneRaw = $contact['phone'] ?? null;
        $phone = is_string($phoneRaw) ? (string) preg_replace('/\D/', '', $phoneRaw) : '';
        $nameRaw = $contact['name'] ?? null;
        $name = is_string($nameRaw) ? strtolower((string) preg_replace('/\s+/', ' ', trim($nameRaw))) : '';

        return [
            'email' => $email === '' ? null : $email,
            'phone' => $phone === '' ? null : $phone,
            'name' => $name === '' ? null : $name,
        ];
    }

    /**
     * Create a typed lead (FR-008).
     *
     * @param int                $companyId    Tenant scope (FR-042).
     * @param string             $leadType     One of LEAD_TYPES.
     * @param array<string,mixed> $contact     ['email'=>, 'phone'=>, 'name'=>].
     * @param int                $actorId      Acting user id (audit).
     * @param int|null           $rawSourceId  Link to immutable source_record (FR-014).
     * @return int Lead ID.
     * @throws \RuntimeException on invalid lead type.
     */
    public function createLead(
        int $companyId,
        string $leadType,
        array $contact,
        int $actorId,
        ?int $rawSourceId = null
    ): int {
        if (!in_array($leadType, self::LEAD_TYPES, true)) {
            throw new \RuntimeException('Invalid lead_type (FR-008): ' . $leadType);
        }

        $norm = $this->normalizeContact($contact);

        // FR-008 acceptance: raw submission reference linking to immutable source.
        $srcId = $rawSourceId ?? null;

        $stmt = $this->db->prepare(
            'INSERT INTO leads '
            . '(company_id, lead_type, source, status, normalized_email, normalized_phone, normalized_name, raw_source_id, dup_status, created_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare lead insert: ' . $this->db->error);
        }
        $source = $srcId !== null ? 'web_form' : 'manual';
        $status = 'new';
        $dupStatus = 'none';
        $email = $norm['email'] ?? '';
        $phone = $norm['phone'] ?? '';
        $name = $norm['name'] ?? '';
        $srcVar = $srcId ?? null;
        $stmt->bind_param(
            'issssssisi',
            $companyId,
            $leadType,
            $source,
            $status,
            $email,
            $phone,
            $name,
            $srcVar,
            $dupStatus,
            $actorId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to create lead: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();

        $audit = new AuditLog($this->db);
        $audit->recordSystem('lead.create', $companyId, 'user', 'create', [
            'lead_id' => $id,
            'lead_type' => $leadType,
            'raw_source_id' => $srcId,
        ], 'lead', (string) $id);

        return $id;
    }

    /**
     * Conservative duplicate flagging (FR-009). Marks the NEW lead as 'possible_duplicate'
     * when it shares >= DUP_MATCH_MIN normalized identifier with an existing, non-deleted,
     * same-company lead that is not itself a confirmed duplicate. NEVER auto-merges.
     *
     * @param array<string,mixed> $contact
     * @return int|null The earlier lead id it was flagged against (for review queue), or null.
     */
    public function flagDuplicate(int $companyId, int $newLeadId, array $contact): ?int
    {
        $norm = $this->normalizeContact($contact);
        if ($norm['email'] === null && $norm['phone'] === null && $norm['name'] === null) {
            return null; // No comparable identifiers => cannot conservatively flag.
        }

        // Build an OR-clause over present normalized identifiers (parameterized).
        $clauses = [];
        $params = [];
        $types = '';
        if ($norm['email'] !== null) { $clauses[] = 'normalized_email = ?'; $params[] = $norm['email']; $types .= 's'; }
        if ($norm['phone'] !== null) { $clauses[] = 'normalized_phone = ?'; $params[] = $norm['phone']; $types .= 's'; }
        if ($norm['name'] !== null)  { $clauses[] = 'normalized_name = ?';  $params[] = $norm['name'];  $types .= 's'; }

        // Only match rows that actually hold the compared value (not just NULL placeholders),
        // are in the same tenant, not deleted, not already a confirmed duplicate, and not self.
        $sql = 'SELECT id FROM leads '
            . 'WHERE company_id = ? AND deleted_at IS NULL '
            . 'AND dup_status <> ? AND id <> ? AND ('
            . implode(' OR ', $clauses)
            . ') LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare dup check: ' . $this->db->error);
        }
        $existingTypes = 'isi' . $types;
        $dupVal = 'confirmed_duplicate';
        $args = array_merge([$companyId, $dupVal, $newLeadId], $params);
        $stmt->bind_param($existingTypes, ...$args);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row === null || $row === false) {
            return null;
        }
        $existingId = (int) $row['id'];

        // Mark the NEW lead as possible_duplicate (reviewable). Conservative; no merge.
        $upd = $this->db->prepare(
            'UPDATE leads SET dup_status = ?, dup_of_lead_id = ? WHERE id = ? AND company_id = ? AND deleted_at IS NULL'
        );
        if ($upd === false) {
            throw new \RuntimeException('Failed to prepare dup update: ' . $this->db->error);
        }
        $dupStatusVal = 'possible_duplicate';
        $upd->bind_param('siii', $dupStatusVal, $existingId, $newLeadId, $companyId);
        $upd->execute();
        $upd->close();

        return $existingId;
    }

    /**
     * List leads scoped to a tenant (SEC-010/FR-042).
     *
     * @return list<array<string,mixed>>
     */
    public function listForCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, company_id, lead_type, source, status, normalized_email, normalized_phone, '
            . 'normalized_name, raw_source_id, dup_status, dup_of_lead_id, created_at '
            . 'FROM leads WHERE company_id = ? AND deleted_at IS NULL ORDER BY id'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare lead list: ' . $this->db->error);
        }
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    private function lastInsertId(): int
    {
        $id = $this->db->insert_id;
        return $id > 0 ? (int) $id : 0;
    }
}
