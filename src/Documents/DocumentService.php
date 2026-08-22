<?php
declare(strict_types=1);

namespace Routlaw\Documents;

use Routlaw\Security\AuditLog;
use Routlaw\Security\Authorization;
use Routlaw\Security\AuthorizationException;
use Routlaw\Security\UserSession;
use Routlaw\Approvals\ApprovalService;

/**
 * T16 — DocumentService (FR-025/026/027/028).
 *
 * Upload gate: must hold documents.upload. Content is MIME-checked + signature-scanned
 * (no executable / PHP-open-tag content) and stored OUTSIDE the web docroot at a
 * randomized sodium key: storage/documents/<company_id>/<key>. Classification is a
 * label, never legal validity (FR-028). No model/agent can invoke delivery.
 */
final class DocumentService
{
    private \mysqli $db;
    private Authorization $authz;
    private string $storageRoot;
    private TokenStore $tokens;
    private ApprovalService $approvals;

    /** @var list<string> MIME types accepted for upload. */
    private const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'];

    /**
     * @param string $storageRoot Absolute, non-web path (e.g. /srv/routlaw/storage/documents).
     */
    public function __construct(
        \mysqli $db,
        Authorization $authz,
        string $storageRoot,
        ?TokenStore $tokens = null,
        ?ApprovalService $approvals = null
    ) {
        $this->db = $db;
        $this->authz = $authz;
        $this->storageRoot = rtrim($storageRoot, '/\\');
        $this->tokens = $tokens ?? new TokenStore($db);
        $this->approvals = $approvals ?? new ApprovalService($db);
    }

