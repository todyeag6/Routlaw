<?php
declare(strict_types=1);

/**
 * ROUTLAW — Leads List
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
$companyName = $_SESSION['routlaw_company'] ?? 'your company';

if ($companyId === 0) {
    http_response_code(403);
    exit('Forbidden');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$leadService = new LeadService($db);
$leads = $leadService->listForCompany($companyId);

$pageTitle = 'Leads';

// Pre-compute lead rows
$leadRowsHtml = '';
if (empty($leads)) {
    $leadRowsHtml = '<tr><td colspan="7" style="text-align: center; padding: var(--space-8); color: var(--color-text-muted);">No leads yet. <a href="/leads/new" class="link">Add your first lead</a>.</td></tr>';
} else {
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

    foreach ($leads as $lead) {
        $status = $lead['status'] ?? 'unknown';
        $dupStatus = $lead['dup_status'] ?? 'none';
        $leadType = $lead['lead_type'] ?? 'unknown';

        $statusLabel = $statusLabels[$status] ?? $status;
        $dupLabel = $dupLabels[$dupStatus] ?? $dupStatus;

        $contactParts = [];
        if (!empty($lead['normalized_email'])) $contactParts[] = '<span style="font-family: var(--font-mono);">' . htmlspecialchars($lead['normalized_email']) . '</span>';
        if (!empty($lead['normalized_phone'])) $contactParts[] = '<span style="font-family: var(--font-mono);">' . htmlspecialchars($lead['normalized_phone']) . '</span>';
        if (!empty($lead['normalized_name'])) $contactParts[] = htmlspecialchars($lead['normalized_name']);
        $contactHtml = implode('<br>', $contactParts) ?: '—';

        $source = $lead['source'] ?? '—';
        $createdAt = $lead['created_at'] ?? '';

        $leadRowsHtml .= <<<HTML
                <tr data-id="{$lead['id']}">
                    <td data-label="Type"><span class="badge badge-{$leadType}">{$leadType}</span></td>
                    <td data-label="Contact">{$contactHtml}</td>
                    <td data-label="Status">{$statusLabel}</td>
                    <td data-label="Dup Status"><span class="badge badge-{$dupStatus}">{$dupLabel}</span></td>
                    <td data-label="Source">{$source}</td>
                    <td data-label="Created" style="font-size: var(--text-xs); color: var(--color-text-muted);">{$createdAt}</td>
                    <td data-label="Actions">
                        <div class="cluster" style="gap: var(--space-2);">
                            <a href="/leads/show.php?id={$lead['id']}" class="btn btn-ghost btn-icon" aria-label="View lead">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
HTML;
        if ($dupStatus === 'possible_duplicate') {
            $dupOf = (int) ($lead['dup_of_lead_id'] ?? 0);
            $leadRowsHtml .= <<<HTML
                            <a href="/leads/resolve.php?id={$lead['id']}&action=review&dup_of={$dupOf}" class="btn btn-ghost btn-icon" aria-label="Review duplicate">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </a>
HTML;
        }
        $leadRowsHtml .= '                        </div></td></tr>';
    }
}

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Leads</h1>
    <a href="/leads/new" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Lead
    </a>
</div>

<div class="card">
    <div class="table-wrapper">
        <table class="data-table">
            <caption>Leads for {$companyName}</caption>
            <thead>
                <tr>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Type <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Contact <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Status <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Dup Status <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Source <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Created <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
{$leadRowsHtml}
            </tbody>
        </table>
    </div>
</div>
HTML;

require __DIR__ . '/includes/layout.php';