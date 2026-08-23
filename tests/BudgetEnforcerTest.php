<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\BudgetEnforcer;
use Routlaw\Agent\KillSwitch;
use Routlaw\Db\Connection;

/**
 * T17 BudgetEnforcer + KillSwitch — HAR-08, FR-032, LLM10, SEC-017.
 * Per-run hard caps on steps/tokens/cost/time/concurrency. Exceeding a cap halts the
 * run (no further tool executes). The global kill switch blocks ALL tool execution
 * while active. Both are server-side and cannot be raised by model output.
 */
final class BudgetEnforcerTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_budget';
    private static ?\mysqli $m = null;
    private int $companyId;
    private int $runId;

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
        self::$m->query('DELETE FROM agent_runs');
        self::$m->query('DELETE FROM system_flags');
        self::$m->query('DELETE FROM audit_events');
        self::$m->query('DELETE FROM companies');
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoBud','CoBud')");
        $this->companyId = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO agent_runs (company_id, workflow_type, agent_version, policy_version, status) VALUES ({$this->companyId}, 'w', 'agent.mvp.v1', 'policy.mvp.v1', 'running')");
        $this->runId = (int) self::$m->insert_id;
    }

    public function test_step_budget_exceeded_halts_run(): void
    {
        $cfg = ['max_steps' => 3, 'max_tokens' => 100000, 'max_cost_cents' => 100000, 'max_seconds' => 86400, 'max_concurrency' => 5];
        $e = new BudgetEnforcer(self::$m, $cfg);
        $this->assertTrue($e->canStep($this->runId), 'First 3 steps allowed.');
        $e->recordStep($this->runId);
        $e->recordStep($this->runId);
        $e->recordStep($this->runId);
        $this->assertFalse($e->canStep($this->runId), '4th step must be blocked by step cap.');
        $this->assertSame('exceeded', $this->budgetStatus());
    }

    public function test_token_budget_exceeded_halts_run(): void
    {
        $cfg = ['max_steps' => 100, 'max_tokens' => 10, 'max_cost_cents' => 100000, 'max_seconds' => 86400, 'max_concurrency' => 5];
        $e = new BudgetEnforcer(self::$m, $cfg);
        $e->recordTokens($this->runId, 6);
        $this->assertTrue($e->canStep($this->runId));
        $e->recordTokens($this->runId, 6); // total 12 > 10
        $this->assertFalse($e->canStep($this->runId), 'Token cap exceeded must halt.');
        $this->assertSame('exceeded', $this->budgetStatus());
    }

    public function test_cost_budget_exceeded_halts_run(): void
    {
        $cfg = ['max_steps' => 100, 'max_tokens' => 100000, 'max_cost_cents' => 50, 'max_seconds' => 86400, 'max_concurrency' => 5];
        $e = new BudgetEnforcer(self::$m, $cfg);
        $e->recordCostCents($this->runId, 30);
        $e->recordCostCents($this->runId, 30); // 60 > 50
        $this->assertFalse($e->canStep($this->runId), 'Cost cap exceeded must halt.');
    }

    public function test_kill_switch_blocks_all_execution(): void
    {
        $ks = new KillSwitch(self::$m);
        $ks->activate($this->companyId);
        $this->assertTrue($ks->isActive($this->companyId), 'Kill switch must be active after activate().');
        $cfg = ['max_steps' => 100, 'max_tokens' => 100000, 'max_cost_cents' => 100000, 'max_seconds' => 86400, 'max_concurrency' => 5];
        $e = new BudgetEnforcer(self::$m, $cfg, $ks);
        $this->assertFalse($e->canStep($this->runId), 'Active kill switch must block all steps regardless of budget.');
        $this->assertSame('killed', $this->budgetStatus());
        $ks->deactivate($this->companyId);
        $this->assertFalse($ks->isActive($this->companyId), 'Kill switch cleared.');
        // A killed run stays killed; a NEW run is allowed again after deactivation.
        self::$m->query("INSERT INTO agent_runs (company_id, workflow_type, agent_version, policy_version, status) VALUES ({$this->companyId}, 'w2', 'agent.mvp.v1', 'policy.mvp.v1', 'running')");
        $newRunId = (int) self::$m->insert_id;
        $e2 = new BudgetEnforcer(self::$m, $cfg, $ks);
        $this->assertTrue($e2->canStep($newRunId), 'Fresh run allowed again after kill switch cleared.');
    }

    public function test_kill_switch_is_global_not_model_raisable(): void
    {
        // KillSwitch has no method that takes model output; it is operator-controlled only.
        $ref = new \ReflectionClass(new KillSwitch(self::$m));
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $this->assertDoesNotMatchRegularExpression('/^(set|raise|allow|enable)/i', $m->getName(),
                'KillSwitch MUST NOT expose a model-raisable enable method.');
        }
    }

    private function budgetStatus(): string
    {
        $r = self::$m->query("SELECT budget_status FROM agent_runs WHERE id={$this->runId}");
        $row = $r->fetch_assoc();
        return (string) ($row['budget_status'] ?? '');
    }
}
