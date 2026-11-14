<?php
declare(strict_types=1);

/**
 * ROUTLAW — Lead Detail + Duplicate Resolution
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Headers;
use Routlaw\Leads\LeadService;
use Routlaw\Leads\SourceRecordService;
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

$leadId = (int) ($_GET['id'] ?? 0);
if ($leadId === 0) {
    http_response_code(404);
    exit('Lead not found');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$leadService = new LeadService($db);
$sourceRecordService = new SourceRecordService($db);

// Fetch lead
$lead = null;
$stmt = $db->prepare('SELECT * FROM leads WHERE id = ? AND company_id = ? AND deleted_at IS NULL');
$stmt->bind_param('ii', $leadId, $companyId);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $lead = $row;
}
$stmt->close();

if (!$lead) {
    http_response_code(404);
    exit('Lead not found');
}

// Fetch duplicate if flagged
$dupLead = null;
if (($lead['dup_status'] ?? 'none') === 'possible_duplicate' && $lead['dup_of_lead_id']) {
    $stmt = $db->prepare('SELECT * FROM leads WHERE id = ? AND company_id = ? AND deleted_at IS NULL');
    $stmt->bind_param('ii', $lead['dup_of_lead_id'], $companyId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $dupLead = $row;
    }
    $stmt->close();
}

// Fetch source record
$sourceRecord = null;
if ($lead['raw_source_id']) {
    $sourceRecord = $sourceRecordService->getForCompany($companyId, $lead['raw_source_id']);
}

$statusLabels = [
    'new' => 'New',
    'in_review' => 'In Review',
    'converted' => 'Converted',
    'rejected' => 'Rejected',
    'archived' => 'Archived'
];

$dupLabels = [
    'none' => 'None',
    'possible_duplicate' => 'Possible Duplicate',
    'reviewed_distinct' => 'Reviewed Distinct',
    'confirmed_duplicate' => 'Confirmed Duplicate'
];

// Pre-compute all values for heredoc
$leadName = $lead['normalized_name'] ?? 'Unnamed Lead';
$leadType = $lead['lead_type'] ?? 'unknown';
$dupStatus = $lead['dup_status'] ?? 'none';
$dupLabel = $dupLabels[$dupStatus] ?? $dupStatus;

$leadEmail = $lead['normalized_email'] ?? '—';
$leadPhone = $lead['normalized_phone'] ?? '—';
$leadStatus = $statusLabels[$lead['status']] ?? $lead['status'];
$leadSource = $lead['source'] ?? '—';
$leadCreatedAt = $lead['created_at'] ?? '';

$dupLeadHtml = '';
if ($dupLead) {
    $dupLeadName = $dupLead['normalized_name'] ?? 'Unnamed';
    $dupLeadId = $dupLead['id'];
    $dupLeadEmail = $dupLead['normalized_email'] ?? '—';
    $dupLeadPhone = $dupLead['normalized_phone'] ?? '—';

    $dupLeadHtml = <<<HTML
        <div class="divider"></div>
        <h3 class="section-title" style="font-size: var(--text-base); margin-bottom: var(--space-4);">Possible Duplicate Match</h3>
        <div class="alert alert-warning">
            <div class="alert-content">
                <div class="alert-title">This lead was flagged as a possible duplicate of:</div>
                <div class="alert-message">
                    <strong>{$dupLeadName}</strong> (ID: {$dupLeadId})
                    <br>Email: {$dupLeadEmail}
                    <br>Phone: {$dupLeadPhone}
                    <div style="margin-top: var(--space-3);">
                        <a href="/leads/resolve.php?id={$leadId}&action=distinct&dup_of={$dupLeadId}" class="btn btn-primary" style="margin-right: var(--space-2);">Mark as Distinct</a>
                        <a href="/leads/resolve.php?id={$leadId}&action=confirm&dup_of={$dupLeadId}" class="btn btn-danger">Confirm Duplicate</a>
                    </div>
                </div>
            </div>
        </div>
HTML;
}

$sourceRecordHtml = '';
if ($sourceRecord) {
    $srcType = $sourceRecord['source_type'] ?? '';
    $srcExternalId = $sourceRecord['external_id'] ?? '—';
    $srcReceivedAt = $sourceRecord['received_at'] ?? $sourceRecord['created_at'] ?? '';
    $srcContentHash = $sourceRecord['content_hash'] ?? '—';
    $srcPayload = $sourceRecord['canonical_payload'] ? htmlspecialchars($sourceRecord['canonical_payload']) : '—';

    $sourceRecordHtml = <<<HTML
        <div style="font-size: var(--text-sm);">
            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Type</div>
            <div>{$srcType}</div>

            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">External ID</div>
            <div style="font-family: var(--font-mono); font-size: var(--text-xs);">{$srcExternalId}</div>

            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">Received</div>
            <div style="font-size: var(--text-xs); color: var(--color-text-muted);">{$srcReceivedAt}</div>

            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">Content Hash</div>
            <div style="font-family: var(--font-mono); font-size: var(--text-xs);">{$srcContentHash}</div>

            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">Original Content</div>
            <pre style="background: var(--color-primary); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); overflow-x: auto; max-height: 300px; font-size: var(--text-xs);">{$srcPayload}</pre>
        </div>
HTML;
} else {
    $sourceRecordHtml = '<p style="color: var(--color-text-muted);">No source record linked.</p>';
}

$pageTitle = 'Lead: ' . htmlspecialchars($leadName);

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">{$leadName}</h1>
    <div class="cluster">
        <span class="badge badge-{$leadType}">{$leadType}</span>
        <span class="badge badge-{$dupStatus}">{$dupLabel}</span>
        <a href="/leads" class="btn btn-ghost">Back to List</a>
    </div>
</div>

<div class="grid" style="grid-template-columns: 1fr; gap: var(--space-6);">
    @media (min-width: 1024px) { .grid { grid-template-columns: 2fr 1fr; } }
</style>

    <!-- Lead Details -->
    <div class="card">
        <h2 class="section-title">Details</h2>
        <div class="grid grid-2" style="margin-bottom: var(--space-6);">
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Name</div>
                <div style="font-weight: var(--font-weight-medium);">{$leadName}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Email</div>
                <div>{$leadEmail}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Phone</div>
                <div style="font-family: var(--font-mono);">{$leadPhone}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Status</div>
                <div>{$leadStatus}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Source</div>
                <div>{$leadSource}</div>
            </div>
            <div>
                <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Created</div>
                <div style="font-size: var(--text-xs); color: var(--color-text-muted);">{$leadCreatedAt}</div>
            </div>
        </div>

        {$dupLeadHtml}
    </div>

    <!-- Source Record -->
    <div class="card">
        <h2 class="section-title">Source Record</h2>
        {$sourceRecordHtml}
    </div>
</div>
HTML;

require __DIR__ . '/includes/layout.php';