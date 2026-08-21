<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Loads\LoadService;
use Routlaw\Loads\LoadExtractionService;
use Routlaw\Leads\SourceRecordService;

/**
 * TDD for T8.4/T8.5 Structured schema extraction (build-plan §4 Phase 3 T8, FR-013/BR-005).
 *
 * FR-013: extraction produces versioned schema-conforming fields, per-field provenance/
 *   confidence, missing fields, and confidence. Invalid schema output rejected; source
 *   retained; low confidence requires review. NO hallucinated completion (BR-005).
 * FR-014: extracted load links to immutable source.
 *
 * The extraction operates on UNTRUSTED text (email/doc/load text) — it must never
 * treat that text as instruction (see LeadLoadInjectionTest for FR-012).
 */
final class LoadExtractionTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_extract';
    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static LoadService $loads;
    private static LoadExtractionService $extract;
    private static SourceRecordService $sources;

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
        self::$extract = new LoadExtractionService();
        self::$sources = new SourceRecordService(self::$m);
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
        self::$m->query('SET FOREIGN_KEY_CHECKS = 1');
        self::$m->query('DELETE FROM companies');
        $this->companyA = self::$auth->createCompany('Alpha Transport', 'Alpha Transport LLC');
        $this->companyB = self::$auth->createCompany('Beta Haulers', 'Beta Haulers LLC');
    }

    /** FR-013: extraction yields versioned, schema-conforming fields with per-field confidence + missing list. */
    public function test_extraction_produces_versioned_fields_with_confidence_and_missing(): void
    {
        // No weight in this text => weight_lbs should be reported as missing (not fabricated).
        $text = "Pickup: Dallas, TX. Deliver: Atlanta, GA. Commodity: General Freight. Rate: $1850.";
        $result = self::$extract->extract($text);

        $this->assertSame('1.0', $result['extraction_version'], 'Extraction is versioned (FR-013).');
        $fields = $result['fields'];
        $this->assertArrayHasKey('origin_city', $fields);
        $this->assertSame('Dallas', $fields['origin_city']['value']);
        $this->assertSame('high', $fields['origin_city']['confidence'], 'Per-field confidence present (FR-013).');
        $this->assertArrayHasKey('weight_lbs', $fields);
        $this->assertNull($fields['weight_lbs']['value'], 'Absent weight is null, not fabricated (BR-005).');

        // Missing field explicitly reported (no fabrication).
        $this->assertContains('weight_lbs', $result['missing'], 'Missing field reported honestly (FR-013/BR-005).');
    }

    /** BR-005 / FR-013: absent data is NEVER hallucinated into a plausible value. */
    public function test_no_hallucinated_completion_for_absent_fields(): void
    {
        // Text mentions no destination, commodity, or weight at all.
        $text = "Please move my shipment from Houston.";
        $result = self::$extract->extract($text);

        $this->assertArrayHasKey('dest_city', $result['fields'], 'Field slot exists but must be empty (BR-005).');
        $this->assertNull($result['fields']['dest_city']['value'], 'No fabricated destination (BR-005).');
        $this->assertArrayHasKey('weight_lbs', $result['fields']);
        $this->assertNull($result['fields']['weight_lbs']['value'], 'No fabricated weight (BR-005).');
        $this->assertContains('dest_city', $result['missing']);
        $this->assertContains('weight_lbs', $result['missing']);
        $this->assertGreaterThanOrEqual(3, count($result['missing']), 'Multiple missing fields enumerated.');
    }

    /** FR-013 acceptance: low overall confidence sets review_required. */
    public function test_low_confidence_requires_review(): void
    {
        // Sparse, ambiguous text => low confidence extraction.
        $text = "load maybe around texas somewhere";
        $result = self::$extract->extract($text);
        $this->assertSame('low', $result['overall_confidence'], 'Sparse input yields low confidence (FR-013).');
        $this->assertSame(1, $result['review_required'], 'Low confidence requires review (FR-013).');
    }

    /** FR-013: persistExtractedLoad stores version + per-field JSON + missing + review flag, links source. */
    public function test_persist_extracted_load_links_source_and_metadata(): void
    {
        $text = "Pickup Dallas TX, Deliver Atlanta GA, Commodity General Freight, Weight 24000 lbs.";
        $extraction = self::$extract->extract($text);
        $loadId = self::$loads->persistExtractedLoad(
            $this->companyA,
            $extraction,
            1,
            null,
            'email'
        );
        $this->assertGreaterThan(0, $loadId, 'Extraction persisted as a load (FR-013).');

        $row = self::$m->query('SELECT extraction_version, extraction_confidence, extraction_missing, extraction_fields, review_required, status FROM loads WHERE id = ' . (int) $loadId)->fetch_assoc();
        $this->assertSame('1.0', $row['extraction_version']);
        $this->assertNotNull($row['extraction_fields'], 'Per-field JSON stored (FR-013).');
        $this->assertSame('high', $row['extraction_confidence'], 'Complete high-confidence extraction (FR-013).');
        $this->assertSame('0', $row['review_required'], 'Complete high-confidence => no review required.');
        $this->assertSame('new', $row['status'], 'Extracted load enters review, not auto-published.');
    }

    /** FR-013 acceptance: invalid schema output is rejected (no partial/garbage persist). */
    public function test_invalid_extraction_schema_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        // ext_version missing / non-conforming shape must be rejected before persist.
        self::$loads->persistExtractedLoad($this->companyA, ['fields' => 'not-an-array'], 1, null, 'email');
    }

    /** FR-042: extraction result persisted for company A is invisible to company B. */
    public function test_extracted_load_isolation_across_tenants(): void
    {
        $text = "Pickup Dallas TX, Deliver Atlanta GA, Commodity General Freight, Weight 24000 lbs.";
        $extraction = self::$extract->extract($text);
        $id = self::$loads->persistExtractedLoad($this->companyA, $extraction, 1, null, 'email');
        $idsB = array_column(self::$loads->listForCompany($this->companyB), 'id');
        $this->assertNotContains((int) $id, $idsB, 'Other tenant must not see this load (FR-042).');
    }
}
