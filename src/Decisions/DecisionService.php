<?php
declare(strict_types=1);

namespace Routlaw\Decisions;

use Routlaw\Gates\GateEvaluation;
use Routlaw\Security\AuditLog;

/**
 * T12.2 — decision alternatives generator (FR-054/055/056/059).
 *
 * Given the pre-scoring gate evaluation (T10.3) plus operator context, produce structured
 * alternatives: accept / reject / negotiate / delay / combine / avoid / abstain.
 * Each alternative carries reasons, risks, assumptions, confidence, and next-action so the
 * interface can expose evidence without relying on color alone (FRD §6.1 / §19).
 *
 * Rules:
 *  - BLOCKED gate (hard fail) ⇒ reject is primary; accept is never 'recommended'.
 *  - NEEDS_REVIEW gate (uncertainty/ABSTAIN) ⇒ at least one alternative is 'needs_review'.
 *  - Schedule/commitment conflict (FR-059) ⇒ forces needs_review even when gates clear.
 *  - Missing mandatory evidence ⇒ an 'abstain' alternative is present (BR-005, no fabrication).
 *  - Confidence is never the sole signal: every alternative requires reasons/risks/assumptions.
 *
 * Tenant-scoped: company_id carried on persisted rows (T12.3); generation is pure logic here.
 */
