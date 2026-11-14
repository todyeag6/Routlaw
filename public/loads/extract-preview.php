<?php
declare(strict_types=1);

/**
 * ROUTLAW — Extraction Preview AJAX Endpoint
 * Returns structured extraction for preview without persisting
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Auth;
use Routlaw\Security\Headers;
use Routlaw\Loads\LoadExtractionService;
use Routlaw\Db\Connection;

Headers::emit(false);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$auth = new Auth(Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw'));
$user = $auth->requireAuth();
$companyId = (int) ($user['company_id'] ?? 0);

if ($companyId === 0) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$rawText = trim($_POST['raw_text'] ?? '');

if ($rawText === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No text provided']);
    exit;
}

try {
    $extractor = new LoadExtractionService();
    $extraction = $extractor->extract($rawText);

    header('Content-Type: application/json');
    echo json_encode($extraction);
} catch (\RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}