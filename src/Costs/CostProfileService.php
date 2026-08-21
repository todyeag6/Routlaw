<?php
declare(strict_types=1);

namespace Routlaw\Costs;

use Routlaw\Security\AuditLog;

/**
 * T6 Versioned carrier cost profiles (build-plan §4 Phase 2 T6, FR-051/BR-001/021).
 *
 * Versioned cost profiles with effective dates, units (per-mile, flat, percentage),
 * and required-input status. Stale (past effective_to) and incomplete profiles
 * are excluded from quantitative recommendations.
 *
 * Tenant-scoped from day one (FR-042): every query carries company_id.
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class CostProfileService
{
    private \mysqli $db;

    /** Allowed unit types (BR-021). */
    public const UNIT_PER_MILE    = 'per_mile';
    public const UNIT_FLAT        = 'flat';
    public const UNIT_PERCENTAGE  = 'percentage';

    /** @var list<string> */
    private const UNIT_TYPES = [self::UNIT_PER_MILE, self::UNIT_FLAT, self::UNIT_PERCENTAGE];

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new versioned cost profile for a carrier (FR-051/BR-001/021).
     *
     * Each carrier can have multiple cost profile versions. Versions are immutable:
     * creating a new version does not modify existing ones. Version numbers increment
     * from the current max + 1.
     *
     * @param int      $companyId    Tenant scope (FR-042).
     * @param int      $carrierId    Carrier this profile belongs to.
     * @param string   $unitType     One of: per_mile, flat, percentage (BR-021).
     * @param float    $rate         The cost rate (BR-001).
     * @param string   $effectiveFrom Start of validity (YYYY-MM-DD, FR-051).
     * @param string|null $effectiveTo End of validity (null = still current, FR-051).
     * @param bool     $isComplete   Whether required inputs are present.
     * @return int Profile ID.
     * @throws \InvalidArgumentException if unit_type is invalid (BR-021).
     * @throws \RuntimeException on DB error.
     */
    public function createVersion(
        int $companyId,
        int $carrierId,
        string $unitType,
        float $rate,
        string $effectiveFrom,
        ?string $effectiveTo,
        bool $isComplete
    ): int {
        // BR-021: validate unit_type against the allowed set.
        if (!in_array($unitType, self::UNIT_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid unit_type "%s"; must be one of: %s (BR-021).', $unitType, implode(', ', self::UNIT_TYPES))
            );
        }

        // FR-051: version is immutable — increment from current max.
        $version = $this->nextVersion($companyId, $carrierId);

        $toVal = $effectiveTo !== null ? $effectiveTo : null;
        $completeVal = (int) $isComplete;

        $stmt = $this->db->prepare(
            'INSERT INTO carrier_cost_profiles '
            . '(company_id, carrier_id, is_complete, unit_type, rate, effective_from, effective_to, version) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare cost profile insert: ' . $this->db->error);
        }
        $stmt->bind_param('iiisdssi', $companyId, $carrierId, $completeVal, $unitType, $rate, $effectiveFrom, $toVal, $version);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to create cost profile: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();

        // FR-029: audit.
        $audit = new AuditLog($this->db);
        $audit->recordSystem('cost.create_version', $companyId, 'system', 'create', [
            'profile_id'    => $id,
            'carrier_id'    => $carrierId,
            'unit_type'     => $unitType,
            'rate'          => $rate,
            'version'       => $version,
            'effective_from' => $effectiveFrom,
            'effective_to'  => $effectiveTo,
        ]);

        return $id;
    }

    /**
     * Get the active (current) cost profile for a carrier at a given date (FR-051/BR-001).
     *
     * Returns the latest effective, complete, non-stale version. Stale (effective_to in the past)
     * and incomplete profiles are excluded from results.
     *
     * @param int    $companyId Tenant scope (SEC-010/FR-042).
     * @param int    $carrierId Carrier to look up.
     * @param string $asOf      Date to evaluate (YYYY-MM-DD). Defaults to 'today'.
     * @return array<string,mixed>|null The active profile row, or null if none.
     */
    public function getActiveAt(int $companyId, int $carrierId, string $asOf = 'today'): ?array
    {
        $asOfSql = $asOf === 'today' ? 'CURDATE()' : "'" . $this->db->real_escape_string($asOf) . "'";

        $sql = 'SELECT id, company_id, carrier_id, unit_type, rate, effective_from, effective_to, version, is_complete, created_at '
            . 'FROM carrier_cost_profiles '
            . 'WHERE company_id = ? AND carrier_id = ? AND deleted_at IS NULL '
            . 'AND is_complete = 1 '
            . 'AND effective_from <= ' . $asOfSql . ' '
            . 'AND (effective_to IS NULL OR effective_to >= ' . $asOfSql . ') '
            . 'ORDER BY version DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare active cost profile query: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $companyId, $carrierId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row === null || $row === false ? null : $row;
    }

    /**
     * List all cost profiles for a carrier, tenant-scoped (SEC-010/FR-042).
     *
     * @return list<array<string,mixed>>
     */
    public function listForCarrier(int $companyId, int $carrierId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, unit_type, rate, effective_from, effective_to, version, is_complete, status '
            . 'FROM carrier_cost_profiles '
            . 'WHERE company_id = ? AND carrier_id = ? AND deleted_at IS NULL '
            . 'ORDER BY version DESC'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare list query: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $companyId, $carrierId);
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
     * Get the next version number for this carrier (current max + 1, or 1 if none).
     */
    private function nextVersion(int $companyId, int $carrierId): int
    {
        $stmt = $this->db->prepare(
            'SELECT MAX(version) FROM carrier_cost_profiles WHERE company_id = ? AND carrier_id = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare version query: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $companyId, $carrierId);
        $stmt->execute();
        $result = $stmt->get_result();
        $max = $result !== false ? $result->fetch_column() : null;
        $stmt->close();
        $current = is_scalar($max) ? (int) $max : 0;
        return $current + 1;
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
