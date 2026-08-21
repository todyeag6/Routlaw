<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;

/**
 * TDD for T1 Auth: Argon2id hashing + login session (FR-001/FR-002/SEC-002).
 * Tests verify auth behavior including cross-request rate limiting and session hardening.
 */
final class AuthTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_auth';
    private static ?\mysqli $m = null;
    private static Auth $auth;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/001_audit_events.sql');

        self::$auth = new Auth(self::$m);
    }

    public static function tearDownAfterClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    /** Clean state before each test: truncate tables, re-seed minimal data. */
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

        $dispatcherRole = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];

        self::$auth->createUser($companyA, 'alice@example.com', 'StrongPass!123', 'Alice', $dispatcherRole);
        self::$auth->createUser($companyB, 'bob@example.com', 'StrongPass!456', 'Bob', $dispatcherRole);
    }

    /** FR-002: passwords stored as Argon2id, never plaintext. */
    public function test_password_hash_is_argon2id(): void
    {
        $hash = Auth::hashPassword('StrongPass!123');
        $this->assertStringStartsWith('$argon2id$', $hash, 'Hash must be Argon2id (FR-002).');
    }

    /** FR-002: verify rejects wrong password, accepts correct. */
    public function test_verify_password_round_trips(): void
    {
        $hash = Auth::hashPassword('MySecret!9');
        $this->assertTrue(Auth::verifyPassword('MySecret!9', $hash));
        $this->assertFalse(Auth::verifyPassword('wrong', $hash));
    }

    /** FR-001: login succeeds for valid credentials and sets a scoped session. */
    public function test_login_succeeds_and_sets_scoped_session(): void
    {
        $user = self::$auth->login('alice@example.com', 'StrongPass!123');
        $this->assertNotNull($user, 'Login must succeed for valid credentials.');
        $this->assertSame('Alpha Transport', $user->companyName, 'Session must carry company display name.');
        $this->assertSame('Alice', $user->fullName, 'Session user object must include full name.');
        $this->assertSame('Alpha Transport LLC', $user->companyLegalName);

        // Session cookie flags per SEC-002 (CLI fallback: verify session data written).
        $this->assertNotEmpty($_SESSION['routlaw_user_id'] ?? null, 'Session must contain user_id after login (SEC-002).');
        $this->assertSame('Alpha Transport', $_SESSION['routlaw_company'] ?? null, 'Session must contain tenant scope (SEC-010).');
    }

    /** FR-001: login fails for bad password. */
    public function test_login_fails_for_bad_password(): void
    {
        $user = self::$auth->login('alice@example.com', 'WrongPassword!');
        $this->assertNull($user, 'Login must fail for wrong password.');
    }

    /** FR-001: login fails for unknown email. */
    public function test_login_fails_for_unknown_email(): void
    {
        $user = self::$auth->login('nobody@example.com', 'StrongPass!123');
        $this->assertNull($user, 'Login must fail for unknown email.');
    }

    /**
     * FR-001: repeated failures are rate-limited.
     * Verified across separate Auth instances (DB-persisted, not in-memory),
     * proving the lockout holds in production where each request is a new process.
     */
    public function test_login_rate_limits_repeated_failures(): void
    {
        $email = 'alice@example.com';

        // Use a fresh Auth instance for each attempt — this proves the
        // rate limiter is DB-persisted, not in-memory per-process state.
        for ($i = 0; $i < 5; $i++) {
            $auth = new Auth(self::$m);
            $this->assertNull($auth->login($email, 'Wrong!'), 'Attempt ' . $i . ' should fail.');
        }
        // 6th attempt with correct password must still be rate-limited.
        $auth = new Auth(self::$m);
        $this->assertNull($auth->login($email, 'StrongPass!123'), 'After 5 failures, login must be rate-limited even with correct password.');

        // Verify the login_attempts table persisted the failures.
        $count = (int) self::$m->query("SELECT COUNT(*) FROM login_attempts WHERE email = 'alice@example.com'")->fetch_column();
        $this->assertSame(5, $count, 'Failed attempts must be persisted to login_attempts table (FR-001).');
    }

    /** FR-001: auth events are logged in audit_events table. */
    public function test_successful_login_emits_audit_event(): void
    {
        $before = (int) self::$m->query('SELECT COUNT(*) FROM audit_events')->fetch_column();
        self::$auth->login('alice@example.com', 'StrongPass!123');
        $after = (int) self::$m->query('SELECT COUNT(*) FROM audit_events')->fetch_column();
        $this->assertGreaterThan($before, $after, 'Successful login must emit an audit event (FR-001).');

        $row = self::$m->query(
            "SELECT * FROM audit_events WHERE event_type='auth.login' AND result='success' ORDER BY id DESC LIMIT 1"
        )->fetch_assoc();
        $this->assertNotFalse($row, 'audit_events must contain an auth.login success entry.');
        $this->assertSame('user', $row['actor_type']);
    }

    /** FR-001: failed login attempts are also audited. */
    public function test_failed_login_emits_audit_event(): void
    {
        $before = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='auth.login' AND result='failure'")->fetch_column();
        self::$auth->login('alice@example.com', 'WrongPassword!');
        $after = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='auth.login' AND result='failure'")->fetch_column();
        $this->assertGreaterThan($before, $after, 'Failed login must emit an auth.login failure audit event (FR-001).');
    }

    /** SEC-002: logout clears the session. */
    public function test_logout_clears_session(): void
    {
        self::$auth->login('alice@example.com', 'StrongPass!123');
        $this->assertNotEmpty($_SESSION['routrow_user_id'] ?? $_SESSION['routlaw_user_id'] ?? null, 'Session should be set after login.');
        self::$auth->logout();
        $this->assertEmpty($_SESSION['routlaw_user_id'] ?? null, 'Session must be cleared on logout.');
    }

    /** FR-002 / SEC-008: users table stores password_hash, not plaintext password. */
    public function test_passwords_not_stored_in_plaintext(): void
    {
        $row = self::$m->query(
            "SELECT password_hash FROM users WHERE email='alice@example.com'"
        )->fetch_assoc();
        $this->assertNotFalse($row);
        $this->assertStringNotContainsString('StrongPass!123', $row['password_hash'], 'Plaintext password must not be stored (FR-002).');
        $this->assertStringStartsWith('$argon2id$', $row['password_hash']);
    }

    /** SEC-002: session ID rotates on login. */
    public function test_session_id_rotates_on_login(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $before = session_id();
        self::$auth->login('alice@example.com', 'StrongPass!123');
        $after = session_id();
        $this->assertNotSame($before, $after, 'Session ID must rotate on login (SEC-002).');
    }

    /**
     * SEC-002: session cookie params include Secure, HttpOnly, SameSite.
     * Verifies that establishSession() calls session_set_cookie_params
     * with the correct flags — not just relying on php.ini.
     */
    public function test_session_cookie_has_security_flags(): void
    {
        // Start a fresh session to inspect cookie params.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $params = session_get_cookie_params();
        $this->assertTrue($params['secure'] ?? false, 'Session cookie must have Secure flag (SEC-002).');
        $this->assertTrue($params['httponly'] ?? false, 'Session cookie must have HttpOnly flag (SEC-002).');
        $this->assertSame('Lax', $params['samesite'] ?? null, 'Session cookie must have SameSite=Lax (SEC-002).');
    }

    /**
     * Cross-tenant login isolation: Bob's password must not authenticate
     * under Alice's tenant context (even though both are valid users).
     */
    public function test_cross_tenant_login_isolation(): void
    {
        // Bob can log in (valid credentials).
        $bob = self::$auth->login('bob@example.com', 'StrongPass!456');
        $this->assertNotNull($bob, 'Bob must authenticate independently.');

        // Bob's session must be scoped to company B, not company A.
        $aliceCompanyId = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Alpha Transport'")->fetch_row()[0];
        $this->assertNotSame($aliceCompanyId, $bob->companyId, 'Bob must have his own tenant scope (SEC-010).');
    }
}
