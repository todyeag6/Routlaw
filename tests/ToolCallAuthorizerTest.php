<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\ToolAllowlist;
use Routlaw\Agent\ToolCallAuthorizer;
use Routlaw\Approvals\ApprovalService;
use Routlaw\Db\Connection;
use Routlaw\Security\AuditLog;
use Routlaw\Security\Authorization;

/**
 * T17 ToolCallAuthorizer — HAR-01/02/09, LLM06.
 * Per-call authorization: allowlist membership + role/permission + payload-bound
 * approval for regulated writes (reuses T15 ApprovalService). Denied calls must NOT
 * execute; they record a blocked tool_call + audit row.
 */
final class ToolCallAuthorizerTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_toolauth';
    private static ?\mysqli $m = null;
    private ToolAllowlist $allow;
    private Authorization $authz;
    private AuditLog $audit;
    private int $companyA;
    private int $companyB;
    private int $dispatcherA;
    private int $reviewerA;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        for ($i = 0; $i <= 16; $i++) {
            $matches = glob(__DIR__ . '/../migrations/' . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . '_*.sql');
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

    protected function setUp(): void
    {
        // Idempotent seeding: clear prior per-test rows first.
        // NOTE: audit_events is append-only (BR-017 trigger) — cannot DELETE; rows
        // accumulate across tests in this throwaway DB, which is acceptable.
        self::$m->query('DELETE FROM tool_calls');
        self::$m->query('DELETE FROM approval_requests');
        self::$m->query('DELETE FROM users');
        self::$m->query('DELETE FROM companies');

        $this->allow = new ToolAllowlist();
        $this->authz = new Authorization(self::$m);
        $this->audit = new AuditLog(self::$m);

        // Seed two tenants + a dispatcher and a reviewer in tenant A.
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA','CoA')");
        $this->companyA = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoB','CoB')");
        $this->companyB = (int) self::$m->insert_id;

        self::$m->query("INSERT INTO users (company_id, email, password_hash, full_name, role_id, status) VALUES ({$this->companyA}, 'disp@a.com', 'x', 'Disp', 3, 'active')");
        $this->dispatcherA = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO users (company_id, email, password_hash, full_name, role_id, status) VALUES ({$this->companyA}, 'rev@a.com', 'x', 'Rev', 5, 'active')");
        $this->reviewerA = (int) self::$m->insert_id;
        // Authorization resolves role via user_role_assignments (not users.role_id).
        self::$m->query("INSERT IGNORE INTO user_role_assignments (user_id, role_id, company_id) VALUES ({$this->dispatcherA}, 3, {$this->companyA}), ({$this->reviewerA}, 5, {$this->companyA})");

        // Grant dispatcher the permissions the agent policy expects (mirrors
        // PolicyEnforcement::BASE_PERMISSIONS for the dispatcher role). The 000 seed
        // only grants super/company admins; tests own their fixtures.
        self::$m->query(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) '
            . 'SELECT 3, p.id FROM permissions p WHERE p.code IN '
            . "('carriers.view','carriers.edit','loads.create','approvals.outbound')"
        );
        self::$m->query(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) '
            . 'SELECT 5, p.id FROM permissions p WHERE p.code IN '
            . "('carriers.view','audit.view')"
        );
    }

    protected function tearDown(): void
    {
        self::$m->query('DELETE FROM tool_calls');
        self::$m->query('DELETE FROM approval_requests');
    }

    private function session(int $userId, int $companyId, string $roleSlug): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession($userId, $companyId, 'Co', 'Co', 'u@x.com', 'U', 3, $roleSlug);
    }

    public function test_allowed_tool_with_permission_is_authorized(): void
    {
        $auth = new ToolCallAuthorizer(self::$m, $this->allow, $this->authz, $this->audit);
        $sess = $this->session($this->dispatcherA, $this->companyA, 'dispatcher');
        $result = $auth->authorize('carrier.lookup', $sess, ['carrier_id' => 1], 'agent.mvp.v1', 'policy.mvp.v1');
        $this->assertTrue($result->allowed, 'carrier.lookup with carriers.view permission must be allowed.');
        $this->assertSame('carrier.lookup', $result->toolName);
    }

    public function test_tool_not_in_allowlist_is_blocked(): void
    {
        $auth = new ToolCallAuthorizer(self::$m, $this->allow, $this->authz, $this->audit);
        $sess = $this->session($this->dispatcherA, $this->companyA, 'dispatcher');
        $result = $auth->authorize('self.grant.admin', $sess, [], 'agent.mvp.v1', 'policy.mvp.v1');
        $this->assertFalse($result->allowed, 'Tool absent from allowlist must be blocked (HAR-04).');
        $this->assertStringContainsString('allowlist', $result->denyReason);
        $this->assertBlockedToolCallRecorded('self.grant.admin');
    }

    public function test_regulated_write_without_approval_is_blocked(): void
    {
        $auth = new ToolCallAuthorizer(self::$m, $this->allow, $this->authz, $this->audit);
        $sess = $this->session($this->dispatcherA, $this->companyA, 'dispatcher');
        $payload = ['carrier_id' => 7, 'field' => 'dot_number', 'value' => '123456'];
        $result = $auth->authorize('carrier.update', $sess, $payload, 'agent.mvp.v1', 'policy.mvp.v1');
        $this->assertFalse($result->allowed, 'Regulated write without payload-bound approval MUST be blocked (LLM06).');
        $this->assertStringContainsString('approval', $result->denyReason);
    }

    public function test_regulated_write_with_valid_approval_is_authorized(): void
    {
        $sess = $this->session($this->dispatcherA, $this->companyA, 'dispatcher');
        $payload = ['carrier_id' => 7, 'field' => 'dot_number', 'value' => '123456'];

        // Create + approve a payload-bound approval (reuse T15 ApprovalService).
        $approval = new ApprovalService(self::$m);
        $approval->create($this->companyA, $this->dispatcherA, 'carrier.update', 'carrier', 7, $payload, '+1 day');
        // Mark approved directly (no UserSession approver in unit scope) via SQL transition.
        self::$m->query("UPDATE approval_requests SET status='approved', decided_at=NOW() WHERE action='carrier.update' AND resource_id=7 AND company_id={$this->companyA}");

        $auth = new ToolCallAuthorizer(self::$m, $this->allow, $this->authz, $this->audit);
        $result = $auth->authorize('carrier.update', $sess, $payload, 'agent.mvp.v1', 'policy.mvp.v1');
        $this->assertTrue($result->allowed, 'Regulated write WITH valid payload-bound approval must be authorized.');
        $this->assertSame('7', (string) $result->approvalId);
    }

    public function test_tampered_payload_fails_approval_recheck(): void
    {
        $sess = $this->session($this->dispatcherA, $this->companyA, 'dispatcher');
        $payload = ['carrier_id' => 7, 'field' => 'dot_number', 'value' => '123456'];
        $approval = new ApprovalService(self::$m);
        $approval->create($this->companyA, $this->dispatcherA, 'carrier.update', 'carrier', 7, $payload, '+1 day');
        self::$m->query("UPDATE approval_requests SET status='approved', decided_at=NOW() WHERE action='carrier.update' AND resource_id=7 AND company_id={$this->companyA}");

        $auth = new ToolCallAuthorizer(self::$m, $this->allow, $this->authz, $this->audit);
        // Tampered payload (different value) => hash mismatch => blocked.
        $tampered = ['carrier_id' => 7, 'field' => 'dot_number', 'value' => '999999'];
        $result = $auth->authorize('carrier.update', $sess, $tampered, 'agent.mvp.v1', 'policy.mvp.v1');
        $this->assertFalse($result->allowed, 'Tampered payload must fail the payload-bound recheck (FR-024).');
    }

    public function test_missing_permission_is_blocked(): void
    {
        // Reviewer (role 5) lacks carriers.edit/approvals; carrier.update requires carriers.edit.
        $auth = new ToolCallAuthorizer(self::$m, $this->allow, $this->authz, $this->audit);
        $sess = $this->session($this->reviewerA, $this->companyA, 'reviewer');
        $result = $auth->authorize('carrier.update', $sess, ['carrier_id' => 1], 'agent.mvp.v1', 'policy.mvp.v1');
        $this->assertFalse($result->allowed, 'Caller lacking the required permission must be blocked.');
        $this->assertStringContainsString('permission', $result->denyReason);
    }

    private function assertBlockedToolCallRecorded(string $tool): void
    {
        $r = self::$m->query("SELECT id, allowed, result_status FROM tool_calls WHERE tool_name='" . self::$m->real_escape_string($tool) . "'");
        $this->assertSame(1, $r->num_rows, 'A blocked call must still record a tool_call row (audit trace).');
        $row = $r->fetch_assoc();
        $this->assertSame(0, (int) $row['allowed'], 'allowed flag must be 0 for a blocked call.');
        $this->assertSame('blocked', $row['result_status']);
    }
}
