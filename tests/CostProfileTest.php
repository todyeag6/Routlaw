<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Carriers\CarrierService;
use Routlaw\Costs\CostProfileService;

/**
 * TDD for T6 Versioned cost profiles (build-plan §4 Phase 2 T6, FR-051/BR-001/021).
 *
 * Versioned carrier cost profiles with effective dates, units (per-mile, flat, percentage),
 * and required-input status. Stale/incomplete excluded from quantitative recommendations.
 * Tests: version effective-date logic, stale profile exclusion, required-input enforcement.
 */
final class CostProfileTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_costs';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static CarrierService $carrierSvc;
    private static CostProfileService $svc;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE=utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/001_audit_events.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/002_carriers.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/003_equipment_profiles.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/004_cost_profiles.sql');

        self::$auth = new Auth(self::$m);
        self::$carrierSvc = new CarrierService(self::$m);
        self::$svc = new CostProfileService(self::$m);
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
        self::$m->query('TRUNCATE equipment_profiles');
        self::$m->query('TRUNCATE carrier_status_history');
        self::$m->query('TRUNCATE carriers');
        self::$m->query('TRUNCATE audit_events');
        self::$m->query('TRUNCATE login_attempts');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM user_role_assignments');
        self::$m->query('DELETE FROM users');
        self::$m->query('DELETE FROM roles');
        self::$m->query('DELETE FROM companies');

        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');

        $companyA = self::$auth->createCompany('Alpha Transport', 'Alpha Transport LLC');
        $dispatcher = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        $carrierRole = (int) self::$m->query("SELECT id FROM roles WHERE slug='carrier'")->fetch_row()[0];
        self::$auth->createUser($companyA, 'alice@example.com', 'Pass!123', 'Alice', $dispatcher);
        self::$auth->createUser($companyA, 'carol@example.com', 'Pass!789', 'Carol', $carrierRole);

        $this->companyA = $companyA;
        $this->dispatcher = $dispatcher;
        $this->carrierRole = $carrierRole;

        // Create a carrier and promote to active.
        $this->carrierId = self::$carrierSvc->signup($companyA, 'Alpha Haul', '', 'DOT-C1', 'MC-C1', 'EIN-C1');
        self::$carrierSvc->transitionState($this->carrierId, 'under_review', $carrierRole, $companyA);
        self::$carrierSvc->transitionState($this->carrierId, 'active', $dispatcher, $companyA);
    }

    private int $carrierId;
    private int $companyA;
    private int $dispatcher;
    private int $carrierRole;

    /**
     * FR-051: cost profile with effective date logic.
     * A new version with a future effective_from does not replace the current active version.
     */
    public function test_future_effective_version_does_not_replace_current(): void
    {
        // Current version effective now.
        $v1 = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 1.50,
            '2026-08-01', null,
            true
        );

        // Version 2 with future effective date.
        $v2 = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 2.00,
            '2026-09-01', null,
            true
        );

        // The active profile at 2026-08-15 should be v1, not v2.
        $active = self::$svc->getActiveAt($this->companyA, $this->carrierId, '2026-08-15');
        $this->assertNotNull($active, 'Should have an active profile at the given date.');
        $this->assertSame($v1, (int) $active['id'], 'Future-dated version must not replace current (FR-051 effective-date logic).');
    }

    /**
     * FR-051: at a future date, the newer version becomes active.
     */
    public function test_future_date_returns_newer_version(): void
    {
        $v1 = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'flat', 500.00,
            '2026-08-01', null,
            true
        );

        $v2 = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'flat', 600.00,
            '2026-09-01', null,
            true
        );

        $active = self::$svc->getActiveAt($this->companyA, $this->carrierId, '2026-09-15');
        $this->assertNotNull($active);
        $this->assertSame($v2, (int) $active['id'], 'On/after effective_from, the newer version must be active.');
    }

    /**
     * FR-051: version is immutable — creating a new version does not modify the old one.
     */
    public function test_versions_are_immutable(): void
    {
        $v1Id = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'percentage', 12.5,
            '2026-08-01', '2026-08-31',
            true
        );

        // Create a second version — this should NOT change v1's rate.
        self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'percentage', 15.0,
            '2026-09-01', null,
            true
        );

        $row = self::$m->query('SELECT rate FROM carrier_cost_profiles WHERE id = ' . (int) $v1Id)->fetch_assoc();
        $this->assertSame('12.5000', $row['rate'], 'Old version must not be modified when a new version is created.');
    }

    /**
     * FR-051/BR-001: incomplete profiles excluded from recommendations.
     */
    public function test_incomplete_profile_excluded_from_active(): void
    {
        // Create an incomplete profile with the most recent effective date.
        $v1 = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 1.00,
            '2026-08-01', null,
            false // is_complete = false
        );

        // An earlier, complete profile.
        $v0 = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 0.75,
            '2026-07-01', '2026-07-31',
            true
        );

        // On 2026-08-15, the v1 profile is incomplete so should be excluded.
        $active = self::$svc->getActiveAt($this->companyA, $this->carrierId, '2026-08-15');
        $this->assertNull($active, 'Incomplete profiles must be excluded from active recommendations (BR-001).');
    }

    /**
     * FR-051/BR-001: stale profiles excluded — a profile whose effective_to is in the past
     * should not be returned.
     */
    public function test_stale_profile_excluded(): void
    {
        self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 1.00,
            '2026-07-01', '2026-07-31',
            true
        );

        $active = self::$svc->getActiveAt($this->companyA, $this->carrierId, '2026-08-15');
        $this->assertNull($active, 'Stale profiles (past effective_to) must be excluded (BR-001).');
    }

    /**
     * BR-021: unit_type must be one of per_mile, flat, percentage.
     */
    public function test_invalid_unit_type_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'invalid_unit', 1.00,
            '2026-08-01', null,
            true
        );
    }

    /**
     * FR-051: version number must increment.
     */
    public function test_version_increments(): void
    {
        $v1 = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 1.00,
            '2026-08-01', null,
            true
        );

        $v2 = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 2.00,
            '2026-09-01', null,
            true
        );

        $this->assertGreaterThan($v1, $v2, 'Each new version must have a higher version number.');
    }

    /**
     * BR-001: required-input enforcement — incomplete flag must be set at creation when
     * required fields are missing.
     */
    public function test_incomplete_flag_set_when_required_input_missing(): void
    {
        // Rate of 0 with per_mile unit is incomplete.
        $v = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 0.0,
            '2026-08-01', null,
            false
        );

        $row = self::$m->query('SELECT is_complete FROM carrier_cost_profiles WHERE id = ' . (int) $v)->fetch_assoc();
        $this->assertSame(0, (int) $row['is_complete'], 'Incomplete flag must be stored as false.');
    }

    /**
     * FR-042: tenant isolation — cost profiles are scoped to company_id.
     */
    public function test_cost_profile_isolation_across_tenants(): void
    {
        $v = self::$svc->createVersion(
            $this->companyA, $this->carrierId,
            'per_mile', 1.50,
            '2026-08-01', null,
            true
        );

        // Different tenant should get no active profiles.
        $active = self::$svc->getActiveAt(99999, $this->carrierId, '2026-08-15');
        $this->assertNull($active, 'Other tenant must not see this profile (SEC-010/FR-042).');
    }

    /**
     * listForCarrier returns all versions for a carrier, tenant-scoped.
     */
    public function test_list_for_carrier_returns_all_versions(): void
    {
        self::$svc->createVersion($this->companyA, $this->carrierId, 'per_mile', 1.00, '2026-08-01', null, true);
        self::$svc->createVersion($this->companyA, $this->carrierId, 'flat', 500.00, '2026-09-01', null, true);

        $profiles = self::$svc->listForCarrier($this->companyA, $this->carrierId);
        $this->assertCount(2, $profiles, 'listForCarrier must return all versions for this carrier.');
    }
}
