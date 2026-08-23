<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\Llm\Client as LlmClient;
use Routlaw\Agent\Memory\MemoryEntry;
use Routlaw\Agent\Memory\MemoryStore;
use Routlaw\Agent\OutcomeLearning;
use Routlaw\Db\Connection;

/**
 * T19-4 OutcomeLearning — FR-039 (post-outcome learning, tenant-scoped via MemoryStore).
 * LLM summarizes; PHP scopes/writes. Cross-tenant leakage gate still holds (LLM summary
 * of another tenant's outcome must NOT cross company_id).
 */
final class OutcomeLearningTest extends TestCase
{
    private static \mysqli $m;
    private static string $db;
    private MemoryStore $store;
    private OutcomeLearning $learning;
    private const COMPANY = 7;

    public static function setUpBeforeClass(): void
    {
        $host = $_ENV['RL_DB_HOST'] ?? '127.0.0.1';
        $user = $_ENV['RL_DB_USER'] ?? 'root';
        $pass = $_ENV['RL_DB_PASS'] ?? '';
        $port = (int) ($_ENV['RL_DB_PORT'] ?? '3306');
        $base = new \mysqli($host, $user, $pass, '', $port);
        if ($base->connect_error) self::fail('DB connect: ' . $base->connect_error);
        self::$db = 'routlaw_test_' . bin2hex(random_bytes(6));
        $base->query("DROP DATABASE IF EXISTS `".self::$db."`");
        $base->query("CREATE DATABASE `".self::$db."` CHARACTER SET utf8mb4");
        self::$m = new \mysqli($host, $user, $pass, self::$db, $port);
        self::$m->set_charset('utf8mb4');
        $d = dirname(__DIR__) . '/migrations';
        for ($i = 0; $i <= 17; $i++) {
            $g = glob($d . '/' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '_*.sql');
            if (!$g) continue;
            Connection::applyFile(self::$m, $g[0]);
        }
        // Seed the tenant ids used by the tests (FK fk_memory_company).
        foreach ([1, 7, 8] as $cid) {
            self::$m->query("INSERT INTO companies (id, legal_name, display_name, status) VALUES ({$cid}, 'Co{$cid}', 'Co{$cid}', 'active')");
        }
        // FK fk_memory_agent requires agent_runs rows to exist for agent_run_id values.
        foreach ([11, 12] as $rid) {
            self::$m->query("INSERT INTO agent_runs (id, company_id, workflow_type, started_at) VALUES ({$rid}, 1, 'self_correction', NOW())");
        }
    }
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$m)) self::$m->query("DROP DATABASE IF EXISTS `".self::$db."`");
    }

    protected function setUp(): void
    {
        $this->store = new MemoryStore(self::$m);
        $this->learning = new OutcomeLearning($this->store, new OutcomeStubLlmClient('used carrier.lookup; learned carriers edit after lookup'));
    }

    public function test_record_learning_is_tenant_scoped(): void
    {
        $id = $this->learning->record(
            self::COMPANY, runId: 11,
            approvedTools: ['carrier.lookup'],
            actualTools: ['carrier.lookup'],
            approvedOutputHash: 'abc',
            finalOutputHash: 'abc',
            verdict: true,
        );
        $this->assertGreaterThan(0, $id, 'learning entry persisted');
        $res = self::$m->query("SELECT company_id, min_role, content FROM memory_entries WHERE id={$id}");
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : false;
        $this->assertIsArray($row, 'learning row must exist');
        $this->assertSame(self::COMPANY, (int) $row['company_id'], 'learning tagged with originating tenant');
        $this->assertStringContainsString('carrier.lookup', (string) $row['content']);
    }

    public function test_cross_tenant_retrieval_leakage_is_zero(): void
    {
        // Company 7 and company 8 BOTH write learning; company 8 retrieves and must see
        // only its own entries (company 7's learning must never cross the tenant boundary).
        $this->learning->record(7, 11, ['carrier.lookup'], ['carrier.lookup'], 'abc', 'abc', true);
        $this->learning->record(8, 12, ['carrier.lookup'], ['carrier.lookup'], 'def', 'def', true);
        $results = $this->store->retrieve('carrier.lookup', 8, 5, 'carrier.lookup');
        $leaked = array_filter($results, fn (MemoryEntry $e) => $e->companyId !== 8);
        $this->assertCount(0, $leaked, 'cross-tenant leakage = 0 (FR-040 hard gate)');
        $this->assertNotEmpty($results, 'company 8 sees its own entries');
    }

    public function test_failed_verdict_still_records_tenant_scoped(): void
    {
        $id = $this->learning->record(
            self::COMPANY, 12, ['carrier.lookup'], ['self.grant'], 'abc', 'def', false,
        );
        $res = self::$m->query("SELECT company_id, content, provenance_json FROM memory_entries WHERE id={$id}");
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : false;
        $this->assertIsArray($row, 'failed-verdict row must exist');
        $this->assertSame(self::COMPANY, (int) $row['company_id']);
        $this->assertNotEmpty($row['content'], 'learning summary persisted even on failure');
        $prov = json_decode((string) $row['provenance_json'], true);
        $this->assertSame('fail', $prov['verdict'] ?? '', 'provenance records failed verdict');
        $this->assertContains('self.grant', $prov['actual_tools'] ?? [], 'provenance retains out-of-scope tool run');
    }
}

final class OutcomeStubLlmClient implements LlmClient
{
    public function __construct(private string $content = 'learned something') {}
    public function chat(array $messages, array $options = []): array
    {
        return ['role' => 'assistant', 'content' => $this->content];
    }
}
