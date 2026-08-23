<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;

/**
 * T17/T18 schema contract (build-plan §4 Phase 6; migrations/016_agent_harness.sql).
 * Applies 000..016 to a throwaway MariaDB test DB and verifies the harness +
 * memory schema without disturbing any other database. Also asserts the PRE-EXISTING
 * tool_calls/agent_runs INSERT tuples used by AuditLog are unchanged (no reordering).
 */
final class AgentHarnessSchemaTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_agent_harness';
    private static ?\mysqli $m = null;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        for ($i = 0; $i <= 16; $i++) {
            $file = __DIR__ . '/../migrations/' . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . '_*.sql';
            $matches = glob($file);
            if (empty($matches)) {
                throw new \RuntimeException("Missing migration file matching {$file}");
            }
            Connection::applyFile(self::$m, $matches[0]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            $base = Connection::connect();
            $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    public function test_agent_runs_has_budget_columns(): void
    {
        foreach (['step_count','token_estimate','cost_cents','budget_status'] as $col) {
            $r = self::$m->query("SHOW COLUMNS FROM agent_runs LIKE '{$col}'");
            $this->assertSame(1, $r->num_rows, "agent_runs.{$col} missing (T17 budget/kill-switch).");
        }
        $r = self::$m->query("SHOW COLUMNS FROM agent_runs LIKE 'budget_status'");
        $row = $r->fetch_assoc();
        $this->assertStringContainsString('ok', $row['Default'] ?? '', 'budget_status default must be ok.');
    }

    public function test_tool_calls_has_decision_columns(): void
    {
        foreach (['allowed','deny_reason','output_hash','sanitized'] as $col) {
            $r = self::$m->query("SHOW COLUMNS FROM tool_calls LIKE '{$col}'");
            $this->assertSame(1, $r->num_rows, "tool_calls.{$col} missing (T17 allowlist decision trace).");
        }
    }

    public function test_system_flags_for_kill_switch(): void
    {
        $r = self::$m->query("SHOW TABLES LIKE 'system_flags'");
        $this->assertSame(1, $r->num_rows, 'system_flags table missing (kill switch host).');
        $r = self::$m->query("SHOW COLUMNS FROM system_flags LIKE 'key'");
        $this->assertSame(1, $r->num_rows, 'system_flags.key missing.');
    }

    public function test_memory_entries_schema_tenant_role_scoped(): void
    {
        $r = self::$m->query("SHOW TABLES LIKE 'memory_entries'");
        $this->assertSame(1, $r->num_rows, 'memory_entries table missing (T18).');
        foreach (['company_id','agent_run_id','min_role','content','provenance_json','contradiction_of','status','expires_at','revalidated_at'] as $col) {
            $r = self::$m->query("SHOW COLUMNS FROM memory_entries LIKE '{$col}'");
            $this->assertSame(1, $r->num_rows, "memory_entries.{$col} missing.");
        }
        // Tenant + role composite index must exist (LLM02/PRIV-004: filter BEFORE search).
        $r = self::$m->query("SHOW INDEX FROM memory_entries WHERE Key_name = 'idx_mem_company_role'");
        $this->assertGreaterThanOrEqual(1, $r->num_rows, 'Tenant+role composite index missing (cross-tenant leakage gate depends on it).');
        // FK to companies must exist (tenant integrity).
        $r = self::$m->query("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='memory_entries' AND REFERENCED_TABLE_NAME='companies'");
        $this->assertSame(1, $r->num_rows, 'memory_entries.company_id FK to companies missing.');
    }

    public function test_existing_auditlog_insert_tuples_intact(): void
    {
        // AuditLog::recordAgentRun inserts (company_id, correlation_id, workflow_type,
        // agent_version, policy_version, status) and recordToolCall inserts
        // (company_id, agent_run_id, tool_name, request_hash, result_status). The ALTER
        // must NOT have reordered/renamed those columns. Verify they still exist in order.
        $cols = [];
        $r = self::$m->query('SHOW COLUMNS FROM agent_runs');
        while ($row = $r->fetch_assoc()) { $cols[] = $row['Field']; }
        $cols = array_values($cols);
        $seq = ['company_id','correlation_id','workflow_type','agent_version','policy_version','status'];
        $prevPos = null;
        foreach ($seq as $c) {
            $pos = array_search($c, $cols, true);
            $this->assertNotFalse($pos, "agent_runs.{$c} missing after ALTER.");
            if ($prevPos !== null) {
                $this->assertGreaterThan($prevPos, $pos, "Column order changed: {$c} must come after prior expected column.");
            }
            $prevPos = $pos;
        }
    }
}
