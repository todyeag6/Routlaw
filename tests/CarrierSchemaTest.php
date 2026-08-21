<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;

/**
 * T10.0 — carriers.cdl_status schema gap (FRD §12.4 logical schema vs migration 002).
 *
 * FRD §12.4 lists `carriers(id, company_id, legal_name, dot_number, mc_number,
 * cdl_status, status, ...)`. Migration 002_carriers.sql is missing `cdl_status`.
 * This test asserts the column exists with the expected ENUM domain and default.
 *
 * TDD RED: the column does not exist yet → this fails until migration 007 is applied.
 */
final class CarrierSchemaTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_carrier_schema';
    private static ?\mysqli $m = null;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/002_carriers.sql');
        // 007 is the subject under test; applied here so the assertion runs against it.
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/007_carriers_cdl_status.sql');
    }

    public static function tearDownAfterClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    public function test_cdl_status_column_exists(): void
    {
        $res = self::$m->query("SHOW COLUMNS FROM carriers LIKE 'cdl_status'");
        $this->assertNotFalse($res, 'SHOW COLUMNS must succeed.');
        $row = $res->fetch_assoc();
        $this->assertNotEmpty($row, 'carriers.cdl_status column must exist (FRD §12.4).');
    }

    public function test_cdl_status_enum_domain_and_default(): void
    {
        $res = self::$m->query("SHOW COLUMNS FROM carriers WHERE Field = 'cdl_status'");
        $row = $res->fetch_assoc();
        $this->assertNotEmpty($row, 'cdl_status column must exist.');

        $type = (string) ($row['Type'] ?? '');
        foreach (['unknown', 'non_cdl', 'cdl_a', 'cdl_b', 'cdl_c'] as $allowed) {
            $this->assertStringContainsString($allowed, $type, "cdl_status ENUM must include '{$allowed}'.");
        }
        $this->assertSame('unknown', (string) ($row['Default'] ?? ''), 'cdl_status default must be unknown.');
    }

    public function test_cdl_status_is_nullable_false(): void
    {
        $res = self::$m->query("SHOW COLUMNS FROM carriers WHERE Field = 'cdl_status'");
        $row = $res->fetch_assoc();
        $this->assertSame('NO', (string) ($row['Null'] ?? ''), 'cdl_status must be NOT NULL (default unknown).');
    }
}
