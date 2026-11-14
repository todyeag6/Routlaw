<?php
declare(strict_types=1);

/**
 * ROUTLAW — Lead Duplicate Resolution
 * Conservative dedup: mark as distinct or confirm duplicate
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Headers;
use Routlaw\Leads\LeadService;
use Routlaw\Db\Connection;

Headers::emit(false);

// Require authentication - check session
session_start();
if (empty($_SESSION['routlaw_user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = (int) $_SESSION['routlaw_user_id'];
$companyId = (int) ($_SESSION['routlaw_company_id'] ?? 0);
$actorId = $userId;

if ($companyId === 0) {
    http_response_code(403);
    exit('Forbidden');
}

$leadId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$dupOfId = (int) ($_GET['dup_of'] ?? $_POST['dup_of'] ?? 0);

if ($leadId === 0 || $dupOfId === 0 || !in_array($action, ['distinct', 'confirm'], true)) {
    http_response_code(400);
    exit('Invalid parameters');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$leadService = new LeadService($db);

// Verify both leads belong to company
$leads = [];
$stmt = $db->prepare('SELECT id, normalized_name, normalized_email, normalized_phone FROM leads WHERE id IN (?, ?) AND company_id = ? AND deleted_at IS NULL');
$stmt->bind_param('iii', $leadId, $dupOfId, $companyId);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $leads[$row['id']] = $row;
    }
}
$stmt->close();

if (!isset($leads[$leadId]) || !isset($leads[$dupOfId])) {
    http_response_code(404);
    exit('Lead(s) not found');
}

$lead = $leads[$leadId];
$dupLead = $leads[$dupOfId];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $newDupStatus = $action === 'confirm' ? 'confirmed_duplicate' : 'reviewed_distinct';

        $stmt = $db->prepare('UPDATE leads SET dup_status = ?, dup_of_lead_id = ?, updated_at = NOW() WHERE id = ? AND company_id = ?');
        $stmt->bind_param('siii', $newDupStatus, $dupOfId, $leadId, $companyId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            // Audit log
            $audit = new \Routlaw\Security\AuditLog($db);
            $audit->recordSystem('lead.duplicate_resolve', $companyId, 'user', 'update', [
                'lead_id' => $leadId,
                'dup_of_lead_id' => $dupOfId,
                'action' => $action,
                'new_dup_status' => $newDupStatus
            ], 'lead', (string) $leadId);

            header('Location: /leads/show.php?id=' . $leadId . '&resolved=1');
            exit;
        } else {
            throw new \RuntimeException('Update failed');
        }
    } catch (\RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$actionLabels = [
    'distinct' => 'Mark as Distinct',
    'confirm' => 'Confirm Duplicate'
];

$actionDescriptions = [
    'distinct' => 'These are two different entities. The new lead will be marked as "Reviewed Distinct" and both leads remain active.',
    'confirm' => 'These are the same entity. The new lead will be marked as "Confirmed Duplicate" and linked to the existing lead.'
];

// Pre-compute values for heredoc
$leadName = $lead['normalized_name'] ?? '—';
$leadEmail = $lead['normalized_email'] ?? '—';
$leadPhone = $lead['normalized_phone'] ?? '—';

$dupLeadName = $dupLead['normalized_name'] ?? '—';
$dupLeadEmail = $dupLead['normalized_email'] ?? '—';
$dupLeadPhone = $dupLead['normalized_phone'] ?? '—';

$actionDescDistinct = $actionDescriptions['distinct'];
$actionDescConfirm = $actionDescriptions['confirm'];

$errorHtml = '';
if (!empty($error)) {
    $errorHtml = '<div class="alert alert-danger" style="margin-bottom: var(--space-4);"><div class="alert-content"><div class="alert-title">Error</div><div class="alert-message">' . htmlspecialchars($error) . '</div></div></div>';
}

$pageTitle = 'Resolve Duplicate: ' . htmlspecialchars($leadName ?? 'Lead #' . $leadId);

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Resolve Duplicate</h1>
    <a href="/leads/show.php?id={$leadId}" class="btn btn-ghost">Back to Lead</a>
</div>

<div class="card" style="max-width: 800px;">
    <div class="alert alert-warning">
        <div class="alert-content">
            <div class="alert-title">Duplicate Detection (FR-009)</div>
            <div class="alert-message">
                This lead was flagged as a possible duplicate based on matching normalized identifiers (email, phone, or name).
                Please review both records and choose the appropriate action.
            </div>
        </div>
    </div>

    <div class="grid grid-2" style="gap: var(--space-6); margin-bottom: var(--space-6);">
        <div class="card" style="border-color: var(--color-border);">
            <h3 style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold); color: var(--color-text-secondary); margin-bottom: var(--space-3);">New Lead (ID: {$leadId})</h3>
            <div style="font-size: var(--text-sm);">
                <div><strong>Name:</strong> {$leadName}</div>
                <div><strong>Email:</strong> {$leadEmail}</div>
                <div><strong>Phone:</strong> {$leadPhone}</div>
            </div>
        </div>

        <div class="card" style="border-color: var(--color-border);">
            <h3 style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold); color: var(--color-text-secondary); margin-bottom: var(--space-3);">Existing Lead (ID: {$dupOfId})</h3>
            <div style="font-size: var(--text-sm);">
                <div><strong>Name:</strong> {$dupLeadName}</div>
                <div><strong>Email:</strong> {$dupLeadEmail}</div>
                <div><strong>Phone:</strong> {$dupLeadPhone}</div>
            </div>
        </div>
    </div>

    <form method="post" action="/leads/resolve.php">
        <input type="hidden" name="id" value="{$leadId}">
        <input type="hidden" name="dup_of" value="{$dupOfId}">
        <input type="hidden" name="action" value="distinct">
        <input type="hidden" name="action" value="confirm">

        <p style="color: var(--color-text-muted); font-size: var(--text-sm); margin-bottom: var(--space-4);">
            {$actionDescDistinct}
        </p>
        <p style="color: var(--color-text-muted); font-size: var(--text-sm); margin-bottom: var(--space-4);">
            {$actionDescConfirm}
        </p>

        {$errorHtml}

        <div class="cluster" style="gap: var(--space-3); flex-wrap: wrap;">
            <button type="submit" name="action" value="distinct" class="btn btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Mark as Distinct
            </button>
            <button type="submit" name="action" value="confirm" class="btn btn-danger">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                Confirm Duplicate
            </button>
            <a href="/leads/show.php?id={$leadId}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
HTML;

require __DIR__ . '/includes/layout.php';