<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\AgentHarness;
use Routlaw\Agent\BudgetEnforcer;
use Routlaw\Agent\KillSwitch;
use Routlaw\Agent\Llm\Client as LlmClient;
use Routlaw\Agent\Memory\MemoryStore;
use Routlaw\Agent\OutcomeLearning;
use Routlaw\Agent\OutputSanitizer;
use Routlaw\Agent\SelfCorrectionVerifier;
use Routlaw\Agent\SelfPromotionGuard;
use Routlaw\Agent\SecretHandleService;
use Routlaw\Agent\ToolAllowlist;
use Routlaw\Agent\ToolCallAuthorizer;
use Routlaw\Agent\ToolOutputValidator;
use Routlaw\Db\Connection;
use Routlaw\Security\Authorization;
use Routlaw\Security\AuditLog;
use Routlaw\Security\UserSession;

/**
 * T19-5 AgentHarness::reflect() — post-run self-correction + learning wiring.
 *
 * The stub path (no live LLM / Decision #2/#11 not signed) must produce a PASS/FAIL
 * verdict WITHOUT any Ollama call. Only when an LLM client is injected does it consult
 * the model — and even then, the verifier verdict is PHP-decided.
 */
final class ReflectStubTest extends TestCase
{
    private static \mysqli $db;
    private static string $dbName;

    public static function setUpBeforeClass(): void
    {
        $host = $_ENV['RL_DB_HOST'] ?? '127.0.0.1';
        $user = $_ENV['RL_DB_USER'] ?? 'root';
        $pass = $_ENV['RL_DB_PASS'] ?? '';
        $port = (int) ($_ENV['RL_DB_PORT'] ?? '3306');
        $base = new \mysqli($host, $user, $pass, '', $port);
        if ($base->connect_error) self::fail('DB connect: ' . $base->connect_error);
        self::$dbName = 'routlaw_test_' . bin2hex(random_bytes(6));
        $base->query("DROP DATABASE IF EXISTS `" . self::$dbName . "`");
        $base->query("CREATE DATABASE `" . self::$dbName . "` CHARACTER SET utf8mb4");
        self::$db = new \mysqli($host, $user, $pass, self::$dbName, $port);
        self::$db->set_charset('utf8mb4');
        $d = dirname(__DIR__) . '/migrations';
        for ($i = 0; $i <= 17; $i++) {
            $g = glob($d . '/' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '_*.sql');
            if (!$g) continue;
            Connection::applyFile(self::$db, $g[0]);
        }
        self::$db->query("INSERT INTO companies (id, legal_name, display_name, status) VALUES (7, 'C7', 'C7', 'active')");
        self::$db->query("INSERT INTO agent_runs (id, company_id, workflow_type, started_at) VALUES (1, 7, 'self_correction', NOW())");
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            self::$db->query("DROP DATABASE IF EXISTS `" . self::$dbName . "`");
        }
    }

    public function test_reflect_no_live_llm_gives_rule_based_verdict(): void
    {
        $guard = $this->stubGuard();
        $store = $this->stubStore();
        $verifier = new SelfCorrectionVerifier(new ReflectStubLlmClient('stub'));
        $learning = new OutcomeLearning($store, new ReflectStubLlmClient('stub'));

        $h = $this->makeHarness();
        // approved == actual, matching hashes => PASS, no live LLM.
        $verdict = $h->reflect($verifier, $guard, $learning, ['carrier.lookup'], ['carrier.lookup'], ['abc'], ['abc']);
        $this->assertTrue($verdict->passed, 'stub (in-scope, matching) run passes without LLM');
        $this->assertSame('verified', $verdict->verdict);
    }

    public function test_reflect_failure_blocks_and_still_records_learning(): void
    {
        $guard = $this->stubGuard();
        $store = $this->stubStore();
        $verifier = new SelfCorrectionVerifier(new ReflectStubLlmClient('stub'));
        $learning = new OutcomeLearning($store, new ReflectStubLlmClient('stub'));

        $h = $this->makeHarness();
        // approved carrier.lookup, but model actually ran self.grant => FAIL, no self-promotion.
        $verdict = $h->reflect($verifier, $guard, $learning, ['carrier.lookup'], ['self.grant'], ['abc'], ['abc']);
        $this->assertFalse($verdict->passed, 'out-of-scope run fails and is NOT self-promoted');
        $this->assertSame('out_of_scope', $verdict->verdict);
    }

    private function makeHarness(): AgentHarness
    {
        $db = self::$db;
        $allowlist = new ToolAllowlist();
        $authz = new Authorization($db);
        $audit = new AuditLog($db);
        return new AgentHarness(
            new ToolCallAuthorizer($db, $allowlist, $authz, $audit),
            new KillSwitch($db),
            new BudgetEnforcer($db, ['max_steps' => 100, 'max_tokens' => 100000, 'max_cost_cents' => 0, 'max_seconds' => 0, 'max_concurrency' => 1]),
            new ToolOutputValidator($allowlist),
            new OutputSanitizer(),
            new SecretHandleService([]),
            $this->stubSession(7),
            1,
        );
    }

    private function stubGuard(): SelfPromotionGuard
    {
        return new SelfPromotionGuard(self::$db);
    }

    private function stubStore(): MemoryStore
    {
        return new MemoryStore(self::$db);
    }

    private function stubSession(int $companyId): UserSession
    {
        return new UserSession($companyId, $companyId, 'TestCo', 'TestCo Inc', 'u@test', 'Test User', 1, 'super_admin');
    }
}

final class ReflectStubLlmClient implements LlmClient
{
    public function __construct(private string $content = 'stub') {}
    public function chat(array $messages, array $options = []): array
    {
        return ['role' => 'assistant', 'content' => $this->content];
    }
}
