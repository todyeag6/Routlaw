<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Messages\MessageDraftService;
use Routlaw\Security\Auth;
use Routlaw\Security\AuditLog;

/**
 * T14.2 — MessageDraftService CRUD (FR-021).
 * Source-linked, editable, tenant-scoped, NEVER self-sends (no Mailer coupling).
 * TDD: fails until MessageDraftService exists.
 */
final class MessageDraftServiceTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_msgservice';

    private static ?\mysqli $m = null;
    private static MessageDraftService $svc;
    private static Auth $auth;
    private static int $companyA;
    private static int $companyB;
    private static int $userA;
    private static int $leadA;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','002_carriers.sql','005_leads_source_records.sql','006_loads.sql','008_gate_results.sql','009_decision_cases.sql','010_message_drafts.sql'] as $f) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $f);
        }
        self::$svc = new MessageDraftService(self::$m);
        self::$auth = new Auth(self::$m);

        // seed two tenants via Auth (idempotent-ish per test DB)
        $companyA = self::$m->query("SELECT id FROM companies WHERE display_name='Alpha'")->fetch_row();
        self::$companyA = $companyA ? (int)$companyA[0] : self::$auth->createCompany('Alpha', 'Alpha LLC');
        $companyB = self::$m->query("SELECT id FROM companies WHERE display_name='Beta'")->fetch_row();
        self::$companyB = $companyB ? (int)$companyB[0] : self::$auth->createCompany('Beta', 'Beta LLC');
        $role = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        $ua = self::$m->query("SELECT id FROM users WHERE email='alice@example.com'")->fetch_row();
        self::$userA = $ua ? (int)$ua[0] : self::$auth->createUser(self::$companyA, 'alice@example.com', 'Pass!123', 'Alice', $role);
        // a lead in company A
        self::$m->query("INSERT INTO leads (company_id, source, status) VALUES (" . self::$companyA . ", 'manual_test', 'new')");
        self::$leadA = (int) self::$m->query("SELECT id FROM leads WHERE company_id=" . self::$companyA)->fetch_row()[0];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            Connection::connect()->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    private function actor(): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession(self::$userA, self::$companyA, 'Alpha', 'Alpha LLC', 'alice@example.com', 'Alice', 0, 'dispatcher');
    }

    public function test_create_draft_source_linked_tenant_scoped(): void
    {
        $id = self::$svc->create($this->actor(), 'lead', self::$leadA, 'Re: your inquiry', 'Please review the attached.');
        $this->assertGreaterThan(0, $id);
        $row = self::$svc->get($id, self::$companyA);
        $this->assertNotNull($row, 'created draft must be readable in same tenant');
        $this->assertSame('lead', $row['source_type']);
        $this->assertSame(self::$leadA, $row['source_id']);
        $this->assertSame('draft', $row['status']);
    }

    public function test_update_edits_subject_and_body(): void
    {
        $id = self::$svc->create($this->actor(), 'lead', self::$leadA, 'orig subj', 'orig body');
        self::$svc->update($id, self::$companyA, $this->actor(), 'new subj', 'new body');
        $row = self::$svc->get($id, self::$companyA);
        $this->assertSame('new subj', $row['subject']);
        $this->assertSame('new body', $row['body']);
    }

    public function test_source_link_validates_in_tenant(): void
    {
        // lead that belongs to company B — must be rejected for company A actor.
        $this->expectException(\RuntimeException::class);
        self::$svc->create($this->actor(), 'lead', 999999, 'x', 'y');
    }

    public function test_list_scoped_to_company(): void
    {
        // one draft in company A
        self::$svc->create($this->actor(), 'lead', self::$leadA, 'a', 'b');
        $rows = self::$svc->listForTenant(self::$companyA);
        $this->assertGreaterThanOrEqual(1, count($rows));
        // cross-tenant list returns 0 for company B (no drafts there)
        $rowsB = self::$svc->listForTenant(self::$companyB);
        $this->assertSame(0, count($rowsB));
    }

    public function test_cross_tenant_get_returns_null(): void
    {
        $id = self::$svc->create($this->actor(), 'lead', self::$leadA, 's', 't');
        $this->assertNull(self::$svc->get($id, self::$companyB), 'cross-tenant read must leak nothing');
    }

    public function test_cross_tenant_update_does_not_mutate(): void
    {
        $id = self::$svc->create($this->actor(), 'lead', self::$leadA, 'keep', 'keep');
        $ok = self::$svc->update($id, self::$companyB, $this->actor(), 'hijack', 'hijack');
        $this->assertFalse($ok);
        $row = self::$svc->get($id, self::$companyA);
        $this->assertSame('keep', $row['subject']);
    }

    public function test_soft_delete_emits_audit(): void
    {
        $id = self::$svc->create($this->actor(), 'lead', self::$leadA, 'to', 'delete');
        $before = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='message_draft.delete' AND target_type='message_draft'")->fetch_column();
        self::$svc->softDelete($id, self::$companyA, $this->actor(), 'cleanup');
        $after = (int) self::$m->query("SELECT COUNT(*) FROM audit_events WHERE event_type='message_draft.delete' AND target_type='message_draft' AND target_id='{$id}'")->fetch_column();
        $this->assertGreaterThan($before, $after, 'soft-delete must emit audited event');
        $this->assertNull(self::$svc->get($id, self::$companyA), 'soft-deleted draft must be invisible');
    }

    /**
     * T14.3 (FR-021 "cannot self-send"): the drafting service MUST NOT import or
     * reference the outbound Mailer. Structural guard — the draft store has no
     * send capability by construction.
     */
    public function test_draft_service_has_no_mailer_coupling(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Messages/MessageDraftService.php');
        // Strip comments/docblocks — the contract is that executable code must not
        // import/invoke Mailer. Mentioning it in a NON-NEGOTIABLE docblock is fine.
        $code = preg_replace('#/\*.*?\*/#s', '', $src);
        $code = preg_replace('#//[^\n]*#', '', $code);
        $this->assertStringNotContainsString('Routlaw\\Mail\\Mailer', $code, 'MessageDraftService must not couple to Mailer (FR-021 cannot-self-send)');
        $this->assertStringNotContainsString('->send(', $code, 'MessageDraftService must have no send call (FR-021 cannot-self-send)');
        $this->assertStringNotContainsString('send(', $code, 'MessageDraftService must have no send path at all (FR-021)');
    }
}
