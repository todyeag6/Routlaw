<?php
declare(strict_types=1);

namespace Routlaw\Gates;

use Routlaw\Security\AuditLog;

/**
 * T10.3 — aggregate hard-gate evaluation, persisted, runs BEFORE scoring.
 *
 * Combines the deterministic equipment gates (T10.1) and the FR-016 compliance flag
 * (T10.2) into a single GateEvaluation. Outcome derivation:
 *   - any FAIL            → BLOCKED  (cannot be recommended)
 *   - any ABSTAIN (none FAIL) → NEEDS_REVIEW (cannot be recommended, route to human)
 *   - all PASS            → CLEAR   (recommendation permitted)
 *
 * Result is persisted to gate_results (tenant-scoped) so a decision case can reference
 * it and the hard-gate violation rate is auditable (FRD §19.3).
 */
final class GateService
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Evaluate all hard gates for a (load, equipment, carrier) triple and persist the result.
     *
     * @param int $companyId Tenant scope (FR-042).
     * @param array<string,mixed> $load
     * @param array<string,mixed>|null $equipment
     * @param array<string,mixed> $carrier
     * @param array<string,mixed>|null $extra Reserved for future gate inputs.
     * @return GateEvaluation
     */
    public function evaluate(
        int $companyId,
        array $load,
        ?array $equipment,
        array $carrier,
        ?array $extra
    ): GateEvaluation {
        $engine = new HardGateEngine();
        $results = array_merge(
            $engine->evaluateEquipment($load, $equipment),
            $engine->evaluateCompliance($carrier, $load, $equipment)
        );

        $outcome = $this->deriveOutcome($results);
        $this->persist($companyId, $outcome, $results);

        return new GateEvaluation($outcome, $results);
    }

    /**
     * @param list<GateResult> $results
     */
    private function deriveOutcome(array $results): string
    {
        $hasFail = false;
        $hasAbstain = false;
        foreach ($results as $r) {
            if ($r->isFail()) {
                $hasFail = true;
            } elseif ($r->isAbstain()) {
                $hasAbstain = true;
            }
        }
        if ($hasFail) {
            return GateEvaluation::BLOCKED;
        }
        if ($hasAbstain) {
            return GateEvaluation::NEEDS_REVIEW;
        }
        return GateEvaluation::CLEAR;
    }

    /**
     * @param list<GateResult> $results
     */
    private function persist(int $companyId, string $outcome, array $results): void
    {
        $json = json_encode(array_map(static fn (GateResult $r) => [
            'gate' => $r->gate,
            'outcome' => $r->outcome,
            'reason' => $r->reason,
            'message' => $r->message,
            'detail' => $r->detail,
        ], $results), JSON_THROW_ON_ERROR);

        $stmt = $this->db->prepare(
            'INSERT INTO gate_results (company_id, outcome, results_json) VALUES (?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare gate_results insert: ' . $this->db->error);
        }
        $stmt->bind_param('iss', $companyId, $outcome, $json);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to persist gate_results: ' . $this->db->error);
        }
        $stmt->close();

        $audit = new AuditLog($this->db);
        $audit->recordSystem('gate.evaluate', $companyId, 'system', 'create', [
            'outcome' => $outcome,
            'gate_count' => count($results),
        ]);
    }
}
