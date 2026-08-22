<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Db\Connection;
use Routlaw\Documents\TokenStore;

/**
 * T16.4 — Signed-access token for private documents (FR-025/026/027).
 *
 * A download_token is a single-use, expiry-bound, randomized token that the
 * public/doc.php endpoint validates BEFORE serving a file from outside the
 * web root. The token is never the storage key; it is an access grant.
 */
final class TokenStoreTest extends TestCase
{
    private const TEST_DB = 'routlaw_test_tokenstore';

    private static ?\mysqli $m = null;
    private static TokenStore $store;

    public static function setUpBeforeClass(): void
    {
        $base = Connection::connect();
        $base->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $base->query('CREATE DATABASE ' . self::TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$m = Connection::connect(self::TEST_DB);
        foreach (['000_init_foundation.sql','001_audit_events.sql','012_documents.sql','015_document_token_expiry.sql'] as $f) {
            Connection::applyFile(self::$m, __DIR__ . '/../migrations/' . $f);
        }
        // seed company 1 + a user (documents FK to companies.id + users.id)
        self::$m->query("INSERT INTO companies (display_name, legal_name) VALUES ('TestCo','Test Co')");
        $co = (int) self::$m->query("SELECT id FROM roles WHERE slug='company_admin'")->fetch_row()[0];
        self::$m->query("INSERT INTO users (company_id, email, password_hash, full_name, role_id) VALUES (1, 'u@e.com', 'x', 'U', $co)");
        self::$store = new TokenStore(self::$m);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m) {
            Connection::connect()->query('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        }
    }

    private function seedDoc(int $company): int
    {
        self::$m->query(
            "INSERT INTO documents (company_id, uploader_user_id, storage_key, original_name, mime_type, file_size, classification, human_verification_note) "
            . "VALUES ($company, 1, '" . bin2hex(random_bytes(16)) . "', 'f.pdf', 'application/pdf', 10, 'internal', 't')");
        return (int) self::$m->insert_id;
    }

    public function test_issue_generates_64_hex_token(): void
    {
        $docId = $this->seedDoc(1);
        $token = self::$store->issue($docId, 1, '+1 day');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $row = self::$m->query("SELECT download_token, download_uses FROM documents WHERE id=$docId")->fetch_assoc();
        $this->assertSame($token, $row['download_token']);
        $this->assertSame('0', $row['download_uses']);
    }

    public function test_verify_and_consume_single_use(): void
    {
        $docId = $this->seedDoc(1);
        $token = self::$store->issue($docId, 1, '+1 hour');
        $resolved = self::$store->consume($token, 1);
        $this->assertSame($docId, $resolved, 'first consume resolves to the document');
        // second consume of the same token must fail (single-use)
        $again = self::$store->consume($token, 1);
        $this->assertNull($again, 'replay of consumed token is rejected');
    }

    public function test_verify_rejects_unknown_token(): void
    {
        $this->assertNull(self::$store->consume('deadbeef', 1), 'unknown token rejected');
    }

    public function test_verify_rejects_cross_tenant(): void
    {
        $docId = $this->seedDoc(1);
        $token = self::$store->issue($docId, 1, '+1 hour');
        // token issued for company 1 must NOT resolve under company 2
        $this->assertNull(self::$store->consume($token, 2), 'cross-tenant token use rejected');
    }

    public function test_expired_token_rejected(): void
    {
        $docId = $this->seedDoc(1);
        // issue a token that already expired (past expiry)
        $token = self::$store->issue($docId, 1, '-1 hour');
        $this->assertNull(self::$store->consume($token, 1), 'expired token rejected');
    }
}
