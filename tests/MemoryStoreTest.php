<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\Memory\MemoryEntry;
use Routlaw\Agent\Memory\MemoryStore;
use Routlaw\Db\Connection;

/**
 * T18 MemoryStore — FR-034/035/036/037 (+ leakage gate, per OQ-2 ADR: FR-040 under T18).
 * Provenance-tagged, tenant/role-scoped retrieval BEFORE any ranking (LLM02/PRIV-004,
 * LLM08). Contradiction handling: a conflicting write flags the prior entry, never
 * silently overwrites (LLM04). Expiry/revalidation enforced on read (LLM04).
 *
 * HARD RELEASE GATE (§19.3 analog): cross-tenant retrieval leakage = 0.
 */
final class MemoryStoreTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_memory';
    private static ?\mysqli $m = null;
    private MemoryStore $store;
    private int $companyA;
    private int $companyB;

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
        self::$m->query('DELETE FROM memory_entries');
        self::$m->query('DELETE FROM audit_events');
        self::$m->query('DELETE FROM companies');
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoA','CoA')");
        $this->companyA = (int) self::$m->insert_id;
        self::$m->query("INSERT INTO companies (legal_name, display_name) VALUES ('CoB','CoB')");
        $this->companyB = (int) self::$m->insert_id;
        $this->store = new MemoryStore(self::$m);
    }

    private function entry(int $companyId, int $minRole, string $content, array $provenance = [], ?string $expiresAt = null): MemoryEntry
    {
        return new MemoryEntry(
            companyId: $companyId,
            minRole: $minRole,
            content: $content,
            provenance: $provenance !== [] ? $provenance : null,
            agentRunId: null,
            status: 'active',
            expiresAt: $expiresAt,
            revalidatedAt: null,
        );
    }

    public function test_write_and_provenance_stored(): void
    {
        $e = $this->entry($this->companyA, 3, 'Carrier X DOT 123456 approved.',
            ['agent_run_id' => 1, 'tool_call_id' => 2, 'source' => 'carrier.lookup']);
        $id = $this->store->write($e);
        $this->assertGreaterThan(0, $id, 'Write returns a new entry id.');
        $stored = $this->store->readForTenant($this->companyA, 5, 1)[0] ?? null;
        $this->assertNotNull($stored);
        $this->assertSame('Carrier X DOT 123456 approved.', $stored->content);
        $this->assertSame(3, $stored->minRole);
        $this->assertSame('1', (string) ($stored->provenance['agent_run_id'] ?? ''), 'Provenance must be retained.');
    }

    /**
     * HARD GATE (§19.3): a tenant+role-scoped query MUST return zero rows from other
     * tenants, and only rows whose min_role <= caller role.
     */
    public function test_cross_tenant_retrieval_leakage_is_zero(): void
    {
        // Seed entries across two tenants with varied min_role.
        $this->store->write($this->entry($this->companyA, 1, 'A-admin secret'));
        $this->store->write($this->entry($this->companyA, 3, 'A-dispatcher note'));
        $this->store->write($this->entry($this->companyA, 5, 'A-reviewer log'));
        $this->store->write($this->entry($this->companyB, 1, 'B-admin secret'));
        $this->store->write($this->entry($this->companyB, 3, 'B-dispatcher note'));
        $this->store->write($this->entry($this->companyB, 5, 'B-reviewer log'));

        // Company A dispatcher (role 3) retrieves.
        $results = $this->store->retrieve('Carrier X', $this->companyA, 3, 'note');
        $companies = array_unique(array_map(fn (MemoryEntry $e) => $e->companyId, $results));
        $leaked = array_filter($results, fn (MemoryEntry $e) => $e->companyId !== $this->companyA);
        $overRole = array_filter($results, fn (MemoryEntry $e) => $e->minRole > 3);

        $this->assertSame([$this->companyA], $companies, 'Retrieval must be scoped to the caller tenant.');
        $this->assertCount(0, $leaked, 'CROSS-TENANT LEAKAGE MUST BE 0 (§19.3 hard gate).');
        $this->assertCount(0, $overRole, 'No entry above caller role may be returned.');
        $this->assertNotEmpty($results, 'Caller-tenant, in-role entries must be returned.');

        // Company B reviewer (role 5) — must see only B, role<=5 (all B entries here).
        $resultsB = $this->store->retrieve('log', $this->companyB, 5, 'log');
        $leakedB = array_filter($resultsB, fn (MemoryEntry $e) => $e->companyId !== $this->companyB);
        $this->assertCount(0, $leakedB, 'B retrieval must not leak A (§19.3).');
    }

    public function test_contradiction_flags_prior_not_overwrites(): void
    {
        $a = $this->store->write($this->entry($this->companyA, 3, 'Carrier Y is APPROVED'));
        // A conflicting later entry marks the first as contradicted (never overwritten).
        $this->store->write($this->entry($this->companyA, 3, 'Carrier Y is REVOKED'), contradictingId: $a);
        // Active retrieval must surface only the NEW entry (the prior is flagged contradicted,
        // never silently overwritten — LLM04).
        $active = $this->store->readForTenant($this->companyA, 3, 1);
        $this->assertCount(1, $active, 'Only the active (new) entry is returned by scoped read.');
        $this->assertSame('Carrier Y is REVOKED', $active[0]->content);
        // Both rows physically exist; the prior carries status 'contradicted'.
        $statuses = array_map(
            static fn (array $r) => $r['status'],
            self::$m->query("SELECT status FROM memory_entries WHERE company_id = {$this->companyA} ORDER BY id")->fetch_all(MYSQLI_ASSOC)
        );
        $this->assertContains('contradicted', $statuses, 'Prior entry must be flagged contradicted (not overwritten).');
        $this->assertContains('active', $statuses, 'New entry active.');
    }

    public function test_expired_entry_excluded_on_read(): void
    {
        $past = gmdate('Y-m-d H:i:s', time() - 3600);
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->store->write($this->entry($this->companyA, 3, 'expired', [], $past));
        $this->store->write($this->entry($this->companyA, 3, 'fresh', [], $future));
        $all = $this->store->retrieve('e', $this->companyA, 3, 'e');
        $contents = array_map(static fn (MemoryEntry $e) => $e->content, $all);
        $this->assertNotContains('expired', $contents, 'Expired entry must be excluded on read (LLM04).');
        $this->assertContains('fresh', $contents, 'Non-expired entry returned.');
    }

    public function test_tenant_scoping_enforced_at_write_path(): void
    {
        // write() must store the company_id it is given; a read scoped to another tenant
        // returns nothing. Verifies the write path carries tenant scope.
        $this->store->write($this->entry($this->companyA, 3, 'scoped write'));
        $asB = $this->store->readForTenant($this->companyB, 3, 1);
        $this->assertCount(0, $asB, 'Company B must not see Company A writes.');
    }
}
