<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Security\UserSession;
use Routlaw\Carriers\CarrierService;

/**
 * TDD for T4 Carrier signup + lifecycle (build-plan §4 Phase 2 T4, FR-004/FR-005/BR-001).
 *
 * Carrier self-registers: email-verify -> active.
 * Lifecycle states: new -> needs_documents -> under_review -> active -> suspended -> inactive -> rejected -> archived.
 * Tests: cross-tenant signup isolation, duplicate detection (dot/mc/ein), state transition guard.
 */
final class CarrierSignupTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_carriers';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static CarrierService $svc;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/001_audit_events.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/002_carriers.sql');

        self::$auth = new Auth(self::$m);
        self::$svc = new CarrierService(self::$m);
    }

    public static function tearDownAfterClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    protected function setUp(): void
    {
        // Truncate carrier tables for clean test state.
        self::$m->query('SET FOREIGN_KEY_CHECKS = 0');
        self::$m->query('TRUNCATE carrier_status_history');
        self::$m->query('TRUNCATE carriers');
        self::$m->query('TRUNCATE audit_events');
        self::$m->query('TRUNCATE login_attempts');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM users');
        self::$m->query('DELETE FROM user_role_assignments');
        self::$m->query('DELETE FROM companies');

        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');

        // Two tenants for cross-tenant isolation tests.
        $companyA = self::$auth->createCompany('Alpha Transport', 'Alpha Transport LLC');
        $companyB = self::$auth->createCompany('Beta Haulers', 'Beta Haulers LLC');

        $dispatcher = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        $carrierRole = (int) self::$m->query("SELECT id FROM roles WHERE slug='carrier'")->fetch_row()[0];
        self::$auth->createUser($companyA, 'alice@example.com', 'Pass!123', 'Alice', $dispatcher);
        self::$auth->createUser($companyA, 'carol@example.com', 'Pass!789', 'Carol', $carrierRole);
        self::$auth->createUser($companyB, 'bob@example.com', 'Pass!456', 'Bob', $dispatcher);
        $this->companyA = $companyA;
        $this->companyB = $companyB;
        $this->dispatcherRole = $dispatcher;
        $this->carrierRole = $carrierRole;
    }

    private int $companyB;
    private int $companyA;
    /** @var int */
    private int $dispatcherRole;
    /** @var int */
    private int $carrierRole;

    /** FR-004/BR-001: carrier self-registers with validated fields, starts at 'new' status. */
    public function test_carrier_signup_creates_new_status_carrier(): void
    {
        $id = self::$svc->signup(
            $this->companyA,
            'Roadway Express',
            'Roadway Express Inc',
            'DOT-12345',
            'MC-98765',
            'EIN-11-2233445'
        );

        $this->assertGreaterThan(0, $id, 'Carrier signup must return a valid ID (FR-004).');

        $row = self::$m->query('SELECT * FROM carriers WHERE id = ' . (int) $id)->fetch_assoc();
        $this->assertNotFalse($row, 'Carrier must be persisted (FR-004).');
        $this->assertSame('new', $row['status'], 'New carrier must start in "new" state (FR-005).');
        $this->assertSame((string) $this->companyA, $row['company_id'], 'Carrier must be tenant-scoped (FR-042).');
        $this->assertSame('Roadway Express', $row['legal_name']);
        $this->assertSame('DOT-12345', $row['dot_number']);
    }

    /** FR-004/BR-001: signup emits an audit event. */
    public function test_signup_emits_audit_event(): void
    {
        $before = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='carrier.signup'")->fetch_column();
        self::$svc->signup($this->companyA, 'Alpha Haul', '', 'DOT-A1', 'MC-A1', 'EIN-A1');
        $after = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='carrier.signup'")->fetch_column();
        $this->assertSame($before + 1, $after, 'Carrier signup must be audited (FR-029).');
    }

    /** FR-004/BR-001: duplicate DOT number within same tenant is rejected. */
    public function test_duplicate_dot_within_tenant_rejected(): void
    {
        self::$svc->signup($this->companyA, 'Alpha Haul One', '', 'DOT-DUP', 'MC-1', 'EIN-1');
        $this->expectException(\RuntimeException::class);
        self::$svc->signup($this->companyA, 'Alpha Haul Two', '', 'DOT-DUP', 'MC-2', 'EIN-2');
    }

    /** FR-004/BR-001: duplicate DOT number in DIFFERENT tenant is allowed (tenant isolation). */
    public function test_duplicate_dot_in_different_tenant_allowed(): void
    {
        self::$svc->signup($this->companyA, 'Alpha Haul', '', 'DOT-SHARING', 'MC-1', 'EIN-1');
        $id = self::$svc->signup($this->companyB, 'Beta Haul', '', 'DOT-SHARING', 'MC-2', 'EIN-2');
        $this->assertGreaterThan(0, $id, 'Same DOT in different tenant must be allowed (tenant isolation FR-042).');
    }

    /** FR-005: state transition new -> under_review is valid. */
    public function test_state_transition_new_to_under_review(): void
    {
        $id = self::$svc->signup($this->companyA, 'Alpha Haul', '', 'DOT-TR1', 'MC-1', 'EIN-1');
        $ok = self::$svc->transitionState($id, 'under_review', $this->carrierRole);
        $this->assertTrue($ok, 'new -> under_review must be allowed (FR-005).');

        $row = self::$m->query('SELECT status FROM carriers WHERE id = ' . (int) $id)->fetch_assoc();
        $this->assertSame('under_review', $row['status']);
    }

    /** FR-005: state transition under_review -> active is valid. */
    public function test_state_transition_under_review_to_active(): void
    {
        $id = self::$svc->signup($this->companyA, 'Alpha Haul', '', 'DOT-TR2', 'MC-1', 'EIN-1');
        self::$svc->transitionState($id, 'under_review', $this->carrierRole);
        $ok = self::$svc->transitionState($id, 'active', $this->carrierRole);
        $this->assertTrue($ok, 'under_review -> active must be allowed (FR-005).');
    }

    /** FR-005: invalid state transition rejected (active -> new is not permitted). */
    public function test_invalid_state_transition_rejected(): void
    {
        $id = self::$svc->signup($this->companyA, 'Alpha Haul', '', 'DOT-TR3', 'MC-1', 'EIN-1');
        self::$svc->transitionState($id, 'under_review', $this->carrierRole);
        self::$svc->transitionState($id, 'active', $this->carrierRole);
        $ok = self::$svc->transitionState($id, 'new', $this->carrierRole);
        $this->assertFalse($ok, 'active -> new is not a permitted transition (FR-005).');
    }

    /** FR-005: state transitions logged to carrier_status_history. */
    public function test_state_transition_logged_to_history(): void
    {
        $id = self::$svc->signup($this->companyA, 'Alpha Haul', '', 'DOT-TR4', 'MC-1', 'EIN-1');
        self::$svc->transitionState($id, 'under_review', $this->carrierRole);

        $row = self::$m->query(
            'SELECT * FROM carrier_status_history WHERE carrier_id = ' . (int) $id . ' ORDER BY id DESC LIMIT 1'
        )->fetch_assoc();
        $this->assertNotFalse($row, 'Transition must be logged (FR-005).');
        $this->assertSame('new', $row['from_status']);
        $this->assertSame('under_review', $row['to_status']);
    }

    /** FR-005/SEC-010: carrier can only be seen in its own tenant. */
    public function test_carrier_isolation_across_tenants(): void
    {
        $id = self::$svc->signup($this->companyA, 'Alpha Haul', '', 'DOT-ISO', 'MC-1', 'EIN-1');
        $carriers = self::$svc->listForTenant($this->companyB);
        $this->assertNotContains((int) $id, $carriers, 'Other tenant must not see this carrier (SEC-010/FR-042).');
    }

    private function loginAs(string $email, string $pass): UserSession
    {
        $user = self::$auth->login($email, $pass);
        $this->assertNotNull($user, "Login failed for {$email}");
        return $user;
    }
}
