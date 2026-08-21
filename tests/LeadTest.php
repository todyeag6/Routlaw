<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Security\AuditLog;
use Routlaw\Leads\LeadService;
use Routlaw\Leads\SourceRecordService;

/**
 * TDD for T7 Typed leads + conservative dedup (build-plan §4 Phase 3 T7, FR-008/FR-009/BR-003).
 *
 * FR-008 acceptance: each lead contains tenant, source, status, timestamps, and
 *   raw submission reference.
 * FR-009 acceptance: possible duplicates are reviewable; no automatic destructive merge.
 *
 * Tenant-scoped from day one (FR-042, BR-020): every query carries company_id.
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class LeadTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_leads';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static LeadService $leads;
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

        self::$auth = new Auth(self::$m);
        self::$leads = new LeadService(self::$m);
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
        self::$m->query('TRUNCATE leads');
        self::$m->query('TRUNCATE source_records');
        self::$m->query('TRUNCATE audit_events');
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM users');
        self::$m->query('DELETE FROM user_role_assignments');
        self::$m->query('DELETE FROM companies');

        $this->companyA = self::$auth->createCompany('Alpha Transport', 'Alpha Transport LLC');
        $this->companyB = self::$auth->createCompany('Beta Haulers', 'Beta Haulers LLC');
    }

    /** FR-008: create a typed lead; it persists with tenant, status, timestamps, type. */
    public function test_create_typed_lead_persists_with_required_fields(): void
    {
        $id = self::$leads->createLead(
            $this->companyA,
            'broker',
            ['email' => 'jane@brokerco.com', 'phone' => '(555) 123-4567', 'name' => 'Jane Broker'],
            1
        );

        $this->assertGreaterThan(0, $id, 'Lead create must return a valid ID (FR-008).');

        $row = self::$m->query('SELECT * FROM leads WHERE id = ' . (int) $id)->fetch_assoc();
        $this->assertNotFalse($row, 'Lead must be persisted (FR-008).');
        $this->assertSame('broker', $row['lead_type'], 'Lead type must be stored (FR-008).');
        $this->assertSame((string) $this->companyA, $row['company_id'], 'Lead must be tenant-scoped (FR-042).');
        $this->assertSame('new', $row['status'], 'New lead starts in "new" status.');
        $this->assertNotNull($row['created_at'], 'created_at timestamp required (FR-008).');
        $this->assertNotNull($row['updated_at'], 'updated_at timestamp required (FR-008).');
    }

    /** FR-008: all five lead types are accepted (carrier/broker/shipper/general/document). */
    public function test_create_accepts_all_five_lead_types(): void
    {
        $types = ['carrier', 'broker', 'shipper', 'general', 'document'];
        foreach ($types as $type) {
            $id = self::$leads->createLead($this->companyA, $type, ['name' => 'X'], 1);
            $this->assertGreaterThan(0, $id, "Lead type {$type} must be creatable (FR-008).");
            $row = self::$m->query('SELECT lead_type FROM leads WHERE id = ' . (int) $id)->fetch_assoc();
            $this->assertSame($type, $row['lead_type']);
        }
    }

    /** FR-008: invalid lead type rejected (server-side validation). */
    public function test_create_rejects_invalid_lead_type(): void
    {
        $this->expectException(\RuntimeException::class);
        self::$leads->createLead($this->companyA, 'nope', ['name' => 'X'], 1);
    }

    /** FR-008 acceptance: lead links to the immutable raw submission reference. */
    public function test_lead_links_raw_source_reference(): void
    {
        $srcId = self::$sources->create(
            $this->companyA,
            'web_form',
            'ext-001',
            hash('sha256', 'raw payload'),
            'raw payload bytes',
            'raw payload bytes',
            'text/plain',
            null
        );
        $this->assertGreaterThan(0, $srcId, 'Source record must be created (FR-014).');

        $leadId = self::$leads->createLead(
            $this->companyA,
            'general',
            ['email' => 'a@b.com', 'name' => 'Bob'],
            1,
            $srcId
        );

        $row = self::$m->query('SELECT raw_source_id, source FROM leads WHERE id = ' . (int) $leadId)->fetch_assoc();
        $this->assertSame((string) $srcId, $row['raw_source_id'], 'Lead must link to raw source (FR-008/FR-014).');
    }

    /** FR-008/FR-029: lead creation emits an audit event. */
    public function test_create_lead_emits_audit_event(): void
    {
        $before = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='lead.create'")->fetch_column();
        self::$leads->createLead($this->companyA, 'shipper', ['email' => 's@ship.com', 'name' => 'S'], 1);
        $after = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='lead.create'")->fetch_column();
        $this->assertSame($before + 1, $after, 'Lead creation must be audited (FR-029).');
    }

    /** FR-042: a lead created in company A is invisible to company B. */
    public function test_lead_isolation_across_tenants(): void
    {
        $id = self::$leads->createLead($this->companyA, 'carrier', ['email' => 'c@c.com', 'name' => 'C'], 1);
        $idsB = array_column(self::$leads->listForCompany($this->companyB), 'id');
        $this->assertNotContains((int) $id, $idsB, 'Other tenant must not see this lead (SEC-010/FR-042).');
        $idsA = array_column(self::$leads->listForCompany($this->companyA), 'id');
        $this->assertContains((int) $id, $idsA, 'Owning tenant sees its lead.');
    }

    /** FR-009: same normalized email across two leads flags the second as a possible duplicate. */
    public function test_duplicate_email_flagged_as_possible_duplicate(): void
    {
        $first = self::$leads->createLead($this->companyA, 'broker', ['email' => 'Jane@BrokerCo.com', 'name' => 'Jane Broker'], 1);
        $second = self::$leads->createLead($this->companyA, 'broker', ['email' => 'jane@brokerco.com', 'phone' => '(555) 999-0000', 'name' => 'Jane B'], 2);

        $flaggedAgainst = self::$leads->flagDuplicate($this->companyA, $second, ['email' => 'jane@brokerco.com', 'phone' => '(555) 999-0000', 'name' => 'Jane B']);

        $this->assertSame($first, $flaggedAgainst, 'Second lead flagged against the first (FR-009).');

        $row = self::$m->query('SELECT dup_status, dup_of_lead_id FROM leads WHERE id = ' . (int) $second)->fetch_assoc();
        $this->assertSame('possible_duplicate', $row['dup_status'], 'Second lead marked possible_duplicate (FR-009).');
        $this->assertSame((string) $first, $row['dup_of_lead_id'], 'Links to the existing lead for review.');
    }

    /** FR-009 acceptance: flagged duplicates are REVIEWABLE, never auto-merged. Both rows remain. */
    public function test_duplicate_flag_is_reviewable_not_destructive(): void
    {
        $first = self::$leads->createLead($this->companyA, 'shipper', ['email' => 'ship@x.com', 'name' => 'Ship'], 1);
        $second = self::$leads->createLead($this->companyA, 'shipper', ['email' => 'ship@x.com', 'name' => 'Ship Co'], 2);
        self::$leads->flagDuplicate($this->companyA, $second, ['email' => 'ship@x.com', 'name' => 'Ship Co']);

        // Both leads still exist as independent rows (no destructive merge).
        $count = (int) self::$m->query('SELECT COUNT(*) FROM leads WHERE company_id = ' . (int) $this->companyA . ' AND deleted_at IS NULL')->fetch_column();
        $this->assertSame(2, $count, 'No auto-merge: both lead rows survive (FR-009).');

        // The second is surfaced in the review queue via dup_status.
        $review = array_filter(
            self::$leads->listForCompany($this->companyA),
            static fn(array $l): bool => $l['dup_status'] === 'possible_duplicate'
        );
        $this->assertCount(1, $review, 'Exactly one lead awaits duplicate review.');
        $reviewRow = array_values($review)[0];
        $this->assertSame('possible_duplicate', $reviewRow['dup_status']);
        $this->assertEquals($first, $reviewRow['dup_of_lead_id'], 'Links to the existing lead for review.');
    }

    /** FR-009 acceptance: distinct leads with no shared identifier are NOT flagged (conservative). */
    public function test_distinct_leads_not_flagged(): void
    {
        self::$leads->createLead($this->companyA, 'general', ['email' => 'a@a.com', 'name' => 'Alpha'], 1);
        $second = self::$leads->createLead($this->companyA, 'general', ['email' => 'b@b.com', 'name' => 'Beta'], 2);

        $flaggedAgainst = self::$leads->flagDuplicate($this->companyA, $second, ['email' => 'b@b.com', 'name' => 'Beta']);
        $this->assertNull($flaggedAgainst, 'No shared identifier => not flagged (conservative FR-009).');

        $row = self::$m->query('SELECT dup_status FROM leads WHERE id = ' . (int) $second)->fetch_assoc();
        $this->assertSame('none', $row['dup_status'], 'Distinct lead stays unflagged.');
    }

    /** FR-009/FR-042: a duplicate identifier in a DIFFERENT tenant is NOT flagged (tenant isolation). */
    public function test_duplicate_email_in_other_tenant_not_flagged(): void
    {
        self::$leads->createLead($this->companyA, 'broker', ['email' => 'shared@broker.com', 'name' => 'Sam'], 1);
        $second = self::$leads->createLead($this->companyB, 'broker', ['email' => 'shared@broker.com', 'name' => 'Sam'], 2);

        $flaggedAgainst = self::$leads->flagDuplicate($this->companyB, $second, ['email' => 'shared@broker.com', 'name' => 'Sam']);
        $this->assertNull($flaggedAgainst, 'Same identifier in different tenant must NOT flag (FR-042).');

        $row = self::$m->query('SELECT dup_status FROM leads WHERE id = ' . (int) $second)->fetch_assoc();
        $this->assertSame('none', $row['dup_status']);
    }

    /** FR-009: phone normalization collapses formatting so (555) 123-4567 == 5551234567. */
    public function test_phone_normalization_matches_across_formats(): void
    {
        $first = self::$leads->createLead($this->companyA, 'carrier', ['phone' => '(555) 123-4567', 'name' => 'P One'], 1);
        $second = self::$leads->createLead($this->companyA, 'carrier', ['phone' => '5551234567', 'name' => 'P One'], 2);

        $flaggedAgainst = self::$leads->flagDuplicate($this->companyA, $second, ['phone' => '5551234567', 'name' => 'P One']);
        $this->assertSame($first, $flaggedAgainst, 'Normalized phone match flags duplicate (FR-009).');
    }
}
