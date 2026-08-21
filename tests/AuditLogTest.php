<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Security\AuditLog;
use Routlaw\Security\Authorization;
use Routlaw\Security\UserSession;

/**
 * TDD for T3 Audit: append-only audit_events (FR-029/FR-030/BR-017).
 * Normal application roles cannot UPDATE/DELETE audit records.
 * The write path for agent_runs + tool_calls is also tested (FR-030).
 */
final class AuditLogTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_audit';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static Authorization $authz;
    private static AuditLog $audit;

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
        self::$audit = new AuditLog(self::$m);
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
        // Order matters: tool_calls references agent_runs via FK.
        self::$m->query('SET FOREIGN_KEY_CHECKS = 0');
        self::$m->query('TRUNCATE audit_events');
        self::$m->query('TRUNCATE tool_calls');
        self::$m->query('TRUNCATE agent_runs');
        self::$m->query('TRUNCATE user_role_assignments');
        self::$m->query('TRUNCATE login_attempts');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM users');
        self::$m->query('DELETE FROM roles');
        self::$m->query('DELETE FROM companies');

        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');

        $companyA = self::$auth->createCompany('Alpha Transport', 'Alpha Transport LLC');
        $dispatcher = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        self::$auth->createUser($companyA, 'alice@example.com', 'Pass!123', 'Alice', $dispatcher);
    }

    private function loginAlice(): UserSession
    {
        $user = self::$auth->login('alice@example.com', 'Pass!123');
        $this->assertNotNull($user, 'Login failed for Alice');
        return $user;
    }

    /**
     * BR-017: Normal application roles cannot alter or delete audit records.
     * Test: a direct UPDATE on audit_events by an app-role DB user is rejected.
     */
    public function test_audit_events_immutable_update_rejected(): void
    {
        $alice = $this->loginAlice();
        self::$audit->record($alice, 'data.update', 'carriers', '1', 'success', ['field' => 'status']);

        // Attempt to UPDATE an audit row — must be rejected.
        try {
            $stmt = self::$m->prepare('UPDATE audit_events SET result = ? WHERE id = ?');
            $result = 'failure';
            $id = 1;
            $stmt->bind_param('si', $result, $id);
            $stmt->execute();
            $stmt->close();
            // If we get here, the UPDATE succeeded — verify the app-level guard caught it.
            $row = self::$m->query('SELECT result FROM audit_events WHERE id = 1')->fetch_assoc();
            $this->assertSame(
                'success',
                $row['result'],
                'Audit events must be immutable to UPDATE (BR-017).'
            );
        } catch (\mysqli_sql_exception $e) {
            // The DB rejected it (trigger) — expected.
            $this->assertStringContainsString('append-only', $e->getMessage(),
                'Trigger must reject UPDATE on audit_events (BR-017).');
        }
    }

    /**
     * BR-017: Normal application roles cannot delete audit records.
     */
    public function test_audit_events_immutable_delete_rejected(): void
    {
        $alice = $this->loginAlice();
        self::$audit->record($alice, 'data.update', 'carriers', '1', 'success', ['field' => 'status']);

        // Attempt to DELETE an audit row — must be rejected.
        try {
            $stmt = self::$m->prepare('DELETE FROM audit_events WHERE id = ?');
            $id = 1;
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            // If we get here, DELETE succeeded — verify the row still exists.
            $count = (int) self::$m->query('SELECT COUNT(*) FROM audit_events')->fetch_column();
            $this->assertNotSame(0, $count, 'Audit events must not be deletable by app roles (BR-017).');
            $row = self::$m->query('SELECT result FROM audit_events WHERE id = 1')->fetch_assoc();
            $this->assertNotFalse($row, 'Deleted audit row must still exist (BR-017).');
        } catch (\mysqli_sql_exception $e) {
            $this->assertStringContainsString('append-only', $e->getMessage(),
                'Trigger must reject DELETE on audit_events (BR-017).');
        }
    }

    /** FR-029: audit event has actor, tenant, action, target, result, time. */
    public function test_audit_event_has_required_fields(): void
    {
        $alice = $this->loginAlice();
        self::$audit->record($alice, 'data.update', 'carriers', '1', 'success', ['field' => 'status']);

        $row = self::$m->query(
            "SELECT * FROM audit_events WHERE event_type = 'data.update' ORDER BY id DESC LIMIT 1"
        )->fetch_assoc();

        $this->assertNotFalse($row, 'Audit event must be recorded (FR-029).');
        $this->assertSame('user', $row['actor_type'], 'Actor must be recorded (FR-029).');
        $this->assertSame((string) $alice->userId, $row['actor_id'], 'Actor ID must be recorded (FR-029).');
        $this->assertSame((string) $alice->companyId, $row['company_id'], 'Tenant scope must be recorded (FR-029/SEC-010).');
        $this->assertSame('data.update', $row['event_type']);
        $this->assertSame('data.update', $row['action']);
        $this->assertSame('carriers', $row['target_type']);
        $this->assertSame('1', $row['target_id']);
        $this->assertSame('success', $row['result']);
        $this->assertNotEmpty($row['created_at'], 'Timestamp must be recorded (FR-029).');
    }

    /** FR-029: audit events are append-only (count monotonically increases). */
    public function test_audit_events_append_only(): void
    {
        $alice = $this->loginAlice();
        $before = (int) self::$m->query('SELECT COUNT(*) FROM audit_events')->fetch_column();

        self::$audit->record($alice, 'data.create', 'brokers', '5', 'success', []);
        self::$audit->record($alice, 'data.update', 'brokers', '5', 'success', []);
        self::$audit->record($alice, 'approval.grant', 'approval_requests', '3', 'success', []);

        $after = (int) self::$m->query('SELECT COUNT(*) FROM audit_events')->fetch_column();
        $this->assertSame($before + 3, $after, 'Audit events must be strictly append-only (BR-017/FR-029).');
    }

    /** FR-030: agent run record with workflow/model metadata. */
    public function test_agent_run_recorded(): void
    {
        $alice = $this->loginAlice();
        $runId = self::$audit->recordAgentRun($alice->companyId, 'lead_extraction', 'v1.0.0', 'policy-v3', 'succeeded');

        $this->assertGreaterThan(0, $runId, 'Agent run must return an ID (FR-030).');

        $row = self::$m->query('SELECT * FROM agent_runs WHERE id = ' . (int) $runId)->fetch_assoc();
        $this->assertNotFalse($row, 'Agent run must be persisted (FR-030).');
        $this->assertSame((string) $alice->companyId, $row['company_id']);
        $this->assertSame('lead_extraction', $row['workflow_type']);
        $this->assertSame('v1.0.0', $row['agent_version']);
        $this->assertSame('policy-v3', $row['policy_version']);
        $this->assertSame('succeeded', $row['status']);
    }

    /** FR-030: tool call under an agent run. */
    public function test_tool_call_recorded_under_agent_run(): void
    {
        $alice = $this->loginAlice();
        $runId = self::$audit->recordAgentRun($alice->companyId, 'load_matching', 'v2.1.0', 'policy-v3', 'running');

        $callId = self::$audit->recordToolCall($alice->companyId, $runId, 'load_extraction', 'success');
        $this->assertGreaterThan(0, $callId, 'Tool call must return an ID (FR-030).');

        $row = self::$m->query('SELECT * FROM tool_calls WHERE id = ' . (int) $callId)->fetch_assoc();
        $this->assertNotFalse($row, 'Tool call must be persisted (FR-030).');
        $this->assertSame((string) $runId, $row['agent_run_id']);
        $this->assertSame('load_extraction', $row['tool_name']);
        $this->assertSame('success', $row['result_status']);
    }

    /** SEC-010: audit events are tenant-scoped (no cross-tenant leakage). */
    public function test_audit_scope_query_returns_only_tenant(): void
    {
        $companyA = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Alpha Transport'")->fetch_row()[0];
        $alice = $this->loginAlice();

        // Record two events: one for company A, one cross-tenant attempt.
        self::$audit->record($alice, 'data.view', 'carriers', '1', 'success', []);
        // Simulate an event for a different tenant (e.g. system event for company B).
        self::$audit->recordSystem('data.scrub', 0, 'system', 'completed', null);

        // Alice's scoped audit query must not see the other tenant's system event.
        $events = self::$authz->scopeQuery(
            $alice->userId,
            $companyA,
            'audit_events',
            'SELECT id, company_id, event_type FROM audit_events WHERE company_id = ? ORDER BY id',
            []
        );
        $this->assertGreaterThanOrEqual(1, count($events), 'Alice must see her own audit events.');
        foreach ($events as $e) {
            $this->assertEquals((string) $companyA, $e['company_id'], 'Cross-tenant audit leakage must be zero (SEC-010).');
        }
    }
}
