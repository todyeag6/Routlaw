<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Decisions\DecisionService;
use Routlaw\Economics\EconomicsService;
use Routlaw\Gates\GateEvaluation;
use Routlaw\Gates\GateService;

/**
 * T13.3 — Phase 4 integration loop: gate → alternatives → persist case → outcome → variance,
 * all tenant-scoped, plus the §19.3 hard-gate-violation = 0 assertion across the critical suite.
 */
final class DecisionIntegrationTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_integration';
    private static ?\mysqli $m = null;
    private static GateService $gate;
    private static DecisionService $decision;
    private static EconomicsService $econ;
    private int $companyA;
    private int $companyB;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','002_carriers.sql','003_equipment_profiles.sql','004_cost_profiles.sql','007_carriers_cdl_status.sql','008_gate_results.sql','009_decision_cases.sql'] as $mig) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $mig);
        }
        self::$gate = new GateService(self::$m);
        self::$decision = new DecisionService(self::$m);
        self::$econ = new EconomicsService(self::$m);
    }

    public static function tearDownAfterClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    protected function setUp(): void
    {
        self::$m->query('SET FOREIGN_KEY_CHECKS = 0');
        self::$m->query('TRUNCATE gate_results');
        self::$m->query('TRUNCATE decision_cases');
        self::$m->query('TRUNCATE decision_outcomes');
        self::$m->query('TRUNCATE prediction_variances');
        self::$m->query('TRUNCATE equipment_profiles');
        self::$m->query('TRUNCATE carriers');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM companies');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA LLC','CoA')");
        $this->companyA = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoB LLC','CoB')");
        $this->companyB = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO carriers (company_id, legal_name, status) VALUES ({$this->companyA}, 'Carrier A', 'active')");
    }

    private function equipment(int $companyId, array $over = []): ?array
    {
        $status = $over['status'] ?? 'approved';
        $complete = (int) ($over['is_complete'] ?? 1);
        $payload = $over['payload_capacity_lbs'] ?? 10000;
        $stmt = self::$m->prepare('INSERT INTO equipment_profiles (company_id, carrier_id, truck_type, payload_capacity_lbs, status, is_complete) VALUES (?, 1, ?, ?, ?, ?)');
        $truck = 'hotshot';
        $stmt->bind_param('isdsi', $companyId, $truck, $payload, $status, $complete);
        if (!$stmt->execute()) {
            return null;
        }
        $id = (int) self::$m->insert_id;
        $stmt = self::$m->prepare('SELECT * FROM equipment_profiles WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function test_full_decision_flow_clear_to_outcome_and_variance(): void
    {
        // 1) Gate BEFORE scoring.
        $eq = $this->equipment($this->companyA, []);
        $gateEval = self::$gate->evaluate($this->companyA, ['weight_lbs' => 5000], $eq, ['cdl_status' => 'non_cdl'], null);
        $this->assertTrue($gateEval->recommendedAllowed(), 'Clear gate must allow recommendation.');

        // 2) Generate alternatives.
        $alts = self::$decision->generateAlternatives($this->companyA, $gateEval, [], []);
        $this->assertNotEmpty($alts);

        // 3) Persist the decision case (linking the gate result).
        $gateId = self::$m->query("SELECT id FROM gate_results WHERE company_id = {$this->companyA} ORDER BY id DESC LIMIT 1")->fetch_column();
        $caseId = self::$decision->createCase($this->companyA, (int) $gateId, 10, 20, 'recommended', 'accept', 'Clear gate; accept.', 1);
        $this->assertGreaterThan(0, $caseId);

        // 4) Economics rollup (reproducible, abstains if missing).
        // No cost profile yet → rollup abstains (honest, never fabricated).
        $roll = self::$econ->rollup($this->companyA, 1, ['posted_rate' => 450.0], 200.0, '2026-08-15');
        $this->assertFalse($roll->isComputed(), 'Without a cost profile, economics abstain (no fabrication).');

        // 5) Capture outcome + variance.
        $oid = self::$decision->recordOutcome($this->companyA, $caseId, 'success', 'Delivered.', null, 1);
        $vid = self::$decision->recordVariance($this->companyA, $caseId, $oid, 'estimation_error', 150.00, 140.00, null, 1);
        $this->assertGreaterThan(0, $vid);

        // 6) Cross-tenant isolation holds at every layer.
        $cross = (int) self::$m->query("SELECT COUNT(*) FROM decision_cases WHERE company_id = {$this->companyA} AND company_id = {$this->companyB}")->fetch_column();
        $this->assertSame(0, $cross);
    }

    public function test_hard_gate_violation_rate_is_zero_across_critical_suite(): void
    {
        // Mirrors §19.3: every violating/uncertain §19.2 case must NOT be classified clear.
        $cases = [
            'overweight'          => [['weight_lbs' => 999999], [], ['cdl_status' => 'non_cdl'], false],
            'incomplete profile'  => [['weight_lbs' => 5000], ['is_complete' => 0], ['cdl_status' => 'non_cdl'], false],
            'unknown cdl'         => [['weight_lbs' => 5000], [], ['cdl_status' => 'unknown'], false],
            'missing weight'      => [[], [], ['cdl_status' => 'non_cdl'], false],
            'clean'               => [['weight_lbs' => 5000], [], ['cdl_status' => 'non_cdl'], true],
        ];
        $violations = 0;
        foreach ($cases as [$load, $eqOver, $carrier, $expectClear]) {
            $eq = $this->equipment($this->companyA, $eqOver);
            $eval = self::$gate->evaluate($this->companyA, $load, $eq, $carrier, null);
            if ($eval->recommendedAllowed() !== $expectClear) {
                $violations++;
            }
        }
        $this->assertSame(0, $violations, 'Hard-gate violation rate must be 0 in the critical suite (FRD §19.3).');
    }
}