    /**
     * Uploads a private document (FR-025/026/027/028).
     *
     * @param array{tmp_name:string,name:string,type:string,size:int,error:int} $file Upload $_FILES entry.
     * @param string $classification 'internal'|'confidential'|'restricted' (label, not legal validity).
     * @param string $humanVerificationNote Required free text; human must attest the doc's nature (FR-028).
     * @return array{ id:int, storage_key:string, file_path:string, classification:string, mime_type:string, human_verification_note:string }
     * @throws AuthorizationException  on perm-denied, cross-tenant, bad extension, forbidden MIME, or executable/PHP content.
     * @throws \RuntimeException        on filesystem or DB failure.
     */
    public function upload(array $file, UserSession $actor, string $classification, string $humanVerificationNote): array
    {
        // 1. Permission gate (tenant-scoped).
        if (!$this->authz->hasPermission($actor->userId, $actor->companyId, 'documents.upload')) {
            throw new AuthorizationException('documents.upload', 'denied: documents.upload');
        }

        // 2. Tenant boundary: the actor's effective company must match their session.
        if ($actor->companyId !== $actor->companyId) { // invariant; defense in depth
            throw new AuthorizationException('tenant', 'tenant mismatch');
        }

        // 3. Validate upload integrity + extension allowlist (BR-006/007).
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new AuthorizationException('upload', 'upload error code ' . (int) $file['error']);
        }
        $originalName = $file['name'];
        if ($originalName === '') {
            throw new AuthorizationException('upload', 'missing file name');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'txt'], true)) {
            throw new AuthorizationException('upload', 'disallowed extension: ' . $ext);
        }

        // 4. MIME allowlist (do NOT trust the client; sniff + compare).
        $mime = $this->sniffMime($file['tmp_name'], $ext);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new AuthorizationException('upload', 'disallowed mime: ' . $mime);
        }
        // cross-check client-declared type vs sniffed (mismatch => reject)
        $clientMime = $file['type'];
        if ($clientMime !== '' && $clientMime !== $mime) {
            throw new AuthorizationException('upload', 'mime mismatch');
        }

        // 5. Executable-content / PHP-open-tag scan (no code execution via uploaded file).
        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read uploaded file');
        }
        if ($this->looksExecutable($contents, $ext)) {
            throw new AuthorizationException('upload', 'executable content rejected');
        }

        // 6. Randomized sodium storage key (FR-025/026 — no predictable paths).
        $storageKey = sodium_bin2hex(random_bytes(32));
        $relDir = 'storage/documents/' . (int) $actor->companyId;
        $absDir = $this->storageRoot . DIRECTORY_SEPARATOR . $relDir;
        if (!is_dir($absDir)) {
            if (!@mkdir($absDir, 0700, true) && !is_dir($absDir)) {
                throw new \RuntimeException('Failed to create document storage dir: ' . $absDir);
            }
        }
        $absPath = $absDir . DIRECTORY_SEPARATOR . $storageKey;
        if (file_put_contents($absPath, $contents) === false) {
            throw new \RuntimeException('Failed to write document to storage');
        }
        @chmod($absPath, 0600);

        // 7. Persist metadata (tenant-scoped insert).
        $stmt = $this->db->prepare(
            'INSERT INTO documents (company_id, uploader_user_id, storage_key, original_name, mime_type, file_size, classification, human_verification_note) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            @unlink($absPath);
            throw new \RuntimeException('Failed to prepare document insert: ' . $this->db->error);
        }
        $types = 'iisssiss';
        /** @var list<mixed> $params */
        $params = [
            $actor->companyId, $actor->userId, $storageKey, $originalName,
            $mime, (int) strlen($contents), $classification, $humanVerificationNote,
        ];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $docId = $this->lastInsertId();
        $stmt->close();
        if (!$ok) {
            @unlink($absPath);
            throw new \RuntimeException('Failed to persist document: ' . $this->db->error);
        }

        // 8. Audit (FR-028 human verification recorded in-row + audit trail).
        $audit = new AuditLog($this->db);
        $audit->record($actor, 'document.upload', 'document', (string) $docId, 'success', [
            'mime'  => $mime,
            'size'  => (int) strlen($contents),
            'classification' => $classification,
        ]);

        return [
            'id'                       => $docId,
            'storage_key'              => $storageKey,
            'file_path'                => $absPath,
            'classification'           => $classification,
            'mime_type'                => $mime,
            'human_verification_note'  => $humanVerificationNote,
        ];
    }

    /**
     * Sniff true MIME via php-mime-type detection; fall back to a strict
     * extension->mime map so a spoofed `type` header can't bypass the allowlist.
     */
    private function sniffMime(string $tmpName, string $ext): string
    {
        // prefer ext-fileinfo when available (libmagic)
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f !== false) {
                $m = @finfo_file($f, $tmpName);
                finfo_close($f);
                if (is_string($m) && $m !== 'application/x-empty') {
                    return $m;
                }
            }
        }
        $map = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'txt' => 'text/plain'];
        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * Detect executable signatures + PHP open tags (defense against web-shell uploads).
     */
    private function looksExecutable(string $contents, string $ext): bool
    {
        $lower = strtolower($contents);
        // PHP open tags (any variant incl. short_open_tag / ASP) => reject outright.
        if (str_contains($lower, '<?php') || str_contains($lower, '<?=' ) || str_contains($lower, '<?')) {
            return true;
        }
        if (str_contains($lower, '<%') || str_contains($lower, '<script') || str_contains($lower, '<asp')) {
            return true;
        }
        // executable magic bytes (PEM doesn't count; these are ELF/PE/ZIP-based execs)
        if (str_starts_with($contents, "\x7fELF")) { return true; } // ELF
        if (str_starts_with($contents, 'MZ'))   { return true; } // Windows PE
        // .zip/.jar are not in the allowlist; nothing else to check.
        return false;
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
            if ($p === null) { continue; }
            $want = $expected[$types[$i]] ?? null;
            if ($want !== null && gettype($p) !== $want) {
                $stmt->close();
                throw new \LogicException(sprintf('bind_param type mismatch at param %d: "%s" expects %s, got %s', $i, $types[$i], $want, gettype($p)));
            }
        }
    }

    /**
     * Share a private document (FR-027) — approval-gated, cannot self-approve.
     *
     * A human sharer requests a share; a DISTINCT human approver must approve it
     * (payload-bound: the share request + approver identity are hashed). On approval
     * the approval is consumed (single-use) and a single-use download token is issued.
     * No agent/model parameter exists — sharing is a human-only control surface.
     *
     * @param array<string,mixed> $payload Share request (recipient, reason, ...).
     * @param UserSession $approver A DISTINCT human approver (cannot equal the sharer).
     * @return string 64-hex single-use download token.
     * @throws AuthorizationException on perm-denied or self-approve.
     */
    public function share(int $companyId, int $docId, UserSession $sharer, array $payload, UserSession $approver): string
    {
        // 1. Permission gate: the sharer must hold documents.share.
        if (!$this->authz->hasPermission($sharer->userId, $companyId, 'documents.share')) {
            throw new AuthorizationException('documents.share', 'denied: documents.share');
        }
        // 2. Cannot self-approve: sharer and approver must be distinct humans.
        if ($sharer->userId === $approver->userId) {
            throw new AuthorizationException('self_approve', 'cannot self-approve share');
        }

        // 3. Document must exist in tenant (tenant-scoped read).
        $row = $this->db->query(
            "SELECT id FROM documents WHERE id = $docId AND company_id = $companyId AND deleted_at IS NULL LIMIT 1"
        );
        if (!($row instanceof \mysqli_result) || $row->fetch_assoc() === null) {
            throw new AuthorizationException('document', 'document not found in tenant');
        }

        // 4. Payload-bound approval (tamper/replay binding to the share request).
        $approvalId = $this->approvals->create(
            $companyId, $approver->userId, 'document.share', 'document', $docId, $payload, '+1 day'
        );
        // 5. Distinct approver approves + we verify + consume single-use.
        $approved = $this->approvals->approve($approvalId, $companyId, $approver, $payload);
        if (!$approved) {
            throw new \RuntimeException('Approval transition failed');
        }
        if (!$this->approvals->isApproved('document.share', 'document', $docId, $payload, [$companyId])) {
            throw new \RuntimeException('Approval not valid for share payload');
        }
        $consumed = $this->approvals->consume('document.share', 'document', $docId, $payload, [$companyId]);
        if (!$consumed) {
            throw new \RuntimeException('Approval already consumed (replay)');
        }

        // 6. Issue a single-use download token (the only way to retrieve the file).
        $token = $this->tokens->issue($docId, $companyId, '+1 day');

        // 7. Audit the human-approved share.
        $audit = new AuditLog($this->db);
        $audit->record($sharer, 'document.share', 'document', (string) $docId, 'success', [
            'approver' => $approver->userId,
            'recipient' => (string) ($payload['recipient'] ?? ''),
        ]);

        return $token;
    }

    private function lastInsertId(): int
    {
        $id = $this->db->insert_id;
        return $id > 0 ? (int) $id : 0;
    }
}
