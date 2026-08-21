<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;

/**
 * T12.1 — decision-case schema (FRD §12.4): decision_cases, decision_input_snapshots,
 * decision_outcomes, prediction_variances, counterparty_observations, facility_observations.
 * All tenant-scoped (company_id) from day one (FR-042).
 */
final class DecisionSchemaTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_decision_schema';
    private static ?\mysqli $m = null;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','002_carriers.sql','003_equipment_profiles.sql','004_cost_profiles.sql','007_carriers_cdl_status.sql','008_gate_results.sql','009_decision_cases.sql'] as $mig) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $mig);
        }
    }

    public static function tearDownAfterClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tableProvider')]
    public function test_table_exists_and_tenant_scoped(string $table): void
    {
        $res = self::$m->query("SHOW TABLES LIKE '{$table}'");
        $this->assertNotFalse($res);
        $this->assertNotEmpty($res->fetch_row(), "Table {$table} must exist (FRD §12.4).");
        $col = self::$m->query("SHOW COLUMNS FROM {$table} LIKE 'company_id'");
        $this->assertNotEmpty($col->fetch_row(), "Table {$table} must carry company_id (FR-042).");
    }

    public static function tableProvider(): array
    {
        return [
            ['decision_cases'],
            ['decision_input_snapshots'],
            ['decision_outcomes'],
            ['prediction_variances'],
            ['counterparty_observations'],
            ['facility_observations'],
        ];
    }
}
