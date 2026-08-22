<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;

/**
 * T16.1 — Migration 012_documents.sql.
 * Verifies documents schema: tenant-scoped, storage_key (CHAR(64), randomized),
 * classification-as-label enum (NOT legal validity), soft-delete, download_token.
 */
final class DocumentsSchemaTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_documents';

    private static ?\mysqli $m = null;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','012_documents.sql'] as $f) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $f);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            Connection::connect()->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    private function cols(string $table): array
    {
        $res = self::$m->query("SHOW COLUMNS FROM {$table}") or
            self::fail('SHOW COLUMNS failed: ' . self::$m->error);
        $map = [];
        while ($r = $res->fetch_assoc()) {
            $map[$r['Field']] = $r;
        }
        $res->close();
        return $map;
    }

    public function test_documents_table_exists(): void
    {
        $this->assertSame(1, (int) self::$m->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='documents'")->fetch_row()[0]);
    }

    public function test_storage_key_is_char64(): void
    {
        $c = $this->cols('documents');
        $this->assertArrayHasKey('storage_key', $c, 'storage_key column must exist (FR-025)');
        $this->assertStringContainsString('char(64)', strtolower($c['storage_key']['Type']), 'storage_key must be CHAR(64) for sodium_bin2hex(random_bytes(32))');
    }

    public function test_classification_is_label_enum(): void
    {
        $c = $this->cols('documents');
        $this->assertSame('enum', strtolower(explode('(', $c['classification']['Type'])[0]), 'classification must be an enum label');
        $type = strtolower($c['classification']['Type']);
        $this->assertStringContainsString("'internal'", $type);
        $this->assertStringContainsString("'confidential'", $type);
        $this->assertStringContainsString("'restricted'", $type);
        $this->assertSame('internal', $c['classification']['Default'], 'default label is internal');
    }

    public function test_mime_type_not_null_unique_tenant_scope(): void
    {
        $c = $this->cols('documents');
        $this->assertNotNull($c['mime_type']);
        $this->assertSame('NO', $c['mime_type']['Null'], 'mime_type must be NOT NULL');
        $this->assertArrayHasKey('download_token', $c);
        $this->assertSame('YES', $c['download_token']['Null'], 'download_token may be null until shared');
        $this->assertArrayHasKey('deleted_at', $c);
        $this->assertSame('YES', $c['deleted_at']['Null'], 'deleted_at nullable for soft-delete');
    }

    public function test_company_foreign_key(): void
    {
        $row = self::$m->query(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE " .
            "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' " .
            "AND COLUMN_NAME='company_id' AND REFERENCED_TABLE_NAME='companies'"
        ) ?? self::fail('fk query failed');
        $this->assertSame(1, (int) $row->fetch_row()[0], 'company_id must FK to companies(id) RESTRICT');
    }
}
