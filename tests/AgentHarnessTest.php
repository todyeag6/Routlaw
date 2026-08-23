<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\AgentHarness;
use Routlaw\Agent\AgentTool;
use Routlaw\Agent\BudgetEnforcer;
use Routlaw\Agent\KillSwitch;
use Routlaw\Agent\OutputSanitizer;
use Routlaw\Agent\SecretHandleService;
use Routlaw\Agent\ToolAllowlist;
use Routlaw\Agent\ToolCallAuthorizer;
use Routlaw\Agent\ToolOutputValidator;
use Routlaw\Db\Connection;
use Routlaw\Security\AuditLog;
use Routlaw\Security\Authorization;
use Routlaw\Security\UserSession;

/**
 * AgentHarness integration — proves the T17 pipeline enforces policy end-to-end with
 * STUB tools (no live LLM, per Phase 6 STOP condition). The model's intended tool call is
 * supplied as data; the harness may never expand scope / grant tools / bypass authz.
 */
final class AgentHarnessTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_harness';
    private static ?\mysqli $m = null;
    private int $companyId;
    private int $dispatcherId;
    private int $runId;
    private AgentHarness $harness;
    private KillSwitch $kill;
    private BudgetEnforcer $budget;

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
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    protected function setUp(): void
    {
        self::$m->query('DELETE FROM tool_calls');
        self::$m->query('DELETE FROM approval_requests');
        self::$m->query('DELETE FROM agent_runs');
        self::$m->query('DELETE FROM users');
        self::$m->query('DELETE FROM companies');
        self::$m->query('DELETE FROM role_permissions');
        self::$m->query('DELETE FROM user_role_assignments');
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA','CoA')");
        $this->companyId = (int) self::$m->insert_id;
        foreach (['carriers.view','carriers.edit','loads.create','approvals.outbound'] as $perm) {
            self::$m->query("INSERT IGNORE INTO permissions (code, description) VALUES ('$perm', '$perm')");
        }
        self::$m->query("INSERT INTO role_permissions (role_id, permission_id) SELECT 3, id FROM permissions WHERE code IN ('carriers.view','carriers.edit','loads.create','approvals.outbound')");
        self::$m->query("INSERT INTO users (company_id, email, password_hash, full_name, role_id, status) VALUES ({$this->companyId}, 'd@a.com', 'x', 'D', 3, 'active')");
        $this->dispatcherId = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO user_role_assignments (user_id, role_id, company_id) VALUES ({$this->dispatcherId}, 3, {$this->companyId})");
        self::$m->query("INSERT INTO agent_runs (company_id, workflow_type, agent_version, policy_version, status) VALUES ({$this->companyId}, 'w', 'agent.mvp.v1', 'policy.mvp.v1', 'running')");
        $this->runId = (int) self::$m->insert_id;

        $allow = new ToolAllowlist();
        $authz = new Authorization(self::$m);
        $audit = new AuditLog(self::$m);
        $this->kill = new KillSwitch(self::$m);
        $this->budget = new BudgetEnforcer(self::$m, ['max_steps'=>100,'max_tokens'=>100000,'max_cost_cents'=>100000,'max_seconds'=>86400,'max_concurrency'=>5], $this->kill);
        $authorizer = new ToolCallAuthorizer(self::$m, $allow, $authz, $audit);
        $validator = new ToolOutputValidator($allow);
        $sanitizer = new OutputSanitizer();
        $secrets = new SecretHandleService(['api_key' => 'AKIA-REAL']);
        $session = new UserSession($this->dispatcherId, $this->companyId, 'CoA', 'CoA', 'd@a.com', 'D', 3, 'dispatcher');
        $this->harness = new AgentHarness($authorizer, $this->kill, $this->budget, $validator, $sanitizer, $secrets, $session, $this->runId);
    }

    private function row(string $tool): ?array
    {
        $r = self::$m->query("SELECT allowed, result_status, output_hash, sanitized, deny_reason FROM tool_calls WHERE tool_name = '$tool' ORDER BY id DESC LIMIT 1");
        $row = $r !== false ? $r->fetch_assoc() : null;
        return $row === false ? null : $row;
    }

    public function test_disallowed_tool_blocked_before_execution(): void
    {
        $executed = false;
        $res = $this->harness->step(new class($executed) implements AgentTool {
            public function __construct(private bool &$executed) {}
            public function name(): string { return 'self.grant'; }
            public function run(array $args, SecretHandleService $s): mixed { $this->executed = true; return 'evil'; }
        }, []);
        $this->assertFalse($res->allowed, 'Tool not on allowlist must be blocked (HAR-01/02, LLM06).');
        $this->assertFalse($executed, 'Blocked tool must NOT execute (pipeline halts before run).');
        $row = $this->row('self.grant');
        $this->assertNotNull($row, 'Denied call is still audited (tool_calls row present).');
        $this->assertSame('0', $row['allowed']);
        $this->assertNull($row['output_hash'], 'No output hash: tool never produced output.');
    }

    public function test_regulated_write_without_approval_blocked(): void
    {
        $res = $this->harness->step(new class implements AgentTool {
            public function name(): string { return 'carrier.update'; }
            public function run(array $args, SecretHandleService $s): mixed { return ['carrier_id' => 7, 'dot' => 'X']; }
        }, ['carrier_id' => 7, 'dot' => 'X']);
        $this->assertFalse($res->allowed, 'Regulated write without approval must be blocked (HAR-09).');
        $this->assertStringContainsString('approval', (string) $res->denyReason);
    }

    public function test_allowed_tool_executes_and_records_sanitized_output(): void
    {
        $res = $this->harness->step(new class implements AgentTool {
            public function name(): string { return 'carrier.lookup'; }
            public function run(array $args, SecretHandleService $s): mixed { return ['carrier_id' => 7, 'name' => 'Carrier X']; }
        }, ['carrier_id' => 7]);
        $this->assertTrue($res->succeeded(), 'Allowed tool with permission must succeed.');
        $this->assertNotNull($res->outputHash, 'Output hash recorded (HAR-13).');
        $row = $this->row('carrier.lookup');
        $this->assertNotNull($row, 'tool_calls row recorded.');
        $this->assertSame('1', $row['allowed']);
        $this->assertContains($row['sanitized'], ['0', '1'], 'Sanitization flag recorded (HAR-14).');
        $this->assertSame($res->outputHash, $row['output_hash']);
    }

    public function test_kill_switch_halts_all_steps(): void
    {
        $this->kill->activate($this->companyId);
        $res = $this->harness->step(new class implements AgentTool {
            public function name(): string { return 'carrier.lookup'; }
            public function run(array $args, SecretHandleService $s): mixed { return ['carrier_id' => 7]; }
        }, ['carrier_id' => 7]);
        $this->assertTrue($res->killSwitchActive, 'Kill switch must halt the step (HAR-08, LLM10).');
        $this->assertFalse($res->succeeded());
    }

    public function test_budget_exhaustion_halts(): void
    {
        // Force the run over its step cap.
        self::$m->query("UPDATE agent_runs SET step_count = 100 WHERE id = {$this->runId}");
        $res = $this->harness->step(new class implements AgentTool {
            public function name(): string { return 'carrier.lookup'; }
            public function run(array $args, SecretHandleService $s): mixed { return ['carrier_id' => 7]; }
        }, ['carrier_id' => 7]);
        $this->assertFalse($res->budgetOk, 'Budget cap exceeded must halt (FR-032, LLM10).');
        $this->assertFalse($res->succeeded());
    }

    public function test_injection_output_rejected(): void
    {
        $res = $this->harness->step(new class implements AgentTool {
            public function name(): string { return 'memory.write'; }
            public function run(array $args, SecretHandleService $s): mixed {
                return ['entry_id' => 1, 'content' => '<script>alert(1)</script>'];
            }
        }, ['entry_id' => 1]);
        // memory.write requires loads.create (dispatcher has it) so it may be authorized;
        // but its output carries an injection pattern and must be rejected by the validator.
        if ($res->allowed) {
            $this->assertNotNull($res->error, 'Injection-bearing output must be rejected by validator (HAR-13).');
        } else {
            $this->assertNotNull($res->denyReason);
        }
    }
}