final class DecisionService
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<string,mixed> $context Operator context (schedule_conflict, etc.).
     * @param array<string,mixed> $inputs  Original decision inputs (for abstain detection).
     * @return list<array<string,mixed>> Each: alternative, status, reasons, risks, assumptions, confidence, next_action.
     */
    public function generateAlternatives(int $companyId, GateEvaluation $gate, array $context, array $inputs): array
    {
        $missingMandatory = (bool) ($inputs['missing_mandatory'] ?? false);
        $scheduleConflict = (bool) ($context['schedule_conflict'] ?? false);

        $alternatives = [];

        // Base set present in every case (so the interface always has the full menu).
        $alternatives[] = $this->build('accept', $this->acceptStatus($gate, $scheduleConflict), [
            'Carrier cleared hard gates and economics are computable.',
        ], ['Market/rate movement before execution.'], ['Posted rate and distance are accurate.'], 'medium', 'Confirm rate and dispatch.');

        $alternatives[] = $this->build('reject', $gate->isBlocked() ? 'recommended' : 'available', [
            'Hard-gate failure or unfavorable economics.',
        ], ['Lost opportunity.'], ['Gate/input data is current.'], 'high', 'Decline and log reason.');

        $alternatives[] = $this->build('negotiate', 'available', [
            'Rate or terms may be improvable.',
        ], ['Counterparty may decline.'], ['Counterparty is reachable.'], 'medium', 'Open negotiation on rate/terms.');

        $alternatives[] = $this->build('delay', 'available', [
            'Timing may improve; no hard conflict.',
        ], ['Window may close.'], ['No imminent commitment.'], 'medium', 'Re-evaluate at next window.');

        $alternatives[] = $this->build('combine', 'available', [
            'Load may pair with another to improve economics.',
        ], ['Coordination complexity.'], ['A compatible backhaul exists.'], 'low', 'Seek a combinable load.');

        $alternatives[] = $this->build('avoid', 'available', [
            'Risk profile exceeds tolerance.',
        ], ['Foregone revenue.'], ['Risk assessment is current.'], 'medium', 'Exclude from consideration.');

        // Needs-review exposure when gates/uncertainty/schedule force human review.
        if ($gate->isNeedsReview() || $scheduleConflict) {
            $alternatives[] = $this->build('needs_review', 'needs_review', [
                'Uncertainty or schedule/commitment conflict requires human review (FR-059).',
            ], ['Decision made without review.'], ['Reviewer resolves the open item.'], 'low', 'Escalate to human review.');
        }

        // Missing mandatory evidence ⇒ abstain (never fabricate a recommendation, BR-005).
        if ($missingMandatory) {
            $alternatives[] = $this->build('abstain', 'abstain', [
                'Mandatory evidence is missing; cannot recommend without fabrication.',
            ], ['No decision rendered.'], ['Missing data supplied.'], 'low', 'Collect mandatory inputs, then re-evaluate.');
        }

        // FR-029/§19: audit that alternatives were generated (traceability), tenant-scoped.
        $audit = new AuditLog($this->db);
        $audit->recordSystem('decision.generate_alternatives', $companyId, 'system', 'read', [
            'gate_outcome' => $gate->outcome,
            'schedule_conflict' => $scheduleConflict,
            'missing_mandatory' => $missingMandatory,
            'alternative_count' => count($alternatives),
        ]);

        return $alternatives;
    }

    /**
     * Persist a decision case, tenant-scoped (FR-042). Write path always scopes by company_id.
     *
     * @param int|null $gateResultId FK to gate_results (T10.3), or null.
     * @param int|null $loadId
     * @param int|null $carrierId
     * @param string $status clear|needs_review|recommended|rejected|approved.
     * @param string|null $selectedAlternative accept|reject|negotiate|delay|combine|avoid|abstain|null.
     * @param string|null $decisionNote Reason-coded note (visible hard-fail reasons, FRD §6.1).
     * @return int decision_case id.
     */
    public function createCase(
        int $companyId,
        ?int $gateResultId,
        ?int $loadId,
        ?int $carrierId,
        string $status,
        ?string $selectedAlternative,
        ?string $decisionNote,
        int $actorId
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO decision_cases '
            . '(company_id, gate_result_id, load_id, carrier_id, status, selected_alternative, decision_note, created_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare decision_cases insert: ' . $this->db->error);
        }
        $params = [$companyId, $gateResultId, $loadId, $carrierId, $status, $selectedAlternative, $decisionNote, $actorId];
        $types = 'iiiisssi';
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to create decision case: ' . $this->db->error);
        }
        $id = (int) $this->db->insert_id;
        $stmt->close();

        $audit = new AuditLog($this->db);
        $audit->recordSystem('decision.create_case', $companyId, 'user', 'create', [
            'decision_case_id' => $id,
            'status' => $status,
            'selected_alternative' => $selectedAlternative,
        ], 'decision_cases', (string) $id);

        return $id;
    }

    /**
     * Read back a decision case, tenant-scoped. Returns null if not found in this tenant
     * (defense-in-depth: write path is scoped; read path is scoped too).
     *
     * @return array<string,mixed>|null
     */
    public function getCase(int $companyId, int $caseId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM decision_cases WHERE id = ? AND company_id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare decision_cases select: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $caseId, $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException('Failed to fetch decision case: ' . $this->db->error);
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        return ($row === null || $row === false) ? null : $row;
    }

    /**
     * Guard against the bind_param type-count pitfall (mem_3ac32f9e4c59): a shifted type
     * string causes SILENT data truncation with no error. Assert arity before execute.
     *
     * @param list<mixed> $params
     */
    /**
     * Guard against the bind_param type-count pitfall (mem_3ac32f9e4c59): a shifted type
     * string causes SILENT data truncation with no error. Assert arity before execute.
     *
     * @param list<mixed> $params
     */
    private function assertBindArity(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if (strlen($types) !== count($params)) {
            $stmt->close();
            throw new \LogicException(sprintf(
                'bind_param type/param arity mismatch: %d types vs %d params',
                strlen($types), count($params)
            ));
        }
    }

    private function acceptStatus(GateEvaluation $gate, bool $scheduleConflict): string
    {
        if ($gate->isBlocked()) {
            return 'blocked'; // accept not permissible under a hard failure
        }
        if ($gate->isNeedsReview() || $scheduleConflict) {
            return 'needs_review';
        }
        return 'recommended';
    }

    /**
     * @param list<string> $reasons
     * @param list<string> $risks
     * @param list<string> $assumptions
     * @return array<string,mixed>
     */
    private function build(
        string $alternative,
        string $status,
        array $reasons,
        array $risks,
        array $assumptions,
        string $confidence,
        string $nextAction
    ): array {
        return [
            'alternative' => $alternative,
            'status'      => $status,
            'reasons'     => $reasons,
            'risks'       => $risks,
            'assumptions' => $assumptions,
            'confidence'  => $confidence,
            'next_action' => $nextAction,
        ];
    }
}
