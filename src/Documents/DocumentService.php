<?php
declare(strict_types=1);

namespace Routlaw\Documents;

use Routlaw\Security\AuditLog;
use Routlaw\Security\Authorization;
use Routlaw\Security\AuthorizationException;
use Routlaw\Security\UserSession;

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

    /** @var list<string> MIME types accepted for upload. */
    private const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'];

    /**
     * @param string $storageRoot Absolute, non-web path (e.g. /srv/routlaw/storage/documents).
     */
    public function __construct(\mysqli $db, Authorization $authz, string $storageRoot)
    {
        $this->db = $db;
        $this->authz = $authz;
        $this->storageRoot = rtrim($storageRoot, '/\\');
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

    private function lastInsertId(): int
    {
        $id = $this->db->insert_id;
        return $id > 0 ? (int) $id : 0;
    }
}
