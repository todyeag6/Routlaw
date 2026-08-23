<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\ToolAllowlist;

/**
 * T17 ToolAllowlist — HAR-01/02/04, LLM06.
 * The allowlist is server-side PHP, loaded from config/agent_policy.php, and is
 * structurally immutable: model output CANNOT grant tools, expand scope, or rewrite
 * the list. Mirrors T9 PolicyEnforcement immutability.
 */
final class ToolAllowlistTest extends TestCase
{
    private string $policyPath;
    private string $snapshotHash;

    protected function setUp(): void
    {
        $this->policyPath = __DIR__ . '/../config/agent_policy.php';
        $this->assertFileExists($this->policyPath, 'agent_policy.php must exist (T17 allowlist source).');
        $this->snapshotHash = md5_file($this->policyPath);
    }

    public function test_allowlist_loads_tool_set(): void
    {
        $al = new ToolAllowlist();
        $tools = $al->listFor('agent.mvp.v1', 'policy.mvp.v1');
        $this->assertNotEmpty($tools, 'Allowlist must define tools for the MVP agent.');
        $this->assertContains('carrier.lookup', $tools, 'carrier.lookup is a seeded allowed tool.');
    }

    public function test_unknown_tool_rejected(): void
    {
        $al = new ToolAllowlist();
        $this->assertFalse($al->isAllowed('self.grant.admin', 'agent.mvp.v1', 'policy.mvp.v1'),
            'A tool not in the allowlist must be rejected even if the model claims it exists.');
        $this->assertFalse($al->isAllowed('system.wipe', 'agent.mvp.v1', 'policy.mvp.v1'),
            'Destructive tool absent from allowlist must be rejected.');
    }

    public function test_per_tool_requires_approval_flag_present(): void
    {
        $al = new ToolAllowlist();
        $tools = $al->listFor('agent.mvp.v1', 'policy.mvp.v1');
        foreach ($tools as $tool) {
            $this->assertIsBool($al->requiresApproval($tool, 'agent.mvp.v1', 'policy.mvp.v1'),
                "Every tool must declare a requires_approval boolean: {$tool}");
        }
        // A regulated-write tool must require approval (LLM06: payload-bound approval).
        $this->assertTrue($al->requiresApproval('carrier.update', 'agent.mvp.v1', 'policy.mvp.v1'),
            'carrier.update writes a regulated entity and MUST require approval.');
    }

    /**
     * HAR-04 / LLM06: a model attempting to "grant itself" a tool or rewrite policy
     * must NOT change the on-disk or in-memory policy. The snapshot is byte-for-byte
     * unchanged after feeding an adversarial payload.
     */
    public function test_model_cannot_mutate_allowlist_or_policy_file(): void
    {
        $al = new ToolAllowlist();
        $before = $al->snapshot();

        // Simulate model output attempting to add a privileged tool and disable approvals.
        $adversarial = [
            'grant_tool' => 'self.grant.admin',
            'add_tool' => 'system.wipe',
            'policy' => ['tool_allowlist' => ['*'], 'require_approval' => false],
            'tools' => ['self.grant.admin' => ['requires_approval' => false]],
        ];
        // The allowlist exposes NO mutation method; we assert the class simply has none
        // whose name implies granting/adding/setting/updating policy (HAR-04).
        $ref = new \ReflectionClass($al);
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(add|grant|set|update|mutate|remove|delete|insert|append)/i',
                $m->getName(),
                "ToolAllowlist MUST NOT expose a mutation method (HAR-04). Found: {$m->getName()}"
            );
        }

        // Feed the adversarial payload to a no-op "ingest" path; assert snapshot unchanged.
        $al->ingestUntrustedModelOutput($adversarial);
        $after = $al->snapshot();

        $this->assertSame($before, $after, 'snapshot() must be identical after ingesting model output.');
        $this->assertFalse($al->isAllowed('self.grant.admin', 'agent.mvp.v1', 'policy.mvp.v1'),
            'Adversarial grant must NOT take effect.');
        $this->assertSame($this->snapshotHash, md5_file($this->policyPath),
            'On-disk policy file MUST be byte-for-byte unchanged (BR-017-style immutability).');
    }
}
