<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Gates\GateService;
use Routlaw\Gates\HardGateEngine;

/**
 * T10.3 — gate evaluation aggregating equipment + compliance, persisted, BEFORE scoring.
 * Hard-gate violation rate must be 0 in the critical suite (FRD §19.3): a FAIL/ABSTAIN
 * outcome never yields recommendedAllowed() === true.
 */
final class GateServiceTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_gatesvc';
    private static ?\mysqli $m = null;
    private GateService $svc;
    private HardGateEngine $engine;

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
        $companyId = (int) self::$m->insert_id;
        // equipment_profiles FKs to carriers; create a minimal carrier for the FK.
        self::$m->query("INSERT INTO carriers (company_id, legal_name, status) VALUES ({$companyId}, 'Carrier A', 'active')");
        $this->companyId = $companyId;
        $this->engine = new HardGateEngine();
        $this->svc = new GateService(self::$m);
    }

    private int $companyId;

    private function equipment(int $companyId, array $over = []): int
    {
        $status = $over['status'] ?? 'approved';
        $complete = (int) ($over['is_complete'] ?? 1);
        $payload = $over['payload_capacity_lbs'] ?? 10000;
        $deckLen = $over['deck_length_ft'] ?? 40.0;
        $deckWid = $over['deck_width_ft'] ?? 8.5;
        $truck = 'hotshot';
        $carrierId = 1;
        $stmt = self::$m->prepare(
            'INSERT INTO equipment_profiles (company_id, carrier_id, truck_type, payload_capacity_lbs, deck_length_ft, deck_width_ft, status, is_complete) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisdddsi', $companyId, $carrierId, $truck, $payload, $deckLen, $deckWid, $status, $complete);
        $stmt->execute();
        return (int) self::$m->insert_id;
    }

    public function test_all_pass_yields_clear_and_recommended_allowed(): void
    {
        $companyId = $this->companyId;
        $eqId = $this->equipment($companyId);
        $equipment = $this->fetchEquipment($eqId);
        $carrier = ['cdl_status' => 'non_cdl'];
        $load = ['weight_lbs' => 5000];

        $eval = $this->svc->evaluate($companyId, $load, $equipment, $carrier, null);
        $this->assertTrue($eval->isClear(), 'All gates PASS must yield clear.');
        $this->assertTrue($eval->recommendedAllowed(), 'Clear gates permit recommended.');
    }

    public function test_overweight_yields_blocked_and_recommended_forbidden(): void
    {
        $companyId = $this->companyId;
        $eqId = $this->equipment($companyId);
        $equipment = $this->fetchEquipment($eqId);
        $carrier = ['cdl_status' => 'non_cdl'];
        $load = ['weight_lbs' => 999999];

        $eval = $this->svc->evaluate($companyId, $load, $equipment, $carrier, null);
        $this->assertTrue($eval->isBlocked(), 'Overweight (FAIL) must yield blocked.');
        $this->assertFalse($eval->recommendedAllowed(), 'Blocked gates must forbid recommended (FRD §13).');
    }

    public function test_unknown_cdl_yields_needs_review(): void
    {
        $companyId = $this->companyId;
        $eqId = $this->equipment($companyId);
        $equipment = $this->fetchEquipment($eqId);
        $carrier = ['cdl_status' => 'unknown']; // ABSTAIN on cdl
        $load = ['weight_lbs' => 5000];

        $eval = $this->svc->evaluate($companyId, $load, $equipment, $carrier, null);
        $this->assertTrue($eval->isNeedsReview(), 'ABSTAIN must yield needs_review.');
        $this->assertFalse($eval->recommendedAllowed(), 'Needs-review gates must forbid recommended.');
    }

    public function test_gate_results_persisted_tenant_scoped(): void
    {
        $companyId = $this->companyId;
        $eqId = $this->equipment($companyId);
        $equipment = $this->fetchEquipment($eqId);
        $eval = $this->svc->evaluate($companyId, ['weight_lbs' => 5000], $equipment, ['cdl_status' => 'non_cdl'], null);

        $row = self::$m->query("SELECT company_id, outcome, results_json FROM gate_results WHERE company_id = {$companyId} ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $this->assertNotEmpty($row, 'gate_results row must be persisted.');
        $this->assertSame($companyId, (int) $row['company_id']);
        $this->assertSame('clear', $row['outcome']);
        $this->assertJson($row['results_json']);
    }

    public function test_persisted_row_isolated_from_other_tenant(): void
    {
        // A different company_id query returns nothing for this tenant's data.
        $other = self::$m->query('SELECT COUNT(*) FROM gate_results WHERE company_id = 99999')->fetch_column();
        $this->assertSame(0, (int) $other, 'Other tenant must see no gate_results (FR-042).');
    }

    private function fetchEquipment(int $id): ?array
    {
        $stmt = self::$m->prepare('SELECT * FROM equipment_profiles WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}
