<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;

/**
 * T14.1 — Migration 010_message_drafts.sql.
 * Applies to a throwaway DB and verifies tenant scope + status/source enums.
 * TDD: this test FAILS until the migration exists and applies cleanly.
 */
final class MessageDraftSchemaTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_msgdraft';

    private static ?\mysqli $m = null;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        // Foundation gives companies + roles + users (source_link FK targets).
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/001_audit_events.sql');
        // 010 is the subject under test — applied after the base.
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/010_message_drafts.sql');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            $base = Connection::connect();
            $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    public function test_table_exists(): void
    {
        $r = self::$m->query("SHOW TABLES LIKE 'message_drafts'");
        $this->assertNotFalse($r);
        $this->assertSame(1, $r->num_rows, 'message_drafts table missing');
    }

    public function test_tenant_scoped(): void
    {
        $r = self::$m->query("SHOW COLUMNS FROM message_drafts LIKE 'company_id'");
        $this->assertSame(1, $r->num_rows, 'company_id missing on message_drafts (FR-042)');
    }

    public function test_fks_tenant_and_source(): void
    {
        $r = self::$m->query("SHOW CREATE TABLE message_drafts");
        $this->assertNotFalse($r);
        $ddl = $r->fetch_row()[1];
        // Tenant FK to companies (RESTRICT — cannot delete tenant out from under drafts).
        $this->assertStringContainsString('CONSTRAINT `fk_md_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)', $ddl);
    }

    public function test_source_link_columns(): void
    {
        // FR-021: source-linked (link to the decision/load/lead it serves).
        foreach (['source_type', 'source_id'] as $c) {
            $r = self::$m->query("SHOW COLUMNS FROM message_drafts LIKE '{$c}'");
            $this->assertSame(1, $r->num_rows, "source column missing: {$c}");
        }
        $r = self::$m->query("SHOW COLUMNS FROM message_drafts LIKE 'source_type'");
        $row = $r->fetch_row();
        $this->assertNotFalse($row);
        $type = strtolower((string) $row[1]);
        $this->assertStringContainsString("'decision'", $type);
        $this->assertStringContainsString("'load'", $type);
        $this->assertStringContainsString("'lead'", $type);
        $this->assertStringStartsWith('enum', $type, 'source_type must be an enum');
    }

    public function test_status_enum_values(): void
    {
        // Draft states: drafting, pending approval, sent, rejected.
        $r = self::$m->query("SHOW COLUMNS FROM message_drafts LIKE 'status'");
        $row = $r->fetch_row();
        $this->assertNotFalse($row);
        $this->assertStringContainsString("'draft'", $row[1]);
        $this->assertStringContainsString("'pending_approval'", $row[1]);
        $this->assertStringContainsString("'sent'", $row[1]);
        $this->assertStringContainsString("'rejected'", $row[1]);
    }

    public function test_soft_delete_columns_present(): void
    {
        foreach (['deleted_at', 'deleted_by', 'delete_reason'] as $c) {
            $r = self::$m->query("SHOW COLUMNS FROM message_drafts LIKE '{$c}'");
            $this->assertSame(1, $r->num_rows, "soft-delete column missing: {$c}");
        }
    }
}
