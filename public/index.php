<?php
declare(strict_types=1);

/**
 * ROUTLAW — Dashboard
 * KPIs: carriers by status, loads by review, leads by type
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Headers;
use Routlaw\Carriers\CarrierService;
use Routlaw\Leads\LeadService;
use Routlaw\Loads\LoadService;
use Routlaw\Db\Connection;

// Emit security headers
Headers::emit(false);

// Require authentication - check session
session_start();
if (empty($_SESSION['routlaw_user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = (int) $_SESSION['routlaw_user_id'];
$companyId = (int) ($_SESSION['routlaw_company_id'] ?? 0);
$companyName = $_SESSION['routlaw_company'] ?? 'Unknown Company';
$roleName = $_SESSION['routlaw_role_slug'] ?? 'User';

if ($companyId === 0) {
    http_response_code(403);
    echo 'Forbidden: No company context';
    exit;
}

// Initialize services
$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$carrierService = new CarrierService($db);
$leadService = new LeadService($db);
$loadService = new LoadService($db);

// Fetch data
$carriers = $carrierService->listForTenant($companyId);
$leads = $leadService->listForCompany($companyId);
$loads = $loadService->listForCompany($companyId);

// Compute KPIs
$carrierCounts = ['new' => 0, 'needs_documents' => 0, 'under_review' => 0, 'active' => 0, 'inactive' => 0, 'rejected' => 0, 'archived' => 0];
foreach ($carriers as $c) {
    $status = $c['status'] ?? 'unknown';
    if (isset($carrierCounts[$status])) $carrierCounts[$status]++;
}

$loadsNeedingReview = 0;
$loadsByConfidence = ['high' => 0, 'medium' => 0, 'low' => 0];
foreach ($loads as $l) {
    if (($l['review_required'] ?? 0) === 1) $loadsNeedingReview++;
    $conf = $l['extraction_confidence'] ?? 'unknown';
    if (isset($loadsByConfidence[$conf])) $loadsByConfidence[$conf]++;
}

$leadCounts = ['new' => 0, 'in_review' => 0, 'converted' => 0, 'rejected' => 0, 'archived' => 0];
$leadTypeCounts = ['carrier' => 0, 'broker' => 0, 'shipper' => 0, 'general' => 0, 'document' => 0];
$leadDupCounts = ['none' => 0, 'possible_duplicate' => 0, 'reviewed_distinct' => 0, 'confirmed_duplicate' => 0];
foreach ($leads as $l) {
    $status = $l['status'] ?? 'unknown';
    if (isset($leadCounts[$status])) $leadCounts[$status]++;
    $type = $l['lead_type'] ?? 'unknown';
    if (isset($leadTypeCounts[$type])) $leadTypeCounts[$type]++;
    $dup = $l['dup_status'] ?? 'none';
    if (isset($leadDupCounts[$dup])) $leadDupCounts[$dup]++;
}

$totalCarriers = array_sum($carrierCounts);
$totalLoads = count($loads);
$totalLeads = count($leads);

$pageTitle = 'Dashboard';

// Pre-compute all values for heredoc
$statusLabels = [
    'new' => 'New',
    'needs_documents' => 'Needs Documents',
    'under_review' => 'Under Review',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'rejected' => 'Rejected',
    'archived' => 'Archived'
];

// Build carrier rows
$carrierRowsHtml = '';
$recentCarriers = array_slice($carriers, 0, 10);
if (empty($recentCarriers)) {
    $carrierRowsHtml = '<tr><td colspan="5" style="text-align: center; padding: var(--space-8); color: var(--color-text-muted);">No carriers yet. <a href="/carriers/new" class="link">Add your first carrier</a>.</td></tr>';
} else {
    foreach ($recentCarriers as $carrier) {
        $status = $carrier['status'] ?? 'unknown';
        $statusLabel = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
        $dba = $carrier['doing_business_as'] ?? '';
        $dbaHtml = $dba ? '<br><span style="color: var(--color-text-muted); font-size: var(--text-xs);">' . htmlspecialchars($dba) . '</span>' : '';
        $dot = $carrier['dot_number'] ? 'DOT: ' . htmlspecialchars($carrier['dot_number']) : '—';
        $mc = $carrier['mc_number'] ? 'MC: ' . htmlspecialchars($carrier['mc_number']) : '—';

        $carrierRowsHtml .= <<<HTML
                    <tr data-id="{$carrier['id']}">
                        <td data-label="Carrier"><strong>{$carrier['legal_name']}</strong>{$dbaHtml}</td>
                        <td data-label="DOT / MC" style="font-family: var(--font-mono); font-size: var(--text-xs);">
                            {$dot}<br>
                            {$mc}
                        </td>
                        <td data-label="Status"><span class="badge badge-{$status}">{$statusLabel}</span></td>
                        <td data-label="Created" style="font-size: var(--text-xs); color: var(--color-text-muted);">{$carrier['created_at']}</td>
                        <td data-label="Actions">
                            <a href="/carriers/show.php?id={$carrier['id']}" class="btn btn-ghost btn-icon" aria-label="View carrier">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
                        </td>
                    </tr>
HTML;
    }
}

// Build load rows
$loadRowsHtml = '';
$recentLoads = array_slice($loads, 0, 10);
if (empty($recentLoads)) {
    $loadRowsHtml = '<tr><td colspan="6" style="text-align: center; padding: var(--space-8); color: var(--color-text-muted);">No loads yet. <a href="/loads/new" class="link">Create your first load</a>.</td></tr>';
} else {
    foreach ($recentLoads as $load) {
        $reviewRequired = ($load['review_required'] ?? 0) === 1;
        $reviewBadge = $reviewRequired ? '<span class="badge badge-warning">Review</span>' : '<span class="badge badge-success">Ready</span>';
        $conf = $load['extraction_confidence'] ?? '—';
        if ($conf !== '—') {
            $confColor = $conf === 'high' ? 'success' : ($conf === 'medium' ? 'warning' : 'danger');
            $confBadge = '<span class="badge" style="background-color: var(--color-' . $confColor . '-subtle); color: var(--color-' . $confColor . ');">' . ucfirst($conf) . '</span>';
        } else {
            $confBadge = '<span style="color: var(--color-text-muted);">—</span>';
        }
        $rateDisplay = $load['posted_rate'] ? '$' . number_format($load['posted_rate'], 2) : '—';

        $loadRowsHtml .= <<<HTML
                    <tr data-id="{$load['id']}">
                        <td data-label="Route" style="font-size: var(--text-sm);">
                            <strong>{$load['origin_city']}, {$load['origin_state']}</strong><br>
                            <span style="color: var(--color-text-muted);">→</span> {$load['dest_city']}, {$load['dest_state']}
                        </td>
                        <td data-label="Commodity">{$load['commodity']}</td>
                        <td data-label="Weight" style="font-family: var(--font-mono);">{$load['weight_lbs']} lbs</td>
                        <td data-label="Rate" style="font-family: var(--font-mono);">{$rateDisplay}</td>
                        <td data-label="Review">{$reviewBadge}</td>
                        <td data-label="Confidence">{$confBadge}</td>
                    </tr>
HTML;
    }
}

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Dashboard</h1>
    <div class="cluster">
        <span style="color: var(--color-text-muted); font-size: var(--text-sm);">
            {$companyName} {$roleName}
        </span>
    </div>
</div>

<!-- KPI Grid -->
<div class="grid grid-4" style="margin-bottom: var(--space-8);">
    <div class="card">
        <div style="font-size: var(--text-2xl); font-weight: var(--font-weight-bold); color: var(--color-text-primary);">{$totalCarriers}</div>
        <div style="color: var(--color-text-muted); font-size: var(--text-sm); margin-top: var(--space-1);">Total Carriers</div>
        <div class="cluster" style="margin-top: var(--space-3); gap: var(--space-2); flex-wrap: wrap;">
            <span class="badge badge-new">New: {$carrierCounts['new']}</span>
            <span class="badge badge-needs_documents">Needs Docs: {$carrierCounts['needs_documents']}</span>
            <span class="badge badge-under_review">Review: {$carrierCounts['under_review']}</span>
            <span class="badge badge-active">Active: {$carrierCounts['active']}</span>
            <span class="badge badge-inactive">Inactive: {$carrierCounts['inactive']}</span>
            <span class="badge badge-rejected">Rejected: {$carrierCounts['rejected']}</span>
            <span class="badge badge-archived">Archived: {$carrierCounts['archived']}</span>
        </div>
    </div>

    <div class="card">
        <div style="font-size: var(--text-2xl); font-weight: var(--font-weight-bold); color: var(--color-text-primary);">{$totalLoads}</div>
        <div style="color: var(--color-text-muted); font-size: var(--text-sm); margin-top: var(--space-1);">Total Loads</div>
        <div class="cluster" style="margin-top: var(--space-3); gap: var(--space-2); flex-wrap: wrap;">
            <span class="badge" style="background-color: var(--color-danger-subtle); color: var(--color-danger);">Needs Review: {$loadsNeedingReview}</span>
            <span class="badge" style="background-color: var(--color-success-subtle); color: var(--color-success);">High Conf: {$loadsByConfidence['high']}</span>
            <span class="badge" style="background-color: var(--color-warning-subtle); color: var(--color-warning);">Med Conf: {$loadsByConfidence['medium']}</span>
            <span class="badge" style="background-color: var(--color-danger-subtle); color: var(--color-danger);">Low Conf: {$loadsByConfidence['low']}</span>
        </div>
    </div>

    <div class="card">
        <div style="font-size: var(--text-2xl); font-weight: var(--font-weight-bold); color: var(--color-text-primary);">{$totalLeads}</div>
        <div style="color: var(--color-text-muted); font-size: var(--text-sm); margin-top: var(--space-1);">Total Leads</div>
        <div class="cluster" style="margin-top: var(--space-3); gap: var(--space-2); flex-wrap: wrap;">
            <span class="badge badge-possible_duplicate">Possible Dup: {$leadDupCounts['possible_duplicate']}</span>
            <span class="badge badge-reviewed_distinct">Distinct: {$leadDupCounts['reviewed_distinct']}</span>
            <span class="badge badge-confirmed_duplicate">Confirmed: {$leadDupCounts['confirmed_duplicate']}</span>
        </div>
    </div>

    <div class="card">
        <div style="font-size: var(--text-2xl); font-weight: var(--font-weight-bold); color: var(--color-accent);">Quick Actions</div>
        <div class="cluster" style="margin-top: var(--space-3); flex-direction: column; gap: var(--space-2);">
            <a href="/carriers/new" class="btn btn-primary" style="width: 100%; justify-content: center;">Add Carrier</a>
            <a href="/leads/new" class="btn btn-secondary" style="width: 100%; justify-content: center;">Add Lead</a>
            <a href="/loads/new" class="btn btn-secondary" style="width: 100%; justify-content: center;">Create Load</a>
            <a href="/loads/extract" class="btn btn-ghost" style="width: 100%; justify-content: center;">Extract from Text</a>
        </div>
    </div>
</div>

<!-- Recent Activity Sections -->
<div class="grid" style="grid-template-columns: 1fr; gap: var(--space-6);">
    @media (min-width: 1024px) {
        .grid { grid-template-columns: 1fr 1fr; }
    }
</style>

    <!-- Recent Carriers -->
    <div class="card">
        <div class="page-header" style="margin-bottom: var(--space-4);">
            <h2 class="section-title">Recent Carriers</h2>
            <a href="/carriers" class="btn btn-ghost" style="font-size: var(--text-sm);">View All</a>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Carrier <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">DOT / MC <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Status <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Created <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
{$carrierRowsHtml}
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Loads -->
    <div class="card">
        <div class="page-header" style="margin-bottom: var(--space-4);">
            <h2 class="section-title">Recent Loads</h2>
            <a href="/loads" class="btn btn-ghost" style="font-size: var(--text-sm);">View All</a>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Route <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Commodity <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Weight <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Rate <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Review <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                        <th scope="col" role="columnheader"><button class="sort-btn" type="button">Confidence <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    </tr>
                </thead>
                <tbody>
{$loadRowsHtml}
                </tbody>
            </table>
        </div>
    </div>
</div>
HTML;

require __DIR__ . '/includes/layout.php';