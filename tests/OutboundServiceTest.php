<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Security\Authorization;
use Routlaw\Messages\MessageDraftService;
use Routlaw\Outgoing\OutboundService;
use Routlaw\Approvals\ApprovalService;

/**
 * T14.4 — OutboundService::approveAndSend (FR-021, FR-022/023/024, FR-042).
 *
 * The ONLY path that can flip a draft to "sent" and invoke the Mailer. It:
 *  - requires the approvals.outbound permission
 *  - requires a distinct approver (cannot self-send)
 *  - drafts a payload-bound approval that must be approved first
 *  - consumes the approval on send (single-use)
 */
final class OutboundServiceTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_outbound';

    private static ?\mysqli $m = null;
    private static Auth $auth;
    private static Authorization $authz;
    private static MessageDraftService $drafts;
    private static OutboundService $svc;
    private static ApprovalService $approvals;
    private static int $companyA;
    private static int $companyB;
    private static int $drafterA;
    private static int $adminA;
    private static int $userB;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','002_carriers.sql','005_leads_source_records.sql','006_loads.sql','008_gate_results.sql','009_decision_cases.sql','010_message_drafts.sql','011_approval_queue.sql','013_message_drafts_sent.sql'] as $f) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $f);
        }
        self::$auth = new Auth(self::$m);
        self::$authz = new Authorization(self::$m);
        self::$drafts = new MessageDraftService(self::$m);
        self::$approvals = new ApprovalService(self::$m);
        self::$svc = new OutboundService(self::$m, self::$authz, self::$drafts, self::$approvals, new DummyMailer());

        self::$m->query("INSERT INTO companies (display_name, legal_name) VALUES ('Alpha','Alpha LLC')");
        self::$companyA = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Alpha'")->fetch_row()[0];
        self::$m->query("INSERT INTO companies (display_name, legal_name) VALUES ('Bravo','Bravo LLC')");
        self::$companyB = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Bravo'")->fetch_row()[0];

        $disp = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        $admin = (int) self::$m->query("SELECT id FROM roles WHERE slug='company_admin'")->fetch_row()[0];
        self::$drafterA = self::$auth->createUser(self::$companyA, 'drafter@example.com', 'Pass!1', 'Drafter', $disp);
        self::$adminA = self::$auth->createUser(self::$companyA, 'admin@example.com', 'Pass!2', 'Admin', $admin);
        self::$userB = self::$auth->createUser(self::$companyB, 'bravo@example.com', 'Pass!3', 'Bravo', $disp);

        // seed 4 leads in companyA (source-link targets for drafts 1-4)
        self::$m->query("INSERT INTO leads (company_id, lead_type, source, status, normalized_email) VALUES (" . self::$companyA . ",'carrier','manual','new','src1@example.com')");
        self::$m->query("INSERT INTO leads (company_id, lead_type, source, status, normalized_email) VALUES (" . self::$companyA . ",'carrier','manual','new','src2@example.com')");
        self::$m->query("INSERT INTO leads (company_id, lead_type, source, status, normalized_email) VALUES (" . self::$companyA . ",'carrier','manual','new','src3@example.com')");
        self::$m->query("INSERT INTO leads (company_id, lead_type, source, status, normalized_email) VALUES (" . self::$companyA . ",'carrier','manual','new','src4@example.com')");

        // grant approvals.outbound to the company_admin role globally (role_permissions
        // is role-scoped, not company-scoped; tenant enforcement happens at the query layer).
        $permId = (int) self::$m->query("SELECT id FROM permissions WHERE code='approvals.outbound'")->fetch_row()[0];
        self::$m->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($admin, $permId)");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            Connection::connect()->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    private function actorA(): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession(self::$drafterA, self::$companyA, 'Alpha', 'Alpha LLC', 'drafter@example.com', 'Drafter', 0, 'dispatcher');
    }
    private function adminActorA(): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession(self::$adminA, self::$companyA, 'Alpha', 'Alpha LLC', 'admin@example.com', 'Admin', 0, 'company_admin');
    }
    private function userActorB(): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession(self::$userB, self::$companyB, 'Bravo', 'Bravo LLC', 'bravo@example.com', 'Bravo', 0, 'dispatcher');
    }

    public function test_send_requires_approvals_outbound_permission(): void
    {
        // drafter (dispatcher) lacks approvals.outbound -> must be refused
        $draftId = self::$drafts->create($this->actorA(), 'lead', 1, 'Hi carrier', 'body');
        $ex = null;
        try {
            self::$svc->approveAndSend(self::$companyA, $draftId, $this->actorA(), $this->actorA(), ['to' => 'c@x.com', 'amount' => '100']);
        } catch (\Throwable $e) {
            $ex = $e;
        }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex, 'dispatcher must be denied outbound send');
        $this->assertSame('denied: approvals.outbound', $ex->getMessage());
    }

    public function test_send_requires_distinct_approver_not_drafter(): void
    {
        // An admin (who has approvals.outbound) drafts and attempts to send their own draft.
        // Passes the permission gate but must be rejected at the cannot-self-send guard.
        $draftId = self::$drafts->create($this->adminActorA(), 'lead', 2, 'Distinct', 'body');
        $payload = ['to' => 'c@x.com', 'amount' => '200'];
        // drafter tries to approve+send their own draft -> forbidden (cannot self-send)
        $ex = null;
        try {
            self::$svc->approveAndSend(self::$companyA, $draftId, $this->adminActorA(), $this->adminActorA(), $payload);
        } catch (\Throwable $e) {
            $ex = $e;
        }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex);
        $this->assertSame('cannot self-send', $ex->getMessage());
    }

    public function test_approve_and_send_succeeds_with_distinct_admin_approver(): void
    {
        $draftId = self::$drafts->create($this->actorA(), 'lead', 3, 'Approved send', 'body');
        $payload = ['to' => 'c@x.com', 'amount' => '300'];
        $sent = self::$svc->approveAndSend(self::$companyA, $draftId, $this->adminActorA(), $this->actorA(), $payload);
        $this->assertTrue($sent, 'admin approver sends draft for drafter');
        // draft must be marked sent
        $row = self::$m->query("SELECT status FROM message_drafts WHERE id={$draftId}")->fetch_assoc();
        $this->assertSame('sent', $row['status']);
    }

    public function test_cross_tenant_approver_rejected(): void
    {
        // adminB is in companyB but trying to send a companyA draft -> cross-tenant
        $draftId = self::$drafts->create($this->actorA(), 'lead', 4, 'X tenant', 'body');
        $payload = ['to' => 'c@x.com', 'amount' => '400'];
        // adminB belongs to companyB; calling approveAndSend on companyA's draft
        // is rejected at the canAccessTenant check (cross-tenant).
        $adminB = self::$auth->createUser(self::$companyB, 'badmin@example.com', 'Pass!9', 'BAdmin', (int) self::$m->query("SELECT id FROM roles WHERE slug='company_admin'")->fetch_row()[0]);
        $bAdmin = new \Routlaw\Security\UserSession($adminB, self::$companyB, 'Bravo', 'Bravo LLC', 'badmin@example.com', 'BAdmin', 0, 'company_admin');
        $ex = null;
        try {
            self::$svc->approveAndSend(self::$companyA, $draftId, $bAdmin, $this->actorA(), $payload);
        } catch (\Throwable $e) {
            $ex = $e;
        }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex);
    }
}

/**
 * Fake Mailer for tests — records sends without touching SMTP.
 */
final class DummyMailer implements \Routlaw\Mail\MailerInterface
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $sent = [];
    public function send(string $to, string $subject, string $body, string $toName = ''): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        return true;
    }
}
