<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Approvals\ApprovalService;

/**
 * T15.2/T15.3 — ApprovalService (FR-022/023/024).
 * create() stores a canonical payload hash + expiry; isApproved() is valid only when
 * the stored hash matches the CURRENT live payload (tamper -> mismatch; replay -> consumed).
 */
final class ApprovalServiceTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_approval_svc';

    private static ?\mysqli $m = null;
    private static ApprovalService $svc;
    private static Auth $auth;
    private static int $companyA;
    private static int $userA;
    private static int $adminA;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql', '001_audit_events.sql', '010_message_drafts.sql', '011_approval_queue.sql'] as $f) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $f);
        }
        self::$svc = new ApprovalService(self::$m);
        self::$auth = new Auth(self::$m);

        self::$m->query("INSERT INTO companies (display_name, legal_name) VALUES ('Alpha','Alpha LLC')");
        self::$companyA = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Alpha'")->fetch_row()[0];
        $disp = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        $admin = (int) self::$m->query("SELECT id FROM roles WHERE slug='company_admin'")->fetch_row()[0];
        self::$userA = self::$auth->createUser(self::$companyA, 'alice@example.com', 'Pass!123', 'Alice', $disp);
        self::$adminA = self::$auth->createUser(self::$companyA, 'admin@example.com', 'Pass!456', 'Admin', $admin);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            Connection::connect()->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    private function payload(string $to = 'carrier@example.com', string $amt = '1200'): array
    {
        return ['to' => $to, 'amount' => $amt, 'currency' => 'USD'];
    }

    private function adminActor(): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession(self::$adminA, self::$companyA, 'Alpha', 'Alpha LLC', 'admin@example.com', 'Admin', 0, 'company_admin');
    }

    public function test_create_stores_pending_with_canonical_hash(): void
    {
        $p = $this->payload();
        $id = self::$svc->create(self::$companyA, self::$userA, 'message.send', 'message_draft', 1, $p, '+0 days');
        $this->assertGreaterThan(0, $id);

        $row = self::$m->query("SELECT status, payload_hash, expires_at FROM approval_requests WHERE id={$id}")->fetch_assoc();
        $this->assertSame('pending', $row['status']);

        // Canonical hash must be deterministic and match an independent recomputation.
        $canonical = ApprovalService::canonicalHash('message.send', 'message_draft', 1, $p);
        $this->assertSame($canonical, $row['payload_hash'], 'stored hash must equal the canonical payload hash');
        $this->assertNotEquals('', $row['payload_hash']);
    }

    public function test_canonical_hash_is_stable_for_same_payload(): void
    {
        $p = $this->payload();
        $h1 = ApprovalService::canonicalHash('message.send', 'message_draft', 1, $p);
        $h2 = ApprovalService::canonicalHash('message.send', 'message_draft', 1, $p);
        $this->assertSame($h1, $h2, 'identical payload must hash identically');
        // Different action -> different hash.
        $this->assertNotEquals($h1, ApprovalService::canonicalHash('document.share', 'message_draft', 1, $p));
    }

    public function test_is_approved_returns_false_for_pending(): void
    {
        $p = $this->payload();
        $id = self::$svc->create(self::$companyA, self::$userA, 'message.send', 'message_draft', 1, $p, '+1 day');
        $this->assertFalse(self::$svc->isApproved('message.send', 'message_draft', 1, $p, [self::$companyA]));
    }

    public function test_is_approved_true_after_approve_with_matching_payload(): void
    {
        $p = $this->payload('other@example.com', '990');
        $id = self::$svc->create(self::$companyA, self::$userA, 'message.send', 'message_draft', 2, $p, '+1 day');
        self::$svc->approve($id, self::$companyA, $this->adminActor(), $p);
        $this->assertTrue(self::$svc->isApproved('message.send', 'message_draft', 2, $p, [self::$companyA]), 'matching payload must be approved');
    }

    public function test_tampered_payload_rejected(): void
    {
        $p = $this->payload('tamper@example.com', '500');
        $id = self::$svc->create(self::$companyA, self::$userA, 'message.send', 'message_draft', 3, $p, '+1 day');
        self::$svc->approve($id, self::$companyA, $this->adminActor(), $p);

        $tampered = $p;
        $tampered['amount'] = '1500'; // changed after approval
        $this->assertFalse(self::$svc->isApproved('message.send', 'message_draft', 3, $tampered, [self::$companyA]), 'tampered payload must be rejected');
    }

    public function test_replay_of_consumed_approval_rejected(): void
    {
        $p = $this->payload('replay@example.com', '750');
        $id = self::$svc->create(self::$companyA, self::$userA, 'message.send', 'message_draft', 4, $p, '+1 day');
        self::$svc->approve($id, self::$companyA, $this->adminActor(), $p);

        // Consume once (simulates an apply).
        $this->assertTrue(self::$svc->consume('message.send', 'message_draft', 4, $p, [self::$companyA]));
        // Replay: second consume must fail (single-use).
        $this->assertFalse(self::$svc->consume('message.send', 'message_draft', 4, $p, [self::$companyA]), 'replay of consumed approval must be rejected');
    }

    public function test_expired_approval_rejected(): void
    {
        $p = $this->payload('expired@example.com', '300');
        $id = self::$svc->create(self::$companyA, self::$userA, 'message.send', 'message_draft', 5, $p, '-1 day');
        // Approve then advance expiry past (expired_at already in the past at create).
        self::$svc->approve($id, self::$companyA, $this->adminActor(), $p);
        $this->assertFalse(self::$svc->isApproved('message.send', 'message_draft', 5, $p, [self::$companyA]), 'expired approval must be rejected');
    }
}
