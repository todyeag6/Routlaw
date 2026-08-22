<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Security\Auth;
use Routlaw\Security\Authorization;
use Routlaw\Documents\DocumentService;

/**
 * T16.2 — DocumentService::upload (FR-025/026/027/028).
 * MIME + signature check, randomized sodium storage key, no executable
 * content, non-web storage path, classification-as-label.
 */
final class DocumentServiceUploadTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_doc_svc';

    private static ?\mysqli $m = null;
    private static DocumentService $svc;
    private static Auth $auth;
    private static Authorization $authz;
    private static int $companyA;
    private static int $adminA;
    private static int $adminB;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','012_documents.sql','014_document_permissions.sql'] as $f) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $f);
        }
        self::$auth = new Auth(self::$m);
        self::$authz = new Authorization(self::$m);
        // use a temp storage root that lives OUTSIDE the docroot
        $root = sys_get_temp_dir() . '/routlaw_doc_test_' . uniqid();
        self::$svc = new DocumentService(self::$m, self::$authz, $root);

        self::$m->query("INSERT INTO companies (display_name, legal_name) VALUES ('Alpha','Alpha LLC')");
        self::$companyA = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Alpha'")->fetch_row()[0];
        self::$m->query("INSERT INTO companies (display_name, legal_name) VALUES ('Bravo','Bravo LLC')");
        $companyB = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Bravo'")->fetch_row()[0];

        $admin = (int) self::$m->query("SELECT id FROM roles WHERE slug='company_admin'")->fetch_row()[0];
        self::$adminA = self::$auth->createUser(self::$companyA, 'admin@example.com', 'Pass!1', 'Admin', $admin);
        self::$adminB = self::$auth->createUser($companyB, 'badmin@example.com', 'Pass!2', 'BAdmin', $admin);

        // grant documents.upload + documents.share to company_admin
        foreach (['documents.upload', 'documents.share'] as $code) {
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
    private function adminB(): \Routlaw\Security\UserSession
    {
        return new \Routlaw\Security\UserSession(self::$adminB, 2 /*companyB*/, 'Bravo', 'Bravo LLC', 'badmin@example.com', 'BAdmin', 0, 'company_admin');
    }

    private function writeTmp(string $name, string $contents, string $mime): array
    {
        $tmp = sys_get_temp_dir() . '/' . $name;
        file_put_contents($tmp, $contents);
        return ['tmp_name' => $tmp, 'name' => $name, 'type' => $mime, 'size' => strlen($contents), 'error' => 0];
    }

    public function test_upload_requires_documents_upload_permission(): void
    {
        // create a non-admin (dispatcher) user who lacks documents.upload
        $disp = (int) self::$m->query("SELECT id FROM roles WHERE slug='dispatcher'")->fetch_row()[0];
        $uid = self::$auth->createUser(self::$companyA, 'd@example.com', 'P!1', 'D', $disp);
        $d = new \Routlaw\Security\UserSession($uid, self::$companyA, 'Alpha', 'Alpha LLC', 'd@example.com', 'D', 0, 'dispatcher');
        $file = $this->writeTmp('ok.txt', 'hello', 'text/plain');
        $ex = null;
        try {
            self::$svc->upload($file, $d, 'internal', 'note');
        } catch (\Throwable $e) { $ex = $e; }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex);
    }

    public function test_upload_rejects_executable_content(): void
    {
        // even with a spoofed .txt name, PHP content must be rejected on signature/MIME
        $file = $this->writeTmp('shell.txt', '<?php system($_GET["c"]); ?>', 'application/x-php');
        $ex = null;
        try {
            self::$svc->upload($file, $this->adminA(), 'internal', 'note');
        } catch (\Throwable $e) { $ex = $e; }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex);
    }

    public function test_upload_rejects_disallowed_mime(): void
    {
        $file = $this->writeTmp('bad.exe', 'MZ' . str_repeat("\x00", 100), 'application/x-msdownload');
        $ex = null;
        try {
            self::$svc->upload($file, $this->adminA(), 'internal', 'note');
        } catch (\Throwable $e) { $ex = $e; }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex);
    }

    public function test_upload_rejects_php_tag_even_if_text_plain(): void
    {
        // text/plain that contains a php open tag is still executable content
        $file = $this->writeTmp('tricky.txt', '<?php echo 1; ?>', 'text/plain');
        $ex = null;
        try {
            self::$svc->upload($file, $this->adminA(), 'internal', 'note');
        } catch (\Throwable $e) { $ex = $e; }
        $this->assertInstanceOf(\Routlaw\Security\AuthorizationException::class, $ex);
    }

    public function test_upload_accepts_pdf_and_stores_outside_docroot(): void
    {
        // minimal PDF magic bytes
        $pdf = "%PDF-1.4\n" . str_repeat('x', 20) . "\n%%EOF";
        $file = $this->writeTmp('doc.pdf', $pdf, 'application/pdf');
        $doc = self::$svc->upload($file, $this->adminA(), 'confidential', 'lead 7 contract');
        $this->assertGreaterThan(0, $doc['id']);
        $this->assertSame('confidential', $doc['classification']);
        $this->assertSame('lead 7 contract', $doc['human_verification_note']);
        // randomized key: 64 hex chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $doc['storage_key']);
        // file on disk is NOT under a web root: no path contains 'public' or 'htdocs'
        $path = $doc['file_path'];
        $this->assertStringNotContainsString('public', $path);
        $this->assertStringNotContainsString('htdocs', $path);
        // stored mime verified
        $row = self::$m->query("SELECT mime_type, file_size FROM documents WHERE id={$doc['id']}")->fetch_assoc();
        $this->assertSame('application/pdf', $row['mime_type']);
        $this->assertGreaterThan(0, (int) $row['file_size']);
        @unlink($path);
    }

    public function test_tenant_bucket_isolation(): void
    {
        // The storage path is derived from the ACTOR's company_id, so a user in
        // companyB can never write into companyA's bucket — isolation by construction.
        $file = $this->writeTmp('iso.pdf', "%PDF-1.4\n%%EOF", 'application/pdf');
        $docA = self::$svc->upload($file, $this->adminA(), 'internal', 'a contract');
        $docB = self::$svc->upload($file, $this->adminB(), 'internal', 'b contract');

        $dirA = dirname($docA['file_path']);
        $dirB = dirname($docB['file_path']);
        // distinct tenant buckets
        $this->assertNotSame($dirA, $dirB);
        // each bucket is the company documents/ subdir (storage/documents/<cid>)
        $this->assertMatchesRegularExpression('#storage/documents/' . (string) self::$companyA . '$#', $dirA);
        $brivo = (int) self::$m->query("SELECT id FROM companies WHERE display_name='Bravo'")->fetch_row()[0];
        $this->assertMatchesRegularExpression('#storage/documents/' . (string) $brivo . '$#', $dirB);
        // never under web docroot
        $this->assertStringNotContainsString('public', $dirA);
        $this->assertStringNotContainsString('htdocs', $dirB);

        @unlink($docA['file_path']);
        @unlink($docB['file_path']);
    }
}
