<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;

/**
 * T19 migration 017 — learning_proposals + proposal_status_history (FR-038/039/041).
 * Hostinger-safe: applies cleanly; new tables present; existing tables' column ORDER
 * is unchanged (AuditLog hard-codes tool_calls/agent_runs insert tuples — must not break).
 */
final class LearningProposalsSchemaTest extends TestCase
{
    private static \mysqli $m;
    private static string $db;

    public static function setUpBeforeClass(): void
    {
        $host = $_ENV['RL_DB_HOST'] ?? '127.0.0.1';
        $user = $_ENV['RL_DB_USER'] ?? 'root';
        $pass = $_ENV['RL_DB_PASS'] ?? '';
        $port = (int) ($_ENV['RL_DB_PORT'] ?? '3306');

        $base = new \mysqli($host, $user, $pass, '', $port);
        if ($base->connect_error) {
            self::fail('Cannot connect to MySQL for schema test: ' . $base->connect_error);
        }
        self::$db = 'routlaw_test_' . bin2hex(random_bytes(6));
        // Drop-if-exists first: a leftover same-named DB from an earlier crashed run
        // would carry stale column order under CREATE DATABASE's idempotency.
        $base->query("DROP DATABASE IF EXISTS `" . self::$db . "`");
        $base->query("CREATE DATABASE `" . self::$db . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        self::$m = new \mysqli($host, $user, $pass, self::$db, $port);
        self::$m->set_charset('utf8mb4');
        self::$m->query("SET SESSION time_zone = '+00:00'");

        $migDir = dirname(__DIR__) . '/migrations';
        for ($i = 0; $i <= 17; $i++) {
            $matches = glob($migDir . '/' . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . '_*.sql');
            if ($matches === []) {
                continue;
            }
            Connection::applyFile(self::$m, $matches[0]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$m)) {
            self::$m->query("DROP DATABASE IF EXISTS `" . self::$db . "`");
            self::$m->close();
        }
    }

    public function test_new_tables_exist(): void
    {
        $t = self::$m->query("SHOW TABLES LIKE 'learning_proposals'");
        $this->assertSame(1, $t->num_rows, 'learning_proposals table missing');
        $t2 = self::$m->query("SHOW TABLES LIKE 'proposal_status_history'");
        $this->assertSame(1, $t2->num_rows, 'proposal_status_history table missing');
    }

    public function test_learning_proposals_columns_and_status_enum(): void
    {
        $cols = [];
        $r = self::$m->query('SHOW COLUMNS FROM learning_proposals');
        while ($row = $r->fetch_assoc()) {
            $cols[$row['Field']] = $row;
        }
        foreach (['id','company_id','run_id','status','payload_hash','payload_json','version','created_by','approved_by','created_at','updated_at'] as $c) {
            $this->assertArrayHasKey($c, $cols, "learning_proposals.$c missing");
        }
        // approved_by is the hard gate: must start NULL-able so a draft has no approver.
        $this->assertSame('YES', $cols['approved_by']['Null'], 'approved_by must be nullable');
        // status enum covers the full lifecycle.
        $this->assertStringContainsString("'draft'", $cols['status']['Type']);
        $this->assertStringContainsString("'approved'", $cols['status']['Type']);
        $this->assertStringContainsString("'staged'", $cols['status']['Type']);
        $this->assertStringContainsString("'rolled_back'", $cols['status']['Type']);
        $this->assertStringContainsString("'rejected'", $cols['status']['Type']);
    }

    public function test_proposal_status_history_columns(): void
    {
        $cols = [];
        $r = self::$m->query('SHOW COLUMNS FROM proposal_status_history');
        while ($row = $r->fetch_assoc()) {
            $cols[$row['Field']] = $row;
        }
        foreach (['id','proposal_id','from_status','to_status','actor_id','reason','created_at'] as $c) {
            $this->assertArrayHasKey($c, $cols, "proposal_status_history.$c missing");
        }
    }

    public function test_existing_tool_calls_column_order_preserved(): void
    {
        // AuditLog::recordToolCall hard-codes INSERT (company_id, agent_run_id, tool_name,
        // request_hash, result_status). The 017 migration must NOT reorder those 5 columns.
        $order = [];
        $r = self::$m->query('SHOW COLUMNS FROM tool_calls');
        while ($row = $r->fetch_assoc()) {
            $order[] = $row['Field'];
        }
        $idx = array_flip($order);
        // AuditLog::recordToolCall INSERT tuple: company_id, agent_run_id, tool_name,
        // request_hash, result_status — these 5 must remain in this relative order.
        $posCompany = $idx['company_id'];
        $posAgentRun = $idx['agent_run_id'];
        $posTool = $idx['tool_name'];
        $posHash = $idx['request_hash'];
        $posStatus = $idx['result_status'];
        $this->assertLessThan($posTool, $posAgentRun, 'tool_calls: agent_run_id must precede tool_name');
        $this->assertLessThan($posHash, $posTool, 'tool_calls: tool_name must precede request_hash');
        $this->assertLessThan($posStatus, $posHash, 'tool_calls: request_hash must precede result_status');
    }
}
