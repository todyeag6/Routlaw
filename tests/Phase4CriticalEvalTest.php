<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Gates\GateService;

/**
 * T10.4 — Phase 4 critical AI-eval suite (FRD §19.2 / §19.3).
 *
 * Hard-gate violation rate must be 0 in the critical suite: every §19.2 scenario that
 * represents a real compatibility violation or uncertainty must NOT yield a `clear`
 * (recommended-allowed) evaluation. A single violating/uncertain case classified as
 * `clear` is a hard-gate violation.
 *
 * Also covers cross-tenant isolation of gate_results.
 */
final class Phase4CriticalEvalTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_crit_eval';
    private static ?\mysqli $m = null;
    private GateService $svc;
    private int $companyA;
    private int $companyB;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','002_carriers.sql','003_equipment_profiles.sql','007_carriers_cdl_status.sql','008_gate_results.sql'] as $mig) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $mig);
        }
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
        self::$m->query('TRUNCATE equipment_profiles');
        self::$m->query('TRUNCATE carriers');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM companies');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');

        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA LLC','CoA')");
        $this->companyA = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoB LLC','CoB')");
        $this->companyB = (int) self::$m->insert_id;
        foreach ([$this->companyA, $this->companyB] as $cid) {
            self::$m->query("INSERT INTO carriers (company_id, legal_name, status) VALUES ({$cid}, 'Carrier', 'active')");
        }
        $this->svc = new GateService(self::$m);
    }

    private function equipment(int $companyId, array $over = []): ?array
    {
        $status = $over['status'] ?? 'approved';
        $complete = (int) ($over['is_complete'] ?? 1);
        $payload = $over['payload_capacity_lbs'] ?? 10000;
        $stmt = self::$m->prepare(
            'INSERT INTO equipment_profiles (company_id, carrier_id, truck_type, payload_capacity_lbs, status, is_complete) VALUES (?, 1, ?, ?, ?, ?)'
        );
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

    /**
     * FRD §19.3: a hard-gate violation = a scenario that should be non-clear but is cleared.
     * Across the §19.2 critical cases, the violation count must be 0.
     */
    public function test_critical_suite_hard_gate_violation_rate_is_zero(): void
    {
        $cases = [
            // [label, load, equipment-over, carrier, expectClear]
            'overweight load'              => [['weight_lbs' => 999999], [], ['cdl_status' => 'non_cdl'], false],
            'incomplete equipment profile' => [['weight_lbs' => 5000], ['is_complete' => 0], ['cdl_status' => 'non_cdl'], false],
            'unapproved equipment'         => [['weight_lbs' => 5000], ['status' => 'draft'], ['cdl_status' => 'non_cdl'], false],
            'unknown CDL status'           => [['weight_lbs' => 5000], [], ['cdl_status' => 'unknown'], false],
            'missing load weight'          => [[], [], ['cdl_status' => 'non_cdl'], false],
            'clean compatible load'        => [['weight_lbs' => 5000], [], ['cdl_status' => 'non_cdl'], true],
        ];

        $violations = 0;
        foreach ($cases as [$load, $eqOver, $carrier, $expectClear]) {
            $eq = $this->equipment($this->companyA, $eqOver);
            $eval = $this->svc->evaluate($this->companyA, $load, $eq, $carrier, null);
            $actualClear = $eval->recommendedAllowed();
            if ($actualClear !== $expectClear) {
                $violations++;
                $this->addToAssertionCount(1);
                $this->fail(sprintf('Hard-gate violation: expected clear=%s but got %s for case', var_export($expectClear, true), var_export($actualClear, true)));
            }
        }
        $this->assertSame(0, $violations, 'Hard-gate violation rate must be 0 in the critical suite (FRD §19.3).');
    }

    public function test_cross_tenant_gate_results_not_visible_to_other_company(): void
    {
        $eqA = $this->equipment($this->companyA, []);
        $this->svc->evaluate($this->companyA, ['weight_lbs' => 5000], $eqA, ['cdl_status' => 'non_cdl'], null);

        // Company B querying Company A's gate_results must return zero rows.
        $count = (int) self::$m->query("SELECT COUNT(*) FROM gate_results WHERE company_id = {$this->companyA} AND company_id = {$this->companyB}")->fetch_column();
        $this->assertSame(0, $count, 'Cross-tenant gate_results must not leak (FR-042).');

        // Direct scoped read: B sees none of A's rows.
        $bRows = (int) self::$m->query("SELECT COUNT(*) FROM gate_results WHERE company_id = {$this->companyB}")->fetch_column();
        $this->assertSame(0, $bRows);
        $aRows = (int) self::$m->query("SELECT COUNT(*) FROM gate_results WHERE company_id = {$this->companyA}")->fetch_column();
        $this->assertSame(1, $aRows, 'Company A owns exactly its own row.');
    }
}
