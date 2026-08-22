<?php
declare(strict_types=1);

namespace Routlaw\Outgoing;

use Routlaw\Approvals\ApprovalService;
use Routlaw\Db\Connection;
use Routlaw\Mail\MailerInterface;
use Routlaw\Messages\MessageDraftService;
use Routlaw\Security\Authorization;
use Routlaw\Security\AuthorizationException;
use Routlaw\Security\UserSession;

/**
 * T14.4 — OutboundService (FR-021 "cannot self-send"; FR-022/023/024 payload-bound approvals; FR-042).
 *
 * The SINGLE chokepoint that can (a) flip a draft to `sent` and (b) invoke the Mailer.
 * MessageDraftService has no mailer logic at all — it cannot trigger delivery.
 *
 * Flow: perm-check → approver≠drafter → tenant-read draft → create approval (hash) →
 * approve → consume (single-use) → send → mark sent. Each guard is structural.
 */
final class OutboundService
{
    private \mysqli $db;
    private Authorization $authz;
    private MessageDraftService $drafts;
    private ApprovalService $approvals;
    private MailerInterface $mailer;

    public function __construct(
        \mysqli $db,
        Authorization $authz,
        MessageDraftService $drafts,
        ApprovalService $approvals,
        MailerInterface $mailer
    ) {
        $this->db = $db;
        $this->authz = $authz;
        $this->drafts = $drafts;
        $this->approvals = $approvals;
        $this->mailer = $mailer;
    }

    /**
     * Approve a draft's send and deliver it — the only path from draft to sent.
     *
     * @param array<string,mixed> $payload Approval payload (to, amount, currency...).
     * @throws AuthorizationException if perm-missing, self-send, or cross-tenant.
     */
    public function approveAndSend(int $companyId, int $draftId, UserSession $approver, UserSession $drafter, array $payload): bool
    {
        // 1. Permission gate: approver must hold approvals.outbound in the tenant.
        if (!$this->authz->hasPermission($approver->userId, $companyId, 'approvals.outbound')) {
            throw new AuthorizationException('approvals.outbound', 'denied: approvals.outbound');
        }

        // 2. Cannot self-send: approver and drafter must be distinct actors.
        if ($approver->userId === $drafter->userId) {
            throw new AuthorizationException('self_send', 'cannot self-send');
        }

        // 3. Tenant boundary: the draft must belong to companyId (cross-tenant reject).
        $draft = $this->drafts->get($draftId, $companyId);
        if ($draft === null) {
            throw new AuthorizationException('draft', 'draft not found in tenant');
        }
        if ($draft['company_id'] !== $companyId) {
            throw new AuthorizationException('cross_tenant', 'cross-tenant draft access');
        }

        // 4. Draft a payload-bound approval (canonical hash -> tamper/replay rejected).
        $approvalId = $this->approvals->create(
            $companyId,
            $approver->userId,
            'message.send',
            'message_draft',
            $draftId,
            $payload,
            '+1 day'
        );

        // 5. Approve it (distinct approver), then consume single-use before send.
        $approved = $this->approvals->approve($approvalId, $companyId, $approver, $payload);
        if (!$approved) {
            return false;
        }
        if (!$this->approvals->isApproved('message.send', 'message_draft', $draftId, $payload, [$companyId])) {
            return false;
        }
        $consumed = $this->approvals->consume('message.send', 'message_draft', $draftId, $payload, [$companyId]);
        if (!$consumed) {
            return false; // replay / race — approval already consumed
        }

        // 6. Deliver — only reachable after approval consumes (cannot self-send / cannot draft→sent).
        $to = (string) ($payload['to'] ?? '');
        $subject = $draft['subject'];
        $body = $draft['body'];
        $this->mailer->send($to, $subject, $body);

        // 7. Flip draft to sent (tenant-scoped write).
        return $this->markSent($draftId, $companyId);
    }

    private function markSent(int $draftId, int $companyId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE message_drafts SET status = ?, sent_at = NOW() WHERE id = ? AND company_id = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare draft send: ' . $this->db->error);
        }
        $status = 'sent';
        $types = 'sii';
        /** @var list<mixed> $params */
        $params = [$status, $draftId, $companyId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Failed to mark draft sent: ' . $this->db->error);
        }
        return $affected > 0;
    }

    /**
     * bind_param arity + type-char guard (routlaw skill: a count-only check is unsafe).
     *
     * @param array<int, mixed> $params
     */
    private function assertBindArity(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if (strlen($types) !== count($params)) {
            $stmt->close();
            throw new \LogicException(sprintf('bind_param arity mismatch: %d types vs %d params', strlen($types), count($params)));
        }
        $expected = ['i' => 'integer', 'd' => 'double', 's' => 'string', 'b' => 'string'];
        foreach ($params as $i => $p) {
            if ($p === null) {
                continue;
            }
            $want = $expected[$types[$i]] ?? null;
            if ($want !== null && gettype($p) !== $want) {
                $stmt->close();
                throw new \LogicException(sprintf('bind_param type mismatch at param %d: "%s" expects %s, got %s', $i, $types[$i], $want, gettype($p)));
            }
        }
    }
}
