<?php
declare(strict_types=1);

/**
 * ROUTLAW — Carrier Transition Options (AJAX)
 * Returns valid next states for a carrier's current status
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Auth;
use Routlaw\Security\Headers;
use Routlaw\Db\Connection;

Headers::emit(false);

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$carrierId = (int) ($_GET['carrier_id'] ?? 0);
$currentStatus = trim($_GET['current_status'] ?? '');
$requestedCompanyId = (int) ($_GET['company_id'] ?? 0);

// Verify tenant
if ($requestedCompanyId !== $companyId || $carrierId === 0 || $currentStatus === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

// Verify carrier belongs to company
$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$stmt = $db->prepare('SELECT status FROM carriers WHERE id = ? AND company_id = ? AND deleted_at IS NULL');
$stmt->bind_param('ii', $carrierId, $companyId);
$stmt->execute();
$result = $stmt->get_result();
$carrier = $result ? $result->fetch_assoc() : false;
$stmt->close();

if (!$carrier) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Carrier not found']);
    exit;
}

// Verify current_status matches
if ($carrier['status'] !== $currentStatus) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Status mismatch']);
    exit;
}

// Define allowed transitions (mirrors CarrierService::ALLOWED_TRANSITIONS)
$transitionMap = [
    'new' => ['needs_documents', 'under_review'],
    'needs_documents' => ['under_review', 'new'],
    'under_review' => ['active', 'rejected', 'new'],
    'active' => ['inactive', 'archived'],
    'inactive' => ['active', 'archived'],
    'rejected' => ['archived'],
    'archived' => []
];

$validTransitions = $transitionMap[$currentStatus] ?? [];

header('Content-Type: application/json');
echo json_encode([
    'validTransitions' => $validTransitions,
    'currentStatus' => $currentStatus
]);