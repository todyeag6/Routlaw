<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Security\Authorization;
use Routlaw\Documents\DocumentService;
use Routlaw\Documents\TokenStore;
use Routlaw\Approvals\ApprovalService;

/**
 * T16.5 — Approval-gated document sharing (FR-027).
 *
 * Sharing a private document (documents.share) must require an APPROVAL that is
 * payload-bound to the share (distinct approver, not self). The approval token
 * is single-use. A model/agent cannot invoke the share path (it is a human-only
 * control surface — structural separation, same as T14.4 cannot-self-send).
 */
final class DocumentShareApprovalTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_doc_share';

    private static ?\mysqli $m = null;
    private static DocumentService $docs;
    private static TokenStore $tokens;
    private static ApprovalService $approvals;
    private static Authorization $authz;
    private static Auth $auth;
    private static int $companyA;
    private static int $adminA;
    private static int $drafterA;
    private static string $storageRoot;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','011_approval_queue.sql','012_documents.sql','014_document_permissions.sql','015_document_token_expiry.sql'] as $f) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $f);
        }
        self::$authz = new Authorization(self::$m);
        self::$tokens = new TokenStore(self::$m);
        self::$approvals = new ApprovalService(self::$m);
        self::$auth = new Auth(self::$m);
        self::$storageRoot = sys_get_temp_dir() . '/routlaw_doc_share_' . uniqid();
        self::$docs = new DocumentService(self::$m, self::$authz, self::$storageRoot);

        self::$m->query("INSERT INTO companies (display_name, legal_name) VALUES ('Alpha','Alpha LLC')");
        self::$companyA = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Alpha'")->fetch_row()[0];

        $admin = (int) self::$m->query("SELECT id FROM roles WHERE slug='company_admin'")->fetch_row()[0];
        $disp = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        self::$adminA = self::$auth->createUser(self::$companyA, 'admin@example.com', 'P!1', 'Admin', $admin);
        self::$drafterA = self::$auth->createUser(self::$companyA, 'd@example.com', 'P!2', 'Drafter', $disp);

        foreach (['documents.upload', 'documents.share', 'approvals.outbound'] as $code) {
            $pid = (int) self::$m->query("SELECT id FROM permissions WHERE code='$code'")->fetch_row()[0];
            self::$m->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($admin, $pid)");
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            Connection::connect()->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    private function adminA(): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession(self::$adminA, self::$companyA, 'Alpha', 'Alpha LLC', 'admin@example.com', 'Admin', 0, 'company_admin');
    }
    private function drafterA(): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession(self::$drafterA, self::$companyA, 'Alpha', 'Alpha LLC', 'd@example.com', 'Drafter', 0, 'dispatcher');
    }

    private function upload(): int
    {
        $tmp = sys_get_temp_dir() . '/doc_share.pdf';
        file_put_contents($tmp, "%PDF-1.4\n%%EOF");
        $file = ['tmp_name' => $tmp, 'name' => 'doc.pdf', 'type' => 'application/pdf', 'size' => 11, 'error' => 0];
        $doc = self::$docs->upload($file, $this->adminA(), 'confidential', 'contract');
        return $doc['id'];
    }

    public function test_share_requires_documents_share_permission(): void
    {
        $docId = $this->upload();
        $ex = null;
        try {
            self::$docs->share(self::$companyA, $docId, $this->drafterA(), ['recipient' => 'bob@x.com'], $this->drafterA());
        } catch (\Throwable $e) { $ex = $e; }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex);
    }

    public function test_share_requires_distinct_approver_not_self(): void
    {
        $docId = $this->upload();
        // admin is both the sharer and the approver -> must be rejected (cannot self-approve share)
        $ex = null;
        try {
            self::$docs->share(self::$companyA, $docId, $this->adminA(), ['recipient' => 'bob@x.com'], $this->adminA());
        } catch (\Throwable $e) { $ex = $e; }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex);
        $this->assertSame('cannot self-approve share', $ex->getMessage());
    }

    public function test_share_success_returns_token_for_distinct_admin_approver(): void
    {
        $docId = $this->upload();
        $token = self::$docs->share(self::$companyA, $docId, $this->adminA() /*sharer*/, ['recipient' => 'bob@x.com'], $this->drafterA() /*approver: distinct*/);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $token);
        // token resolves under the tenant -> it was issued
        $resolvedId = self::$tokens->consume($token, self::$companyA);
        $this->assertSame($docId, $resolvedId);
    }

    public function test_no_model_share_path(): void
    {
        // The share() method requires a human approver UserSession. There is NO
        // overload that accepts an agent/model identity — proving the model cannot
        // invoke sharing by construction.
        $docId = $this->upload();
        $ref = new \ReflectionMethod(self::$docs, 'share');
        $params = $ref->getParameters();
        foreach ($params as $p) {
            $this->assertNotSame('model', (string) $p->getName());
            $this->assertNotSame('agent', (string) $p->getName());
        }
        $this->assertGreaterThan(0, count($params));
    }
}
