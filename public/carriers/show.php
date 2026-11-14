<?php
declare(strict_types=1);

/**
 * ROUTLAW — Carrier Detail + Status History + Transition
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Headers;
use Routlaw\Carriers\CarrierService;
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
$actorRoleId = (int) ($_SESSION['routlaw_role_id'] ?? 0);

if ($companyId === 0) {
    http_response_code(403);
    exit('Forbidden');
}

$carrierId = (int) ($_GET['id'] ?? 0);
if ($carrierId === 0) {
    http_response_code(404);
    exit('Carrier not found');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$carrierService = new CarrierService($db);

// Fetch carrier
$carrier = null;
$stmt = $db->prepare('SELECT * FROM carriers WHERE id = ? AND company_id = ? AND deleted_at IS NULL');
$stmt->bind_param('ii', $carrierId, $companyId);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $carrier = $row;
}
$stmt->close();

if (!$carrier) {
    http_response_code(404);
    exit('Carrier not found');
}

// Fetch status history
$history = [];
$stmt = $db->prepare('SELECT * FROM carrier_status_history WHERE carrier_id = ? AND company_id = ? ORDER BY created_at DESC');
$stmt->bind_param('ii', $carrierId, $companyId);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
}
$stmt->close();

// Get valid transitions
$currentStatus = $carrier['status'];
$allowedTransitions = [];
$transitionMap = [
    'new' => ['needs_documents', 'under_review'],
    'needs_documents' => ['under_review', 'new'],
    'under_review' => ['active', 'rejected', 'new'],
    'active' => ['inactive', 'archived'],
    'inactive' => ['active', 'archived'],
    'rejected' => ['archived'],
    'archived' => []
];
if (isset($transitionMap[$currentStatus])) {
    $allowedTransitions = $transitionMap[$currentStatus];
}

$statusLabels = [
    'new' => 'New',
    'needs_documents' => 'Needs Documents',
    'under_review' => 'Under Review',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'rejected' => 'Rejected',
    'archived' => 'Archived'
];

// Pre-compute values for heredoc
$currentStatusLabel = $statusLabels[$currentStatus] ?? $currentStatus;
$carrierStatus = $carrier['status'];
$carrierLegalName = htmlspecialchars($carrier['legal_name']);
$carrierDba = $carrier['doing_business_as'] ?? '—';
$carrierDot = $carrier['dot_number'] ?? '—';
$carrierMc = $carrier['mc_number'] ?? '—';
$carrierEin = $carrier['ein'] ?? '—';
$carrierSourceType = $carrier['source_type'] ?? '—';

$allowedHtml = '';
if ($allowedTransitions) {
    $optionsHtml = '';
    foreach ($allowedTransitions as $s) {
        $label = $statusLabels[$s] ?? $s;
        $optionsHtml .= '<option value="' . $s . '">' . htmlspecialchars($label) . '</option>';
    }
    $allowedHtml = <<<HTML
        <form method="post" action="/carriers/transition.php" class="form-row form-row-2" style="max-width: 480px;">
            <input type="hidden" name="carrier_id" value="{$carrierId}">
            <input type="hidden" name="company_id" value="{$companyId}">
            <input type="hidden" name="actor_role_id" value="{$actorRoleId}">

            <div class="form-field">
                <label for="new_status" class="form-label">New Status <span class="required-indicator">*</span></label>
                <select id="new_status" name="new_status" class="form-input" required>
                    <option value="">Select…</option>
                    {$optionsHtml}
                </select>
            </div>

            <div class="form-field">
                <label for="reason" class="form-label">Reason (optional)</label>
                <input type="text" id="reason" name="reason" class="form-input" placeholder="Reason for transition">
            </div>

            <div class="form-field" style="grid-column: 1 / -1; margin-top: var(--space-2);">
                <button type="submit" class="btn btn-primary">Save Transition</button>
            </div>
        </form>
HTML;
} else {
    $allowedHtml = '<p style="color: var(--color-text-muted);">No further transitions allowed from this state.</p>';
}

$allowedTransitionsStr = '';
if ($allowedTransitions) {
    $allowedTransitionsStr = ' → Allowed: ' . implode(', ', array_map(function($s) use ($statusLabels) {
        return $statusLabels[$s] ?? $s;
    }, $allowedTransitions));
}

$pageTitle = 'Carrier: ' . $carrierLegalName;

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">{$carrierLegalName}</h1>
    <div class="cluster">
        <span class="badge badge-{$carrierStatus}">{$currentStatusLabel}</span>
        <a href="/carriers" class="btn btn-ghost">Back to List</a>
    </div>
</div>

<div class="grid" style="grid-template-columns: 1fr; gap: var(--space-6);">
    @media (min-width: 1024px) { .grid { grid-template-columns: 2fr 1fr; } }
</style>

    <!-- Carrier Details -->
    <div class="card">
        <h2 class="section-title">Details</h2>
        <div class="grid grid-2" style="margin-bottom: var(--space-6);">
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Legal Name</div>
                <div style="font-weight: var(--font-weight-medium);">{$carrierLegalName}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">DBA</div>
                <div>{$carrierDba}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">DOT Number</div>
                <div style="font-family: var(--font-mono);">{$carrierDot}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">MC Number</div>
                <div style="font-family: var(--font-mono);">{$carrierMc}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">EIN</div>
                <div style="font-family: var(--font-mono);">{$carrierEin}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Source</div>
                <div>{$carrierSourceType}</div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Status Transition -->
        <h3 class="section-title" style="font-size: var(--text-base); margin-bottom: var(--space-4);">Status Transition</h3>
        <p style="color: var(--color-text-muted); font-size: var(--text-sm); margin-bottom: var(--space-4);">
            Current: <strong>{$currentStatusLabel}</strong>
            {$allowedTransitionsStr}
        </p>

        {$allowedHtml}
    </div>

    <!-- Status History -->
    <div class="card">
        <h2 class="section-title">Status History</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">From</th>
                        <th scope="col">To</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Date</th>
                    </tr>
                </thead>
                <tbody>
HTML;

if (empty($history)) {
    $content .= '<tr><td colspan="5" style="text-align: center; padding: var(--space-8); color: var(--color-text-muted);">No status history yet.</td></tr>';
} else {
    foreach ($history as $h) {
        $fromLabel = $statusLabels[$h['from_status']] ?? $h['from_status'];
        $toLabel = $statusLabels[$h['to_status']] ?? $h['to_status'];
        $actorType = $h['actor_type'] ?? 'user';
        $actorLabel = $actorType === 'system' ? 'System' : ($actorType === 'agent' ? 'AI Agent' : 'User');
        $reason = $h['reason'] ?? '—';

        $content .= <<<HTML
                    <tr>
                        <td><span class="badge badge-{$h['from_status']}">{$fromLabel}</span></td>
                        <td><span class="badge badge-{$h['to_status']}">{$toLabel}</span></td>
                        <td>{$reason}</td>
                        <td>{$actorLabel} #{$h['actor_id']}</td>
                        <td style="font-size: var(--text-xs); color: var(--color-text-muted);">{$h['created_at']}</td>
                    </tr>
HTML;
    }
}

$content .= <<<HTML
                </tbody>
            </table>
        </div>
    </div>
</div>
HTML;

require __DIR__ . '/includes/layout.php';