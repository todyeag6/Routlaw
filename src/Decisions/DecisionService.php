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
