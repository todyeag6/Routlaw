<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Carriers\CarrierService;
use Routlaw\Costs\CostProfileService;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Economics\EconomicsService;

/**
 * T11.1 — versioned carrier economics from carrier_cost_profiles (FR-051/BR-001/021).
 * Total cost derived from the active versioned profile × entered distance, tagged with
 * the profile version + effective_from so the figure is reproducible from stored input.
 */
final class EconomicsTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_econ';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static CarrierService $carrierSvc;
    private static CostProfileService $costSvc;
    private static EconomicsService $econ;
    private int $companyA;
    private int $carrierId;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','002_carriers.sql','003_equipment_profiles.sql','004_cost_profiles.sql','007_carriers_cdl_status.sql'] as $mig) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $mig);
        }
        self::$auth = new Auth(self::$m);
        self::$carrierSvc = new CarrierService(self::$m);
        self::$costSvc = new CostProfileService(self::$m);
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
        self::$m->query('TRUNCATE carrier_cost_profiles');
        self::$m->query('TRUNCATE carriers');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM companies');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');

        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA LLC','CoA')");
        $this->companyA = (int) self::$m->insert_id;
        $this->carrierId = self::$carrierSvc->signup($this->companyA, 'Alpha Haul', '', 'DOT1', 'MC1', 'EIN1');
    }

    public function test_per_mile_cost_scales_with_distance_and_version(): void
    {
        $cpId = self::$costSvc->createVersion($this->companyA, $this->carrierId, 'per_mile', 1.50, '2026-08-01', null, true);
        $active = self::$costSvc->getActiveAt($this->companyA, $this->carrierId, '2026-08-15');

        $result = self::$econ->totalCost($this->companyA, $this->carrierId, 200.0, '2026-08-15');
        $this->assertTrue($result->isComputed(), 'Cost must be computed when inputs present.');
        $this->assertSame(300.0, $result->totalCost, '1.50/mi × 200mi = 300.00.');
        // Reproducibility: carries the version + effective_from used.
        $this->assertSame($cpId, $result->costProfileId);
        $this->assertSame('2026-08-01', $result->effectiveFrom);
        $this->assertSame(1.50, $result->rate);
        $this->assertSame('per_mile', $result->unitType);
    }

    public function test_flat_cost_ignores_distance(): void
    {
        self::$costSvc->createVersion($this->companyA, $this->carrierId, 'flat', 500.00, '2026-08-01', null, true);
        $result = self::$econ->totalCost($this->companyA, $this->carrierId, 999.0, '2026-08-15');
        $this->assertTrue($result->isComputed());
        $this->assertSame(500.0, $result->totalCost, 'Flat rate is distance-independent.');
    }

    public function test_missing_active_profile_abstains(): void
    {
        // No cost profile created → nothing to compute from.
        $result = self::$econ->totalCost($this->companyA, $this->carrierId, 100.0, '2026-08-15');
        $this->assertFalse($result->isComputed(), 'No active profile → ABSTAIN, never fabricate a rate.');
        $this->assertSame('no_active_cost_profile', $result->reason);
    }

    public function test_per_mile_without_distance_abstains(): void
    {
        self::$costSvc->createVersion($this->companyA, $this->carrierId, 'per_mile', 1.50, '2026-08-01', null, true);
        $result = self::$econ->totalCost($this->companyA, $this->carrierId, null, '2026-08-15');
        $this->assertFalse($result->isComputed(), 'per_mile requires distance; missing → ABSTAIN (no fabricated miles).');
        $this->assertSame('distance_required', $result->reason);
    }

    public function test_reproducible_across_calls(): void
    {
        self::$costSvc->createVersion($this->companyA, $this->carrierId, 'per_mile', 2.00, '2026-08-01', null, true);
        $r1 = self::$econ->totalCost($this->companyA, $this->carrierId, 150.0, '2026-08-15');
        $r2 = self::$econ->totalCost($this->companyA, $this->carrierId, 150.0, '2026-08-15');
        $this->assertSame($r1->totalCost, $r2->totalCost);
        $this->assertSame($r1->costProfileId, $r2->costProfileId);
        $this->assertSame($r1->effectiveFrom, $r2->effectiveFrom);
    }

    // ----- T11.2: total-mile/time rollup + honest abstention -----

    public function test_rollup_computes_net_vs_posted_rate(): void
    {
        self::$costSvc->createVersion($this->companyA, $this->carrierId, 'per_mile', 1.50, '2026-08-01', null, true);
        $load = ['posted_rate' => 450.00];
        $r = self::$econ->rollup($this->companyA, $this->carrierId, $load, 200.0, '2026-08-15');
        $this->assertTrue($r->isComputed(), 'Rollup must compute when all inputs present.');
        $this->assertSame(300.0, $r->totalCost);
        $this->assertSame(450.0, $r->postedRate);
        $this->assertSame(150.0, $r->net, 'net = posted_rate − totalCost = 150.00.');
    }

    public function test_rollup_abstains_without_posted_rate(): void
    {
        self::$costSvc->createVersion($this->companyA, $this->carrierId, 'per_mile', 1.50, '2026-08-01', null, true);
        $r = self::$econ->rollup($this->companyA, $this->carrierId, [], 200.0, '2026-08-15');
        $this->assertFalse($r->isComputed(), 'No posted_rate → ABSTAIN net, never guess.');
        $this->assertSame('posted_rate_required', $r->reason);
        $this->assertNull($r->net);
    }

    public function test_rollup_abstains_without_distance_for_per_mile(): void
    {
        self::$costSvc->createVersion($this->companyA, $this->carrierId, 'per_mile', 1.50, '2026-08-01', null, true);
        $r = self::$econ->rollup($this->companyA, $this->carrierId, ['posted_rate' => 450.0], null, '2026-08-15');
        $this->assertFalse($r->isComputed(), 'per_mile without distance → ABSTAIN, never fabricate miles.');
        $this->assertSame('distance_required', $r->reason);
    }

    public function test_rollup_reproducible_from_stored_version(): void
    {
        $cpId = self::$costSvc->createVersion($this->companyA, $this->carrierId, 'per_mile', 1.50, '2026-08-01', null, true);
        $r1 = self::$econ->rollup($this->companyA, $this->carrierId, ['posted_rate' => 450.0], 200.0, '2026-08-15');
        $r2 = self::$econ->rollup($this->companyA, $this->carrierId, ['posted_rate' => 450.0], 200.0, '2026-08-15');
        $this->assertSame($r1->totalCost, $r2->totalCost);
        $this->assertSame($r1->net, $r2->net);
        $this->assertSame($cpId, $r1->costProfileId);
        $this->assertSame('2026-08-01', $r1->effectiveFrom);
    }
}
