<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Security\AuditLog;
use Routlaw\Leads\SourceRecordService;
use Routlaw\Loads\LoadService;

/**
 * TDD for T8 Manual + email load intake, source preservation, schema extraction
 * (build-plan §4 Phase 3 T8, FR-010/FR-011/FR-013/FR-014).
 *
 * FR-010 acceptance: authorized users create loads with required pickup/delivery/
 *   commodity/weight + source context; record enters review; missing details visible.
 * FR-011 acceptance: ingested approved email creates a reviewable candidate load.
 * FR-014 acceptance: normalized load links to immutable source_record + ingestion ts.
 * FR-013 (see LoadExtractionTest): per-field confidence + missing-field reporting, no hallucination.
 *
 * Tenant-scoped from day one (FR-042). No Python, no Redis, no Docker.
 */
final class LoadTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_loads';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static LoadService $loads;
    private static SourceRecordService $sources;
    private static AuditLog $audit;

    private int $companyA;
    private int $companyB;

    public static function setUpBeforeClass(): void
    {
        $conn = Connection::connect();
        $conn->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $conn->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/000_init_foundation.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/001_audit_events.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/005_leads_source_records.sql');
        Connection::applyFile(self::$m, __DIR__ . '/../migrations/006_loads.sql');

        self::$auth = new Auth(self::$m);
        self::$loads = new LoadService(self::$m);
        self::$sources = new SourceRecordService(self::$m);
        self::$audit = new AuditLog(self::$m);
    }

    public static function tearDownAfterClass(): void
    {
        $conn = Connection::connect();
        $conn->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
    }

    protected function setUp(): void
    {
        self::$m->query('SET FOREIGN_KEY_CHECKS = 0');
        self::$m->query('TRUNCATE loads');
        self::$m->query('TRUNCATE source_records');
        self::$m->query('TRUNCATE audit_events');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM users');
        self::$m->query('DELETE FROM user_role_assignments');
        self::$m->query('DELETE FROM companies');

        $this->companyA = self::$auth->createCompany('Alpha Transport', 'Alpha Transport LLC');
        $this->companyB = self::$auth->createCompany('Beta Haulers', 'Beta Haulers LLC');
    }

    /** FR-010: authorized manual load entry with required fields; enters review (new). */
    public function test_manual_load_entry_creates_reviewable_record(): void
    {
        $srcId = self::$sources->create(
            $this->companyA,
            'manual_entry',
            null,
            hash('sha256', 'manual payload'),
            'operator manual entry',
            'operator manual entry',
            'application/json',
            null
        );

        $id = self::$loads->createManualLoad(
            $this->companyA,
            [
                'origin_city' => 'Dallas', 'origin_state' => 'TX',
                'dest_city' => 'Atlanta', 'dest_state' => 'GA',
                'commodity' => 'General Freight', 'weight_lbs' => 24000,
                'posted_rate' => '1850.00',
            ],
            1,
            $srcId
        );
        $this->assertGreaterThan(0, $id, 'Manual load must return a valid ID (FR-010).');

        $row = self::$m->query('SELECT * FROM loads WHERE id = ' . (int) $id)->fetch_assoc();
        $this->assertNotFalse($row, 'Load must be persisted (FR-010).');
        $this->assertSame((string) $this->companyA, $row['company_id'], 'Load tenant-scoped (FR-042).');
        $this->assertSame('new', $row['status'], 'New manual load enters review workflow (FR-010).');
        $this->assertSame('Dallas', $row['origin_city']);
        $this->assertSame('Atlanta', $row['dest_city']);
        $this->assertSame('General Freight', $row['commodity']);
        $this->assertSame('24000', $row['weight_lbs']);
        $this->assertSame((string) $srcId, $row['source_record_id'], 'Load links to immutable source (FR-014).');
    }

    /** FR-010 acceptance: missing required details are visible (review_required / needs_info surfaced). */
    public function test_manual_load_with_missing_required_fields_is_flagged(): void
    {
        // Weight omitted: required input missing => review_required true, status new.
        $srcId = self::$sources->create($this->companyA, 'manual_entry', null, hash('sha256', 'x'), 'x', 'x', 'text/plain', null);
        $id = self::$loads->createManualLoad(
            $this->companyA,
            ['origin_city' => 'Dallas', 'origin_state' => 'TX', 'dest_city' => 'Atlanta', 'dest_state' => 'GA', 'commodity' => 'Steel'],
            1,
            $srcId
        );
        $row = self::$m->query('SELECT review_required, status, extraction_missing FROM loads WHERE id = ' . (int) $id)->fetch_assoc();
        $this->assertSame(1, (int) $row['review_required'], 'Load with missing weight flags review (FR-010).');
        $this->assertStringContainsString('weight_lbs', (string) $row['extraction_missing'], 'Missing field enumerated (FR-013/010).');
    }

    /** FR-010/FR-029: manual load creation emits an audit event. */
    public function test_manual_load_emits_audit_event(): void
    {
        $before = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='load.create'")->fetch_column();
        $srcId = self::$sources->create($this->companyA, 'manual_entry', null, hash('sha256', 'x'), 'x', 'x', 'text/plain', null);
        self::$loads->createManualLoad($this->companyA, ['origin_city' => 'D', 'origin_state' => 'TX', 'dest_city' => 'A', 'dest_state' => 'GA', 'commodity' => 'c', 'weight_lbs' => 1], 1, $srcId);
        $after = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='load.create'")->fetch_column();
        $this->assertSame($before + 1, $after, 'Load creation must be audited (FR-029).');
    }

    /** FR-042: load created in company A invisible to company B. */
    public function test_load_isolation_across_tenants(): void
    {
        $srcId = self::$sources->create($this->companyA, 'manual_entry', null, hash('sha256', 'x'), 'x', 'x', 'text/plain', null);
        $id = self::$loads->createManualLoad($this->companyA, ['origin_city' => 'D', 'origin_state' => 'TX', 'dest_city' => 'A', 'dest_state' => 'GA', 'commodity' => 'c', 'weight_lbs' => 1], 1, $srcId);
        $idsB = array_column(self::$loads->listForCompany($this->companyB), 'id');
        $this->assertNotContains((int) $id, $idsB, 'Other tenant must not see this load (SEC-010/FR-042).');
    }

    /** FR-011: ingested approved email creates a reviewable candidate load linked to the email source. */
    public function test_approved_email_intake_creates_candidate_load(): void
    {
        // Simulate a vetted (approved) Gmail message: source_record carries the email payload + external id.
        $msgId = 'gmail-msg-abc123';
        $emailBody = "Load: Dallas, TX to Atlanta, GA. Commodity: General Freight. Weight: 24000 lbs. Rate: $1850.";
        $srcId = self::$sources->create(
            $this->companyA,
            'email',
            $msgId,
            hash('sha256', $emailBody),
            $emailBody,
            $emailBody,
            'text/plain',
            null
        );

        $id = self::$loads->createEmailLoad(
            $this->companyA,
            $srcId,
            ['origin_city' => 'Dallas', 'origin_state' => 'TX', 'dest_city' => 'Atlanta', 'dest_state' => 'GA', 'commodity' => 'General Freight', 'weight_lbs' => 24000, 'posted_rate' => '1850.00'],
            1
        );
        $this->assertGreaterThan(0, $id, 'Email-derived candidate load must be created (FR-011).');

        $row = self::$m->query('SELECT source_record_id, status FROM loads WHERE id = ' . (int) $id)->fetch_assoc();
        $this->assertSame((string) $srcId, $row['source_record_id'], 'Candidate load links to email source (FR-011/FR-014).');
        $this->assertSame('new', $row['status'], 'Candidate load is reviewable, not auto-published (FR-011).');
    }

    /** FR-011/FR-014: the email source_record must be preservable and re-readable for the load (immutable ref). */
    public function test_email_source_preserved_and_linkable(): void
    {
        $msgId = 'gmail-msg-preserve';
        $emailBody = "Pickup Houston TX, Deliver Orlando FL, 30000 lbs, machinery.";
        $srcId = self::$sources->create($this->companyA, 'email', $msgId, hash('sha256', $emailBody), $emailBody, $emailBody, 'text/plain', null);
        $src = self::$sources->getForCompany($this->companyA, $srcId);
        $this->assertNotNull($src, 'Source preserved (FR-014).');
        $this->assertSame($msgId, $src['external_id'], 'Email external id retained.');
        $this->assertSame($emailBody, $src['canonical_payload'], 'Original email body retained verbatim (FR-014).');
    }
}
