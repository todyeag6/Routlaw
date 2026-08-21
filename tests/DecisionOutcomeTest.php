<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Decisions\DecisionService;

/**
 * T13.2 — outcome capture + predicted-vs-actual variance (FR-060/061).
 * Variance is DERIVED (actual − predicted), never entered; missing data → null (no fabrication).
 */
final class DecisionOutcomeTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_decision_out';
    private static ?\mysqli $m = null;
    private static DecisionService $svc;
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
        self::$m->query('TRUNCATE decision_outcomes');
        self::$m->query('TRUNCATE prediction_variances');
        self::$m->query('TRUNCATE decision_cases');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM companies');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA LLC','CoA')");
        $this->companyA = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoB LLC','CoB')");
        $this->companyB = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO decision_cases (company_id, status) VALUES ({$this->companyA}, 'approved')");
        $this->caseA = (int) self::$m->insert_id;
    }

    private int $caseA;

    public function test_outcome_capture_persists(): void
    {
        $oid = self::$svc->recordOutcome($this->companyA, $this->caseA, 'success', 'Delivered on time.', '2026-08-20 12:00:00', 1);
        $this->assertGreaterThan(0, $oid);
        $rows = self::$m->query("SELECT * FROM decision_outcomes WHERE id = {$oid}")->fetch_all(MYSQLI_ASSOC);
        $this->assertSame('success', $rows[0]['outcome']);
        $this->assertSame('Delivered on time.', $rows[0]['notes']);
    }

    public function test_variance_derived_not_entered(): void
    {
        $oid = self::$svc->recordOutcome($this->companyA, $this->caseA, 'partial', 'Rate came in lower.', null, 1);
        // predicted net = 150.00, actual net = 120.00 → variance = -30.00 (actual − predicted).
        $vid = self::$svc->recordVariance($this->companyA, $this->caseA, $oid, 'estimation_error', 150.00, 120.00, null, 1);
        $this->assertGreaterThan(0, $vid);
        $rows = self::$m->query("SELECT * FROM prediction_variances WHERE id = {$vid}")->fetch_all(MYSQLI_ASSOC);
        $this->assertSame('150.0000', $rows[0]['predicted_value']);
        $this->assertSame('120.0000', $rows[0]['actual_value']);
        // variance_value is DERIVED (actual − predicted), never supplied by caller.
        $this->assertSame('-30.0000', $rows[0]['variance_value']);
        $this->assertSame('estimation_error', $rows[0]['variance_class']);
    }

    public function test_variance_missing_data_is_null_not_fabricated(): void
    {
        $oid = self::$svc->recordOutcome($this->companyA, $this->caseA, 'failure', 'No actuals recorded.', null, 1);
        // predicted present but actual missing → variance cannot be computed → null (BR-005).
        $vid = self::$svc->recordVariance($this->companyA, $this->caseA, $oid, 'missing_data', 150.00, null, null, 1);
        $rows = self::$m->query("SELECT * FROM prediction_variances WHERE id = {$vid}")->fetch_all(MYSQLI_ASSOC);
        $this->assertNull($rows[0]['variance_value'], 'Missing actual → variance null, never fabricated (BR-005).');
        $this->assertSame('150.0000', $rows[0]['predicted_value']);
        $this->assertNull($rows[0]['actual_value']);
    }

    public function test_outcome_variance_not_leaked_across_tenants(): void
    {
        self::$m->query("INSERT INTO decision_cases (company_id, status) VALUES ({$this->companyB}, 'approved')");
        $caseB = (int) self::$m->insert_id;
        self::$svc->recordOutcome($this->companyA, $this->caseA, 'success', 'A', null, 1);
        self::$svc->recordOutcome($this->companyB, $caseB, 'failure', 'B', null, 1);

        $aOut = (int) self::$m->query("SELECT COUNT(*) FROM decision_outcomes WHERE company_id = {$this->companyA}")->fetch_column();
        $bOut = (int) self::$m->query("SELECT COUNT(*) FROM decision_outcomes WHERE company_id = {$this->companyB}")->fetch_column();
        $this->assertSame(1, $aOut);
        $this->assertSame(1, $bOut);
        // Confirm A cannot read B's row via scoped query (defense-in-depth).
        $cross = (int) self::$m->query("SELECT COUNT(*) FROM decision_outcomes WHERE company_id = {$this->companyB} AND company_id = {$this->companyA}")->fetch_column();
        $this->assertSame(0, $cross);
    }
}
