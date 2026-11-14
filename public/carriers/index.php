<?php
declare(strict_types=1);

/**
 * ROUTLAW — Carriers List
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
$companyName = $_SESSION['routlaw_company'] ?? 'your company';
$actorRoleId = (int) ($_SESSION['routlaw_role_id'] ?? 0);

if ($companyId === 0) {
    http_response_code(403);
    exit('Forbidden');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$carrierService = new CarrierService($db);
$carriers = $carrierService->listForTenant($companyId);

$pageTitle = 'Carriers';

// Pre-compute carrier rows
$carrierRowsHtml = '';
if (empty($carriers)) {
    $carrierRowsHtml = '<tr><td colspan="7" style="text-align: center; padding: var(--space-8); color: var(--color-text-muted);">No carriers yet. <a href="/carriers/new" class="link">Add your first carrier</a>.</td></tr>';
} else {
    foreach ($carriers as $carrier) {
        $status = $carrier['status'] ?? 'unknown';
        $statusLabel = ucfirst(str_replace('_', ' ', $status));
        $canTransition = in_array($status, ['new', 'needs_documents', 'under_review', 'active', 'inactive'], true);

        $dba = $carrier['doing_business_as'] ?? '—';
        $dot = $carrier['dot_number'] ? 'DOT: ' . htmlspecialchars($carrier['dot_number']) : '—';
        $mc = $carrier['mc_number'] ? 'MC: ' . htmlspecialchars($carrier['mc_number']) : '—';
        $ein = $carrier['ein'] ?? '—';

        $carrierRowsHtml .= <<<HTML
                <tr data-id="{$carrier['id']}">
                    <td data-label="Carrier"><strong>{$carrier['legal_name']}</strong></td>
                    <td data-label="DBA">{$dba}</td>
                    <td data-label="DOT / MC" style="font-family: var(--font-mono); font-size: var(--text-xs);">
                        {$dot}<br>
                        {$mc}
                    </td>
                    <td data-label="Status">
                        <span class="badge badge-{$status}">{$statusLabel}</span>
                    </td>
                    <td data-label="EIN">{$ein}</td>
                    <td data-label="Created" style="font-size: var(--text-xs); color: var(--color-text-muted);">{$carrier['created_at']}</td>
                    <td data-label="Actions">
                        <div class="cluster" style="gap: var(--space-2);">
                            <a href="/carriers/show.php?id={$carrier['id']}" class="btn btn-ghost btn-icon" aria-label="View carrier">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
HTML;
        if ($canTransition) {
            $carrierRowsHtml .= <<<HTML
                            <button type="button"
                                class="btn btn-ghost btn-icon"
                                data-carrier-transition
                                data-carrier-id="{$carrier['id']}"
                                data-current-status="{$status}"
                                data-company-id="{$companyId}"
                                data-actor-role-id="{$actorRoleId}"
                                aria-label="Transition status">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg>
                            </button>
HTML;
        }
        $carrierRowsHtml .= '                        </div></td></tr>';
    }
}

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Carriers</h1>
    <a href="/carriers/new" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Carrier
    </a>
</div>

<div class="card">
    <div class="table-wrapper">
        <table class="data-table">
            <caption>Carriers for {$companyName}</caption>
            <thead>
                <tr>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Carrier <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">DBA <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">DOT / MC <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Status <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">EIN <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
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
HTML;

require __DIR__ . '/includes/layout.php';