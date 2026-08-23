<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\SelfPromotionGuard;
use Routlaw\Db\Connection;

/**
 * T19-2 SelfPromotionGuard — FR-041 proposals cannot self-promote.
 *
 * The model (LLM) can only ever create a proposal in 'draft'. Any transition out of
 * 'draft' requires an explicit human approver id and the guard records it. No method
 * the model can invoke can advance status.
 */
final class SelfPromotionGuardTest extends TestCase
{
    private static \mysqli $m;
    private static string $db;

    public static function setUpBeforeClass(): void
    {
        $host = $_ENV['RL_DB_HOST'] ?? '127.0.0.1';
        $user = $_ENV['RL_DB_USER'] ?? 'root';
        $pass = $_ENV['RL_DB_PASS'] ?? '';
        $port = (int) ($_ENV['RL_DB_PORT'] ?? '3306');
        $base = new \mysqli($host, $user, $pass, '', $port);
        if ($base->connect_error) self::fail('DB connect: ' . $base->connect_error);
        self::$db = 'routlaw_test_' . bin2hex(random_bytes(6));
        $base->query("DROP DATABASE IF EXISTS `".self::$db."`");
        $base->query("CREATE DATABASE `".self::$db."` CHARACTER SET utf8mb4");
        self::$m = new \mysqli($host, $user, $pass, self::$db, $port);
        self::$m->set_charset('utf8mb4');
        $d = dirname(__DIR__) . '/migrations';
        for ($i = 0; $i <= 17; $i++) {
            $g = glob($d . '/' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '_*.sql');
            if (!$g) continue;
            Connection::applyFile(self::$m, $g[0]);
        }
        // 000 seeds roles but not companies; insert a tenant for FK-valid proposals.
        self::$m->query("INSERT INTO companies (legal_name, display_name, status) VALUES ('TestCo', 'TestCo', 'active')");
    }
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$m)) self::$m->query("DROP DATABASE IF EXISTS `".self::$db."`");
    }

    public function test_model_can_only_create_draft(): void
    {
        $g = new SelfPromotionGuard(self::$m);
        $payload = ['proposal' => 'tune temperature', 'reason' => 'overfit'];
        $id = $g->createDraft($payload, companyId: 1, runId: null, systemCreatedBy: 0);
        $this->assertGreaterThan(0, $id);
        $row = self::$m->query("SELECT * FROM learning_proposals WHERE id={$id}")->fetch_assoc();
        $this->assertSame('draft', $row['status']);
        $this->assertNull($row['approved_by'], 'draft has no approver');
    }

    public function test_advance_from_draft_requires_approver(): void
    {
        $g = new SelfPromotionGuard(self::$m);
        $id = $g->createDraft(['x' => 1], 1, null, 0);
        // No approver id -> rejected.
        $ok = $g->advance($id, 'evaluated', null, 'human-reason');
        $this->assertFalse($ok);
        // With a real approver id -> advances and records approver + history.
        $ok = $g->advance($id, 'evaluated', 42, 'human-reason');
        $this->assertTrue($ok);
        $row = self::$m->query("SELECT * FROM learning_proposals WHERE id={$id}")->fetch_assoc();
        $this->assertSame('evaluated', $row['status']);
        $this->assertSame('42', $row['approved_by']);
    }

    public function test_cannot_self_promote_without_approver(): void
    {
        $g = new SelfPromotionGuard(self::$m);
        $id = $g->createDraft(['x' => 1], 1, null, 0);
        foreach (['approved', 'staged'] as $target) {
            $ok = $g->advance($id, $target, null, 'model tried');
            $this->assertFalse($ok, "model must not advance to {$target}");
        }
        $row = self::$m->query("SELECT status FROM learning_proposals WHERE id={$id}")->fetch_assoc();
        $this->assertSame('draft', $row['status'], 'stays in draft after attempted self-promotion');
    }

    public function test_rollback_records_history(): void
    {
        $g = new SelfPromotionGuard(self::$m);
        $id = $g->createDraft(['v' => 1], 1, null, 0);
        $g->advance($id, 'evaluated', 7, 'ok');
        $g->advance($id, 'approved', 8, 'ok');
        $ok = $g->rollback($id, 5, 'regression');
        $this->assertTrue($ok);
        $row = self::$m->query("SELECT status, version FROM learning_proposals WHERE id={$id}")->fetch_assoc();
        $this->assertSame('rolled_back', $row['status']);
        $hist = self::$m->query("SELECT COUNT(*) AS c FROM proposal_status_history WHERE proposal_id={$id}")->fetch_assoc();
        $this->assertGreaterThan(2, $hist['c'], 'each transition is audited in history');
    }
}
