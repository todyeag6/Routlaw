<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Carriers\CarrierService;
use Routlaw\Equipment\EquipmentProfileService;

/**
 * TDD for T5 Equipment profiles (build-plan §4 Phase 2 T5, FR-006/FR-007/BR-001/002).
 *
 * Multiple equipment profiles per carrier. Numeric-range validation (length/width/height/weight).
 * Hard-match constraints (e.g. hazmat class must match equipment spec). Incomplete -> never approved.
 * Tests: range validation, hard constraint enforcement, incomplete profile cannot be approved.
 */
final class EquipmentProfileTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_equipment';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static CarrierService $carrierSvc;
    private static EquipmentProfileService $svc;

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

        self::$auth = new Auth(self::$m);
        self::$carrierSvc = new CarrierService(self::$m);
        self::$svc = new EquipmentProfileService(self::$m);
    }

    public static function tearDownAfterClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    protected function setUp(): void
    {
        self::$m->query('SET FOREIGN_KEY_CHECKS = 0');
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

        // Create a carrier for equipment profiles (start at active).
        $this->carrierId = self::$carrierSvc->signup($companyA, 'Alpha Haul', '', 'DOT-A1', 'MC-A1', 'EIN-A1');
        self::$carrierSvc->transitionState($this->carrierId, 'under_review', $carrierRole, $companyA);
        self::$carrierSvc->transitionState($this->carrierId, 'active', $dispatcher, $companyA);
    }

    private int $carrierId;
    private int $companyA;
    private int $dispatcher;
    private int $carrierRole;

    /** FR-006/BR-002: multiple equipment profiles per carrier. */
    public function test_multiple_profiles_per_carrier(): void
    {
        $id1 = $this->createValidProfile(['truck_type' => 'Flatbed']);
        $id2 = $this->createValidProfile(['truck_type' => 'Gooseneck']);

        $this->assertNotSame($id1, $id2, 'Carrier must support multiple equipment profiles (FR-006).');
    }

    /** FR-007: numeric range validation rejects negative weight. */
    public function test_negative_payload_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createValidProfile(['payload_capacity_lbs' => -100]);
    }

    /** FR-007: numeric range validation rejects zero payload. */
    public function test_zero_payload_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createValidProfile(['payload_capacity_lbs' => 0]);
    }

    /** FR-007: numeric range validation rejects negative GVWR. */
    public function test_negative_gvwr_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createValidProfile(['truck_gvwr_lbs' => -50000]);
    }

    /** FR-007: numeric range validation rejects zero deck dimensions. */
    public function test_zero_deck_length_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createValidProfile(['deck_length_ft' => 0]);
    }

    /** FR-007: numeric range validation rejects negative deck width. */
    public function test_negative_deck_width_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createValidProfile(['deck_width_ft' => -10]);
    }

    /**
     * FR-007: hard-match constraint — hazmat class must match equipment spec.
     * If capabilities declare 'hazmat_class' but the carrier's profile doesn't support it,
     * the profile is flagged as inconsistent and rejected.
     */
    public function test_hazmat_mismatch_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/hazmat/i');

        // Profile declares hazmat capability but doesn't have the hazmat endorsement field.
        $this->createValidProfile([
            'truck_type'    => 'Flatbed',
            'capabilities'  => ['hazmat_class' => 'Class 3'],
            'is_complete'   => true,
        ]);
    }

    /**
     * FR-007: hard-match constraint — GCWR must be >= truck GVWR + trailer GVWR.
     */
    public function test_gcwr_below_combined_gvwr_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createValidProfile([
            'truck_gvwr_lbs'   => 20000,
            'trailer_gvwr_lbs' => 20000,
            'gcwr_lbs'         => 30000, // 30000 < 20000+20000=40000
        ]);
    }

    /**
     * FR-006/BR-002: incomplete profiles cannot be approved.
     */
    public function test_incomplete_profile_cannot_be_approved(): void
    {
        // Create an incomplete profile (missing deck dimensions).
        $id = $this->createValidProfile([
            'deck_length_ft' => null,
            'deck_width_ft'  => null,
            'is_complete'    => false,
        ]);
        $this->assertGreaterThan(0, $id);

        $ok = self::$svc->approveProfile($id, $this->companyA);
        $this->assertFalse($ok, 'Incomplete profiles must not be approvable (FR-006/BR-002).');

        $row = self::$m->query('SELECT status FROM equipment_profiles WHERE id = ' . (int) $id)->fetch_assoc();
        $this->assertNotFalse($row);
        $this->assertSame('draft', $row['status'], 'Incomplete profile must remain in draft (FR-006/BR-002).');
    }

    /**
     * FR-006/BR-002: complete profile can be approved.
     */
    public function test_complete_profile_can_be_approved(): void
    {
        $id = $this->createValidProfile([
            'is_complete' => true,
        ]);

        $ok = self::$svc->approveProfile($id, $this->companyA);
        $this->assertTrue($ok, 'Complete profiles must be approvable (FR-006/BR-002).');

        $row = self::$m->query('SELECT status FROM equipment_profiles WHERE id = ' . (int) $id)->fetch_assoc();
        $this->assertNotFalse($row);
        $this->assertSame('approved', $row['status']);
    }

    /**
     * FR-006/BR-002: profile creation is audited.
     */
    public function test_profile_creation_audited(): void
    {
        $before = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='equipment.create'")->fetch_column();
        $this->createValidProfile(['truck_type' => 'Flatbed Audit']);
        $after = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='equipment.create'")->fetch_column();
        $this->assertSame($before + 1, $after, 'Equipment profile creation must be audited (FR-029).');
    }

    /**
     * FR-006/BR-002: equipment profiles are tenant-scoped.
     */
    public function test_profile_isolation_across_tenants(): void
    {
        $id = $this->createValidProfile(['truck_type' => 'Alpha Only']);

        // Query for a different tenant — must return zero rows.
        $profiles = self::$svc->listForTenant(99999, $this->carrierId);
        $this->assertNotContains((int) $id, array_column($profiles, 'id'), 'Other tenant must not see this profile (SEC-010/FR-042).');
    }

    /**
     * Helper: create a valid equipment profile with optional overrides.
     * @param array{
     *   truck_type?: string,
     *   truck_gvwr_lbs?: int|null,
     *   trailer_type?: string|null,
     *   trailer_gvwr_lbs?: int|null,
     *   gcwr_lbs?: int|null,
     *   payload_capacity_lbs?: int|null,
     *   deck_length_ft?: float|null,
     *   deck_width_ft?: float|null,
     *   capabilities?: array|null,
     *   is_complete?: bool
     * } $overrides
     */
    private function createValidProfile(array $overrides = []): int
    {
        $data = array_merge([
            'truck_type'           => 'Flatbed',
            'trailer_type'         => 'Flatbed Trailer',
            'truck_gvwr_lbs'       => 26000,
            'trailer_gvwr_lbs'     => 10000,
            'gcwr_lbs'             => 40000,
            'payload_capacity_lbs' => 16000,
            'deck_length_ft'       => 20.0,
            'deck_width_ft'        => 8.5,
            'capabilities'         => [],
            'is_complete'           => true,
        ], $overrides);

        return self::$svc->createProfile(
            $this->companyA,
            $this->carrierId,
            $data['truck_type'],
            $data['trailer_type'] ?? '',
            $data['truck_gvwr_lbs'] ?? null,
            $data['trailer_gvwr_lbs'] ?? null,
            $data['gcwr_lbs'] ?? null,
            $data['payload_capacity_lbs'] ?? null,
            $data['deck_length_ft'] ?? null,
            $data['deck_width_ft'] ?? null,
            $data['capabilities'] ?? null,
            $data['is_complete']
        );
    }
}
