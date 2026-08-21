<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Decisions\DecisionService;
use Routlaw\Gates\GateEvaluation;

/**
 * T12.2 — decision alternatives generator (FR-054/055/056/059).
 * Produces structured alternatives (accept/reject/negotiate/delay/combine/avoid),
 * each with reasons, risks, assumptions, confidence, next-action. Schedule/commitment
 * conflict ⇒ needs_review. Missing mandatory evidence ⇒ ABSTAIN (no fabrication).
 * Confidence is never the sole signal (reasons required).
 */
final class DecisionAlternativeTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_decision_alt';
    private static ?\mysqli $m = null;
    private static DecisionService $svc;
    private int $companyA;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','002_carriers.sql','003_equipment_profiles.sql','004_cost_profiles.sql','007_carriers_cdl_status.sql','008_gate_results.sql','009_decision_cases.sql'] as $mig) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $mig);
        }
        self::$svc = new DecisionService(self::$m);
    }

    public static function tearDownAfterClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    protected function setUp(): void
    {
        self::$m->query('SET FOREIGN_KEY_CHECKS = 0');
        self::$m->query('TRUNCATE decision_cases');
        self::$m->query('TRUNCATE decision_input_snapshots');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM companies');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA LLC','CoA')");
        $this->companyA = (int) self::$m->insert_id;
    }

    public function test_clear_gate_yields_structured_alternatives_with_required_fields(): void
    {
        $gate = new GateEvaluation(GateEvaluation::CLEAR, []);
        $alts = self::$svc->generateAlternatives($this->companyA, $gate, [], []);

        $this->assertNotEmpty($alts, 'A clear case must yield alternatives.');
        $allowed = ['accept','reject','negotiate','delay','combine','avoid'];
        foreach ($alts as $alt) {
            $this->assertContains($alt['alternative'], $allowed, 'Alternative must be one of the defined set.');
            $this->assertNotEmpty($alt['reasons'], 'Each alternative needs reasons (confidence not sole signal).');
            $this->assertNotEmpty($alt['risks'], 'Each alternative needs risks.');
            $this->assertNotEmpty($alt['assumptions'], 'Each alternative needs assumptions.');
            $this->assertArrayHasKey('confidence', $alt, 'Each alternative needs confidence.');
            $this->assertNotEmpty($alt['next_action'], 'Each alternative needs a next action.');
        }
    }

    public function test_blocked_gate_yields_reject_priority_and_no_recommended(): void
    {
        $gate = new GateEvaluation(GateEvaluation::BLOCKED, []);
        $alts = self::$svc->generateAlternatives($this->companyA, $gate, [], []);
        $this->assertNotEmpty($alts);
        // A blocked (hard-fail) case must include reject and must NOT mark accept as recommended.
        $alternatives = array_column($alts, 'alternative');
        $this->assertContains('reject', $alternatives);
        foreach ($alts as $alt) {
            if ($alt['alternative'] === 'accept') {
                $this->assertNotSame('recommended', $alt['status'] ?? '', 'Accept must not be recommended under a hard gate failure.');
            }
        }
    }

    public function test_needs_review_gate_yields_needs_review_status(): void
    {
        $gate = new GateEvaluation(GateEvaluation::NEEDS_REVIEW, []);
        $alts = self::$svc->generateAlternatives($this->companyA, $gate, [], []);
        $this->assertNotEmpty($alts);
        // At least one alternative must carry needs_review (cannot recommend under uncertainty).
        $hasReview = false;
        foreach ($alts as $alt) {
            if (($alt['status'] ?? '') === 'needs_review') {
                $hasReview = true;
            }
        }
        $this->assertTrue($hasReview, 'Needs-review gate must expose a needs_review alternative.');
    }

    public function test_schedule_conflict_forces_needs_review(): void
    {
        $gate = new GateEvaluation(GateEvaluation::CLEAR, []);
        $context = ['schedule_conflict' => true];
        $alts = self::$svc->generateAlternatives($this->companyA, $gate, $context, []);
        // Schedule/commitment conflict routes to human review even when gates clear.
        $hasReview = false;
        foreach ($alts as $alt) {
            if (($alt['status'] ?? '') === 'needs_review') {
                $hasReview = true;
            }
        }
        $this->assertTrue($hasReview, 'Schedule/commitment conflict must force needs_review (FR-059).');
    }

    public function test_missing_mandatory_evidence_abstains(): void
    {
        $gate = new GateEvaluation(GateEvaluation::CLEAR, []);
        // No load/carrier context at all → cannot generate a defensible recommendation.
        $alts = self::$svc->generateAlternatives($this->companyA, $gate, [], ['missing_mandatory' => true]);
        $this->assertNotEmpty($alts);
        $hasAbstain = false;
        foreach ($alts as $alt) {
            if (($alt['alternative'] ?? '') === 'abstain') {
                $hasAbstain = true;
                $this->assertSame('abstain', $alt['alternative']);
            }
        }
        $this->assertTrue($hasAbstain, 'Missing mandatory evidence must produce an abstain alternative (BR-005).');
    }
}
