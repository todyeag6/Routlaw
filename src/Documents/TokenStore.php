<?php
declare(strict_types=1);

namespace Routlaw\Documents;

/**
 * T16.4 — Signed-access token store (FR-025/026/027).
 *
 * Issues single-use, expiry-bound download tokens for documents. The public
 * doc.php endpoint validates a token via consume() BEFORE serving the file
 * from outside the web root. The token is an access grant, never the storage key.
 */
final class TokenStore
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Issue (or rotate) a single-use download token for a document.
     *
     * @param string $ttl A MySQL-relative spec: '+1 day', '-1 hour', '+30 minute'.
     * @return string 64-hex token (sodium_bin2hex(random_bytes(32))).
     * @throws \RuntimeException if the document is not in the tenant.
     */
    public function issue(int $docId, int $companyId, string $ttl = '+1 day'): string
    {
        $token = sodium_bin2hex(random_bytes(32));
        [$sign, $amount, $unit] = $this->parseTtl($ttl);
        // sign = '+1' or '-1' so DATE_ADD/DATE_SUB cover positive + negative offsets.
        $fn = $sign >= 0 ? 'DATE_ADD' : 'DATE_SUB';
        $amount = abs($amount);
        $stmt = $this->db->prepare(
            "UPDATE documents SET download_token = ?, download_uses = 0, "
            . "expires_at = $fn(NOW(), INTERVAL ? $unit) "
            . "WHERE id = ? AND company_id = ?"
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare token issue: ' . $this->db->error);
        }
        $types = 'siii';
        /** @var list<mixed> $params */
        $params = [$token, $amount, $docId, $companyId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $affected === 0) {
            throw new \RuntimeException('Document not found in tenant for token issue');
        }
        return $token;
    }

    /**
     * Validate + consume a download token. Single-use: the first call returns the
     * document id; a replay, expired, unknown, or cross-tenant token returns null.
     *
     * @return int|null The document id on success, null on any failure.
     */
    public function consume(string $token, int $companyId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM documents WHERE download_token = ? AND company_id = ? '
            . 'AND expires_at IS NOT NULL AND expires_at > NOW() '
            . 'AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare token consume: ' . $this->db->error);
        }
        $stmt->bind_param('si', $token, $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($row === null || $row === false) {
            return null;
        }
        $docId = (int) $row['id'];

        // single-use: clear the token (CAS on download_token to defend against replays).
        $upd = $this->db->prepare(
            'UPDATE documents SET download_token = NULL, download_uses = download_uses + 1 '
            . 'WHERE id = ? AND company_id = ? AND download_token = ?'
        );
        if ($upd === false) {
            return null;
        }
        $upd->bind_param('iis', $docId, $companyId, $token);
        $upd->execute();
        $affected = $upd->affected_rows;
        $upd->close();
        if ($affected !== 1) {
            return null; // race: token already consumed
        }
        return $docId;
    }

    /**
     * @return array{0:int,1:int,2:string} [sign(±1), amount(int), unit(string)]
     */
    private function parseTtl(string $ttl): array
    {
        $ttl = trim($ttl);
        $sign = ($ttl[0] ?? '+') === '-' ? -1 : 1;
        $rest = ltrim($ttl, '+-');
        if (!preg_match('/^(\d+)\s*(\w+)$/', $rest, $m)) {
            throw new \InvalidArgumentException('Invalid TTL spec: ' . $ttl);
        }
        $amount = (int) $m[1];
        $unit = match ($m[2]) {
            'minute', 'minutes' => 'MINUTE',
            'hour',   'hours'   => 'HOUR',
            'day',    'days'    => 'DAY',
            default => throw new \InvalidArgumentException('Unsupported TTL unit: ' . $m[2]),
        };
        return [$sign, $amount, $unit];
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
}
