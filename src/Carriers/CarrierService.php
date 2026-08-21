<?php
declare(strict_types=1);

namespace Routlaw\Carriers;

use Routlaw\Security\AuditLog;

/**
 * T4 Carrier signup + lifecycle (build-plan §4 Phase 2 T4, FR-004/FR-005/BR-001).
 *
 * Carrier self-registers: email-verify -> active (§0b #7, FRD §4.3).
 * Lifecycle states per FRD §13.1: new -> needs_documents | under_review -> active
 *   -> inactive -> archived; rejected -> archived.
 *
 * Tenant-scoped from day one (FR-042, BR-020): every query carries company_id.
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class CarrierService
{
    private \mysqli $db;

    /**
     * Permitted state transitions (FR-005 §13.1).
     * from_status => [allowed to_statuses].
     */
    private const ALLOWED_TRANSITIONS = [
        'new'           => ['needs_documents', 'under_review'],
        'needs_documents' => ['under_review', 'new'],
        'under_review'  => ['active', 'rejected', 'new'],
        'active'        => ['inactive', 'archived'],
        'inactive'      => ['active', 'archived'],
        'rejected'      => ['archived'],
        'archived'      => [],
    ];

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Carrier self-registration (FR-004/BR-001).
     * Creates a carrier row in 'new' state with server-side validation and
     * duplicate detection within the tenant (SEC-010/FR-042).
     *
     * @param int    $companyId Tenant scope (FR-042).
     * @param string $legalName Required (FR-004).
     * @param string $dba       Doing-business-as (optional).
     * @param string $dotNumber DOT identifier (optional, but unique per tenant).
     * @param string $mcNumber  MC identifier (optional, unique per tenant).
     * @param string $ein       EIN (optional).
     * @return int Carrier ID.
     * @throws \RuntimeException on duplicate within tenant (FR-004 duplicate detection).
     */
    public function signup(
        int $companyId,
        string $legalName,
        string $dba,
        string $dotNumber,
        string $mcNumber,
        string $ein
    ): int {
        // FR-004: server-side validation — legal_name required.
        if ($legalName === '') {
            throw new \RuntimeException('legal_name is required (FR-004).');
        }

        // FR-004: duplicate detection within tenant. DOT and MC must be unique per tenant.
        if ($dotNumber !== '' && $this->existsByField($companyId, 'dot_number', $dotNumber)) {
            throw new \RuntimeException('Duplicate DOT number within tenant (FR-004).');
        }
        if ($mcNumber !== '' && $this->existsByField($companyId, 'mc_number', $mcNumber)) {
            throw new \RuntimeException('Duplicate MC number within tenant (FR-004).');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO carriers (company_id, legal_name, doing_business_as, dot_number, mc_number, ein, status, source_type) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare carrier signup: ' . $this->db->error);
        }
        $status = 'new';
        $sourceType = 'carrier_signup';
        $stmt->bind_param(
            'isssssss',
            $companyId,
            $legalName,
            $dba,
            $dotNumber,
            $mcNumber,
            $ein,
            $status,
            $sourceType
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to create carrier: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();

        // FR-029/FR-005: audit the signup and initial state.
        $audit = new AuditLog($this->db);
        $audit->recordSystem('carrier.signup', $companyId, 'system', 'create', [
            'carrier_id' => $id,
            'legal_name' => $legalName,
            'status'     => $status,
        ], 'carrier', (string) $id);

        return $id;
    }

    /**
     * Transition a carrier to a new lifecycle state (FR-005).
     * Only authorized roles may transition states (FR-005: transitions logged).
     *
     * @param int    $carrierId  Carrier to transition.
     * @param string $newStatus  Target state.
     * @param int    $actorRoleId Role ID of the acting user (permission checked upstream).
     * @param int    $companyId  Tenant scope — defense-in-depth (FR-042); scope all queries by company_id.
     * @return bool True if transition succeeded.
     * @throws \RuntimeException if the carrier is not found in the tenant.
     */
    public function transitionState(int $carrierId, string $newStatus, int $actorRoleId, int $companyId): bool
    {
        $currentStatus = $this->getStatus($carrierId, $companyId);
        if ($currentStatus === null) {
            throw new \RuntimeException('Carrier not found.');
        }

        if (!isset(self::ALLOWED_TRANSITIONS[$currentStatus])) {
            return false;
        }

        // FR-005: enforce permitted state transitions.
        if (!in_array($newStatus, self::ALLOWED_TRANSITIONS[$currentStatus], true)) {
            return false;
        }

        $stmt = $this->db->prepare('UPDATE carriers SET status = ? WHERE id = ? AND company_id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare carrier status update: ' . $this->db->error);
        }
        $stmt->bind_param('sii', $newStatus, $carrierId, $companyId);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return false;
        }

        // FR-005: log the transition to carrier_status_history.
        $this->logTransition($carrierId, $currentStatus, $newStatus, $actorRoleId);

        return true;
    }

    /**
     * List carriers scoped to a tenant (SEC-010/FR-042).
     *
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, legal_name, doing_business_as, dot_number, mc_number, status, created_at '
            . 'FROM carriers WHERE company_id = ? AND deleted_at IS NULL ORDER BY id'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare list query: ' . $this->db->error);
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

    /**
     * Get the current status of a carrier (tenant-scoped read).
     *
     * @return string|null
     */
    private function getStatus(int $carrierId, int $companyId): ?string
    {
        $stmt = $this->db->prepare('SELECT status FROM carriers WHERE id = ? AND company_id = ? AND deleted_at IS NULL');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare status query: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $carrierId, $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : false;
        $stmt->close();
        return ($row === null || $row === false) ? null : (string) ($row['status'] ?? '');
    }

    /**
     * Soft-delete a carrier (regulated entity — never hard-delete; BR-020 audit trail).
     * Tenant-scoped: only affects a carrier owned by $companyId.
     *
     * @param int    $carrierId  Carrier to delete.
     * @param int    $companyId  Tenant scope (FR-042) — defense-in-depth.
     * @param int    $actorId    Acting user id (audit).
     * @param string $reason     Reason for deletion (compliance record).
     * @return bool True if a row was soft-deleted.
     */
    public function softDelete(int $carrierId, int $companyId, int $actorId, string $reason): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE carriers SET deleted_at = NOW(), deleted_by = ?, delete_reason = ? '
            . 'WHERE id = ? AND company_id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare carrier soft-delete: ' . $this->db->error);
        }
        $stmt->bind_param('isii', $actorId, $reason, $carrierId, $companyId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok && $affected > 0) {
            $audit = new AuditLog($this->db);
            $audit->recordSystem('carrier.delete', $companyId, 'user', 'soft_delete', [
                'carrier_id' => $carrierId,
                'reason'     => $reason,
                'actor_id'   => $actorId,
            ], 'carrier', (string) $carrierId);
            return true;
        }
        return false;
    }

    /**
     * Check if a carrier exists with a given non-null field value within a tenant.
     */
    private function existsByField(int $companyId, string $field, string $value): bool
    {
        // $field is a class-constant whitelist (hardcoded), never user input.
        $allowed = ['dot_number', 'mc_number', 'ein'];
        if (!in_array($field, $allowed, true)) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM carriers WHERE company_id = ? AND {$field} = ? LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare duplicate check: ' . $this->db->error);
        }
        $stmt->bind_param('is', $companyId, $value);
        $stmt->execute();
        $result = $stmt->get_result();
        $col = $result !== false ? $result->fetch_column() : false;
        $exists = ($col !== false && $col !== null);
        $stmt->close();
        return $exists;
    }

    /**
     * Log a state transition to carrier_status_history (FR-005).
     */
    private function logTransition(int $carrierId, string $from, string $to, int $actorRoleId): void
    {
        $res = $this->db->query(
            'SELECT company_id FROM carriers WHERE id = ' . (int) $carrierId
        );
        $row = is_bool($res) ? false : $res->fetch_column();
        $companyId = $row !== null && $row !== false ? (int) $row : 0;

        $stmt = $this->db->prepare(
            'INSERT INTO carrier_status_history (company_id, carrier_id, from_status, to_status, actor_id, actor_type) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return; // Best-effort logging; the UPDATE already succeeded.
        }
        $actorType = 'user';
        $stmt->bind_param('iissis', $companyId, $carrierId, $from, $to, $actorRoleId, $actorType);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get the last insert ID as an integer (phpstan L8 friendly).
     */
    private function lastInsertId(): int
    {
        $id = $this->db->insert_id;
        return $id > 0 ? (int) $id : 0;
    }
}
