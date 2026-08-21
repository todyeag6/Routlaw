<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;

/**
 * TDD for the foundation + audit migrations (build-plan §3 T1.3 / §4 Phase 1 T3).
 * Applies migrations/000_init_foundation.sql + 001_audit_events.sql to a
 * throwaway MariaDB test DB and verifies tenant-scoped foundation (FR-042, FRD §12.2)
 * plus seeded roles + audit trace tables.
 */
final class FoundationSchemaTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_foundation';
    private static ?\mysqli $m = null;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/001_audit_events.sql');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            $base = Connection::connect();
            $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    public function test_core_tables_exist(): void
    {
        foreach (['companies','roles','permissions','role_permissions','users','user_role_assignments','async_jobs'] as $t) {
            $r = self::$m->query("SHOW TABLES LIKE '{$t}'");
            $this->assertNotFalse($r);
            $this->assertSame(1, $r->num_rows, "Table missing: {$t}");
        }
    }

    public function test_users_table_is_tenant_scoped(): void
    {
        $r = self::$m->query("SHOW COLUMNS FROM users LIKE 'company_id'");
        $this->assertSame(1, $r->num_rows, 'users.company_id (tenant scope) missing.');
        // Unique natural key must include company_id (no cross-tenant email collision).
        $r = self::$m->query("SHOW INDEX FROM users WHERE Key_name = 'uq_users_company_email'");
        $this->assertGreaterThanOrEqual(1, $r->num_rows, 'Tenant-aware unique key missing on users.');
    }

    public function test_roles_are_seeded(): void
    {
        $count = (int) self::$m->query("SELECT COUNT(*) FROM roles")->fetch_column();
        $this->assertGreaterThanOrEqual(5, $count, 'Expected at least 5 seeded roles.');
        $carrier = self::$m->query("SELECT COUNT(*) FROM roles WHERE slug='carrier'")->fetch_column();
        $this->assertSame(1, (int) $carrier, 'Carrier User role (MVP self-service login) must be seeded.');
    }

    public function test_async_jobs_queue_table_present(): void
    {
        $r = self::$m->query("SHOW COLUMNS FROM async_jobs LIKE 'correlation_id'");
        $this->assertSame(1, $r->num_rows, 'async_jobs.correlation_id (NFR-006) missing.');
    }

    public function test_audit_tables_exist(): void
    {
        foreach (['audit_events','agent_runs','tool_calls'] as $t) {
            $r = self::$m->query("SHOW TABLES LIKE '{$t}'");
            $this->assertNotFalse($r);
            $this->assertSame(1, $r->num_rows, "Audit table missing: {$t}");
        }
    }

    public function test_audit_events_has_company_scope_and_actor(): void
    {
        foreach (['company_id','actor_type','event_type','action','target_type','target_id','result','correlation_id','created_at'] as $col) {
            $r = self::$m->query("SHOW COLUMNS FROM audit_events LIKE '{$col}'");
            $this->assertSame(1, $r->num_rows, "audit_events.{$col} missing (FR-029).");
        }
    }

    public function test_audit_events_actor_type_enum(): void
    {
        $r = self::$m->query("SHOW COLUMNS FROM audit_events LIKE 'actor_type'");
        $row = $r->fetch_row();
        $this->assertNotFalse($row);
        $this->assertStringContainsString('user', $row[1], 'actor_type enum must include user');
        $this->assertStringContainsString('agent', $row[1], 'actor_type enum must include agent');
        $this->assertStringContainsString('system', $row[1], 'actor_type enum must include system');
        $this->assertStringContainsString('anonymous', $row[1], 'actor_type enum must include anonymous');
    }
}