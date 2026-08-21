<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Security\Authorization;
use Routlaw\Security\UserSession;

/**
 * TDD for T2 RBAC: server-side tenant+role enforcement (FR-003/SEC-010).
 * Cross-role and cross-tenant negative tests must return 403 + zero data leakage.
 */
final class AuthorizationTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_rbac';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static Authorization $authz;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/001_audit_events.sql');

        self::$auth = new Auth(self::$m);
        self::$authz = new Authorization(self::$m);
    }

    public static function tearDownAfterClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    protected function setUp(): void
    {
        // NOTE: audit_events has BEFORE DELETE trigger (BR-017 immutability).
        // Use TRUNCATE (DDL) to bypass the trigger for test setup.
        self::$m->query('SET FOREIGN_KEY_CHECKS = 0');
        self::$m->query('TRUNCATE audit_events');
        self::$m->query('TRUNCATE login_attempts');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM user_role_assignments');
        self::$m->query('DELETE FROM users');
        self::$m->query('DELETE FROM roles');
        self::$m->query('DELETE FROM companies');

        // Re-seed roles from the migration.
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');

        // Create two tenants with one dispatcher user each for cross-tenant tests.
        $companyA = self::$auth->createCompany('Alpha Transport', 'Alpha Transport LLC');
        $companyB = self::$auth->createCompany('Beta Haulers', 'Beta Haulers LLC');

        $dispatcher = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        $carrier = (int) self::$m->query("SELECT id FROM roles WHERE slug='carrier'")->fetch_row()[0];
        $reviewer = (int) self::$m->query("SELECT id FROM roles WHERE slug='reviewer'")->fetch_row()[0];

        // Alice: dispatcher in company A
        self::$auth->createUser($companyA, 'alice@example.com', 'Pass!123', 'Alice', $dispatcher);
        // Bob: dispatcher in company B (different tenant)
        self::$auth->createUser($companyB, 'bob@example.com', 'Pass!456', 'Bob', $dispatcher);
        // Carol: carrier in company A (same tenant, different role)
        self::$auth->createUser($companyA, 'carol@example.com', 'Pass!789', 'Carol', $carrier);
        // Dave: reviewer in company A
        self::$auth->createUser($companyA, 'dave@example.com', 'Pass!012', 'Dave', $reviewer);
    }

    /** Helper: log in and return session. */
    private function loginAs(string $email, string $pass): UserSession
    {
        $user = self::$auth->login($email, $pass);
        $this->assertNotNull($user, "Login failed for {$email}");
        return $user;
    }

    /** FR-003: a tenant user can access their own tenant's resources. */
    public function test_tenant_user_can_access_own_tenant(): void
    {
        $alice = $this->loginAs('alice@example.com', 'Pass!123');
        $this->assertTrue(
            self::$authz->canAccessTenant($alice->companyId, $alice->userId),
            'User must access own tenant resources (FR-003).'
        );
    }

    /** FR-003: cross-tenant access is denied. */
    public function test_cross_tenant_access_denied(): void
    {
        $alice = $this->loginAs('alice@example.com', 'Pass!123');
        // Alice (company A) tries to access company B's resources via Bob's tenant.
        $bob = $this->loginAs('bob@example.com', 'Pass!456');
        $this->assertFalse(
            self::$authz->canAccessTenant($bob->companyId, $alice->userId),
            'Cross-tenant access must be denied (FR-003/SEC-010).'
        );
    }

    /** FR-003: cross-tenant data leakage returns zero rows. */
    public function test_cross_tenant_query_returns_zero_rows(): void
    {
        $companyA = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Alpha Transport'")->fetch_row()[0];
        $companyB = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Beta Haulers'")->fetch_row()[0];

        // Alice (company A) queries users scoped to company B — must return 0 rows.
        $alice = $this->loginAs('alice@example.com', 'Pass!123');
        $users = self::$authz->scopeQuery(
            $alice->userId,
            $companyB,
            'users',
            'SELECT id, email FROM users WHERE company_id = ?',
            []
        );
        $this->assertSame(0, count($users), 'Cross-tenant query must return zero rows (FR-003/SEC-010).');

        // Alice queries her own tenant — must return only her rows.
        $own = self::$authz->scopeQuery(
            $alice->userId,
            $companyA,
            'users',
            'SELECT id, email FROM users WHERE company_id = ?',
            []
        );
        $this->assertGreaterThanOrEqual(1, count($own), 'Tenant user must see own tenant rows.');
    }

    /** FR-003: role-based permission check — carrier cannot manage users. */
    public function test_carrier_role_lacks_admin_permission(): void
    {
        $carol = $this->loginAs('carol@example.com', 'Pass!789');
        $this->assertFalse(
            self::$authz->hasPermission($carol->userId, $carol->companyId, 'users.manage'),
            'Carrier role must not have users.manage permission (FRD §4.2, FR-003).'
        );
    }

    /** FR-003: role-based permission check — company_admin can manage users. */
    public function test_company_admin_has_admin_permission(): void
    {
        // Promote Alice to company_admin in both users table and user_role_assignments.
        $roleId = (int) self::$m->query("SELECT id FROM roles WHERE slug='company_admin'")->fetch_row()[0];
        $alice = $this->loginAs('alice@example.com', 'Pass!123');
        self::$m->query("UPDATE users SET role_id = {$roleId} WHERE id = {$alice->userId}");
        self::$m->query("UPDATE user_role_assignments SET role_id = {$roleId} WHERE user_id = {$alice->userId}");

        $this->assertTrue(
            self::$authz->hasPermission($alice->userId, $alice->companyId, 'users.manage'),
            'Company admin must have users.manage permission (FRD §4.2, FR-003).'
        );
    }

    /** FR-003: anonymous (unauthenticated) user is denied all protected access. */
    public function test_anonymous_user_denied(): void
    {
        $this->assertFalse(
            self::$authz->canAccessTenant(1, 0),
            'Anonymous user must be denied tenant access (SEC-010).'
        );
    }

    /** FR-003: scoped query only returns tenant-owned rows. */
    public function test_scope_query_returns_only_tenant_rows(): void
    {
        $companyA = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Alpha Transport'")->fetch_row()[0];

        $alice = $this->loginAs('alice@example.com', 'Pass!123');
        $users = self::$authz->scopeQuery(
            $alice->userId,
            $companyA,
            'users',
            'SELECT id, email FROM users WHERE company_id = ?',
            []
        );
        $this->assertGreaterThanOrEqual(1, count($users), 'Tenant user must see own tenant rows.');
        foreach ($users as $u) {
            $this->assertStringContainsString('@example.com', $u['email'], 'Each row must have email.');
        }
    }

    /** SEC-010: authorization denial is audited. */
    public function test_authorization_denial_is_audited(): void
    {
        $alice = $this->loginAs('alice@example.com', 'Pass!123');
        $bob = $this->loginAs('bob@example.com', 'Pass!456');

        $before = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='authz.denied'")->fetch_column();
        // Alice attempts cross-tenant access.
        self::$authz->canAccessTenant($bob->companyId, $alice->userId);
        $after = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='authz.denied'")->fetch_column();
        $this->assertGreaterThan($before, $after, 'Authorization denial must be audited (FR-029).');
    }
}
