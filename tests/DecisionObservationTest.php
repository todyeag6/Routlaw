<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Decisions\DecisionService;

/**
 * T13.1 — counterparty/facility observations (FR-057/058) + tenant isolation.
 */
final class DecisionObservationTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_decision_obs';
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
        self::$m->query('TRUNCATE counterparty_observations');
        self::$m->query('TRUNCATE facility_observations');
        self::$m->query('TRUNCATE decision_cases');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM companies');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA LLC','CoA')");
        $this->companyA = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoB LLC','CoB')");
        $this->companyB = (int) self::$m->insert_id;
    }

    public function test_counterparty_observation_persists_and_reads_back(): void
    {
        $id = self::$svc->recordCounterpartyObservation(
            $this->companyA, null, 'broker', 'BROKER-1', 'Slow to confirm rate.', 'watch', 1
        );
        $this->assertGreaterThan(0, $id);
        $rows = self::$m->query("SELECT * FROM counterparty_observations WHERE id = {$id}")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame($this->companyA, (int) $rows[0]['company_id']);
        $this->assertSame('broker', $rows[0]['counterparty_type']);
        $this->assertSame('Slow to confirm rate.', $rows[0]['observation']);
        $this->assertSame('watch', $rows[0]['severity']);
    }

    public function test_facility_observation_captures_uncertainty(): void
    {
        $id = self::$svc->recordFacilityObservation(
            $this->companyA, null, 'reload', 'FAC-9', 'Possible reload window.', 'Timing not confirmed by facility.', 'info', 1
        );
        $this->assertGreaterThan(0, $id);
        $rows = self::$m->query("SELECT * FROM facility_observations WHERE id = {$id}")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $rows);
        // Explicit uncertainty must be stored, never silently assumed away (FR-058).
        $this->assertSame('Timing not confirmed by facility.', $rows[0]['uncertainty']);
    }

    public function test_observations_not_leaked_across_tenants(): void
    {
        self::$svc->recordCounterpartyObservation($this->companyA, null, 'broker', 'B1', 'obs A', 'info', 1);
        self::$svc->recordFacilityObservation($this->companyB, null, 'pickup', 'F1', 'obs B', null, 'info', 1);

        $aCp = (int) self::$m->query("SELECT COUNT(*) FROM counterparty_observations WHERE company_id = {$this->companyA}")->fetch_column();
        $bCp = (int) self::$m->query("SELECT COUNT(*) FROM counterparty_observations WHERE company_id = {$this->companyB}")->fetch_column();
        $this->assertSame(1, $aCp);
        $this->assertSame(0, $bCp, 'Company B must not own Company A counterparty observations (FR-042).');

        $aFac = (int) self::$m->query("SELECT COUNT(*) FROM facility_observations WHERE company_id = {$this->companyA}")->fetch_column();
        $bFac = (int) self::$m->query("SELECT COUNT(*) FROM facility_observations WHERE company_id = {$this->companyB}")->fetch_column();
        $this->assertSame(0, $aFac);
        $this->assertSame(1, $bFac, 'Company A must not own Company B facility observations (FR-042).');
    }
}
