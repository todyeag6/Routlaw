<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;

/**
 * T15.1 — Migration 011_approval_queue.sql.
 * Payload-bound approval requests (FR-022/023/024): carry actor, action, resource,
 * payload_hash, expiry. Tenant-scoped from day one (FR-042).
 */
final class ApprovalQueueSchemaTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_approval';

    private static ?\mysqli $m = null;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql', '010_message_drafts.sql', '011_approval_queue.sql'] as $f) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $f);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            Connection::connect()->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    public function test_table_exists(): void
    {
        $r = self::$m->query("SHOW TABLES LIKE 'approval_requests'");
        $this->assertSame(1, $r->num_rows, 'approval_requests table missing');
    }

    public function test_tenant_scoped(): void
    {
        $r = self::$m->query("SHOW COLUMNS FROM approval_requests LIKE 'company_id'");
        $this->assertSame(1, $r->num_rows, 'company_id missing (FR-042)');
        $ddl = (string) self::$m->query("SHOW CREATE TABLE approval_requests")->fetch_row()[1];
        $this->assertStringContainsString('FOREIGN KEY (`company_id`) REFERENCES `companies`', $ddl);
    }

    public function test_payload_bound_columns(): void
    {
        // FR-022/023/024: actor + action + resource + payload-hash + expiry.
        foreach (['actor_user_id', 'action', 'resource_type', 'resource_id', 'payload_hash', 'expires_at'] as $c) {
            $r = self::$m->query("SHOW COLUMNS FROM approval_requests LIKE '{$c}'");
            $this->assertSame(1, $r->num_rows, "required column missing: {$c}");
        }
    }

    public function test_status_enum_values(): void
    {
        $r = self::$m->query("SHOW COLUMNS FROM approval_requests LIKE 'status'");
        $row = $r->fetch_row();
        $this->assertNotFalse($row);
        $type = strtolower((string) $row[1]);
        foreach (['pending', 'approved', 'rejected', 'expired', 'consumed'] as $v) {
            $this->assertStringContainsString("'{$v}'", $type, "status enum missing {$v}");
        }
    }

    public function test_consumed_at_for_replay_safety(): void
    {
        // Single-use: consumed_at set on apply to defeat replay (FR-024).
        $r = self::$m->query("SHOW COLUMNS FROM approval_requests LIKE 'consumed_at'");
        $this->assertSame(1, $r->num_rows, 'consumed_at missing (replay-safety)');
    }

    public function test_payload_hash_is_sha256(): void
    {
        $r = self::$m->query("SHOW COLUMNS FROM approval_requests LIKE 'payload_hash'");
        $row = $r->fetch_row();
        $this->assertNotFalse($row);
        // CHAR(64) for hex sha256.
        $this->assertStringContainsString('char', strtolower((string) $row[1]));
        $this->assertStringContainsString('64', (string) $row[1]);
    }
}
