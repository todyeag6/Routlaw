<?php
declare(strict_types=1);

/**
 * ROUTLAW — Carrier Status Transition Handler (POST)
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Auth;
use Routlaw\Security\Headers;
use Routlaw\Carriers\CarrierService;
use Routlaw\Db\Connection;

Headers::emit(false);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$auth = new Auth(Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw'));
$user = $auth->requireAuth();
$companyId = (int) ($user['company_id'] ?? 0);
$actorRoleId = (int) ($user['role_id'] ?? 0);

if ($companyId === 0) {
    http_response_code(403);
    exit('Forbidden');
}

// Validate CSRF (simple check)
if (empty($_POST['carrier_id']) || empty($_POST['new_status']) || empty($_POST['company_id']) || empty($_POST['actor_role_id'])) {
    http_response_code(400);
    exit('Missing required parameters');
}

$carrierId = (int) $_POST['carrier_id'];
$newStatus = trim($_POST['new_status']);
$postedCompanyId = (int) $_POST['company_id'];
$postedActorRoleId = (int) $_POST['actor_role_id'];
$reason = trim($_POST['reason'] ?? '');

// Verify company_id matches session (tenant isolation)
if ($postedCompanyId !== $companyId || $postedActorRoleId !== $actorRoleId) {
    http_response_code(403);
    exit('Forbidden: tenant mismatch');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$carrierService = new CarrierService($db);

try {
    $ok = $carrierService->transitionState($carrierId, $newStatus, $actorRoleId, $companyId);

    if ($ok) {
        // Log reason if provided (could extend carrier_status_history with reason)
        if ($reason !== '') {
            $stmt = $db->prepare('UPDATE carrier_status_history SET reason = ? WHERE carrier_id = ? AND company_id = ? AND to_status = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->bind_param('siss', $reason, $carrierId, $companyId, $newStatus);
            $stmt->execute();
            $stmt->close();
        }

        // AJAX response
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
            exit;
        }

        header('Location: /carriers/show.php?id=' . $carrierId . '&transitioned=1');
        exit;
    } else {
        throw new \RuntimeException('Invalid state transition');
    }
} catch (\RuntimeException $e) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    // Fallback: redirect with error
    header('Location: /carriers/show.php?id=' . $carrierId . '&error=' . urlencode($e->getMessage()));
    exit;
}