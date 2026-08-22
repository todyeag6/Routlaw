<?php
declare(strict_types=1);

/**
 * T16.4 — public/doc.php: signed-access document delivery (FR-025/026/027).
 *
 * Serves a private document ONLY after a valid single-use download token is
 * presented. Files live outside the web docroot (storage/documents/<cid>/<key>)
 * — this endpoint is the sole read path. Never expose storage_key directly.
 *
 * Usage: /doc.php?token=<64-hex-download-token>
 */

namespace Routlaw;

require __DIR__ . '/../config/autoload.php';

use Routlaw\Documents\TokenStore;
use Routlaw\Db\Connection;

/** @var \mysqli $db */
$db = Connection::connect();

$token = (string) ($_GET['token'] ?? '');
if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
    http_response_code(404);
    exit; // never reveal whether a token exists
}

$store = new TokenStore($db);

// Resolve + consume the token. Single-use: a replay returns null -> 404.
// Company scope is taken from the authenticated session's current tenant.
$companyId = (int) ($_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
    http_response_code(403);
    exit;
}

$docId = $store->consume($token, $companyId);
if ($docId === null) {
    http_response_code(404);
    exit; // unknown / expired / already used / cross-tenant — same response
}

// Locate the file (tenant-scoped read).
$row = $db->query(
    'SELECT original_name, mime_type FROM documents WHERE id = ? AND company_id = ? AND deleted_at IS NULL'
);
if ($row === false) { http_response_code(500); exit; }
$row = $row->fetch_assoc();
if ($row === null) { http_response_code(404); exit; }

// storage path is OUTSIDE docroot: storage/documents/<company>/<key>.
$keyRow = $db->query(
    'SELECT storage_key FROM documents WHERE id = ? AND company_id = ? LIMIT 1'
);
$keyRow = $keyRow !== false && $keyRow->fetch_assoc();
$storageKey = (string) ($keyRow['storage_key'] ?? '');
if ($storageKey === '') { http_response_code(404); exit; }

$root = rtrim((string) ($_ENV['RL_DOC_STORAGE_ROOT'] ?? __DIR__ . '/../storage/documents'), '/\\');
$path = $root . DIRECTORY_SEPARATOR . $companyId . DIRECTORY_SEPARATOR . $storageKey;

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit;
}

// Stream the bytes; never let the storage key leak in the URL.
$mime = (string) $row['mime_type'];
header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . rawurlencode((string) $row['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0');
readfile($path);
exit;
