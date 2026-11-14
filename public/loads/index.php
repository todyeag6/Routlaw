<?php
declare(strict_types=1);

/**
 * ROUTLAW — Loads List
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Headers;
use Routlaw\Loads\LoadService;
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

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$loadService = new LoadService($db);
$loads = $loadService->listForCompany($companyId);

$companyName = $_SESSION['routlaw_company_name'] ?? 'your company';

$pageTitle = 'Loads';

$statusLabels = [
    'new' => 'New',
    'extracted' => 'Extracted',
    'needs_info' => 'Needs Info',
    'review_ready' => 'Review Ready',
    'rejected' => 'Rejected',
    'archived' => 'Archived'
];

$rowsHtml = '';
if (empty($loads)) {
    $rowsHtml = '<tr><td colspan="10" style="text-align: center; padding: var(--space-8); color: var(--color-text-muted);">No loads yet. <a href="/loads/new" class="link">Create your first load</a> or <a href="/loads/extract" class="link">extract from text</a>.</td></tr>';
} else {
    foreach ($loads as $load) {
        $status = $load['status'] ?? 'new';
        $reviewRequired = ($load['review_required'] ?? 0) === 1;
        $conf = $load['extraction_confidence'] ?? '—';
        $sourceId = $load['source_record_id'] ?? null;

        $statusLabel = $statusLabels[$status] ?? $status;

        if ($conf !== '—') {
            $confColor = $conf === 'high' ? 'success' : ($conf === 'medium' ? 'warning' : 'danger');
            $confBadge = '<span class="badge" style="background-color: var(--color-' . $confColor . '-subtle); color: var(--color-' . $confColor . ');">' . ucfirst($conf) . '</span>';
        } else {
            $confBadge = '<span style="color: var(--color-text-muted);">—</span>';
        }

        $sourceDisplay = $sourceId ? 'Src #' . $sourceId : 'Manual';
        $rateDisplay = $load['posted_rate'] ? '$' . number_format($load['posted_rate'], 2) : '—';
        $reviewDisplay = $reviewRequired ? '<span class="badge badge-warning">Review Required</span>' : '<span class="badge badge-success">Ready</span>';

        $rowsHtml .= <<<HTML
                <tr data-id="{$load['id']}">
                    <td data-label="Route" style="font-size: var(--text-sm);">
                        <strong>{$load['origin_city']}, {$load['origin_state']}</strong><br>
                        <span style="color: var(--color-text-muted);">→</span> {$load['dest_city']}, {$load['dest_state']}
                    </td>
                    <td data-label="Commodity">{$load['commodity']}</td>
                    <td data-label="Weight (lbs)" style="font-family: var(--font-mono);">{$load['weight_lbs']}</td>
                    <td data-label="Rate" style="font-family: var(--font-mono);">{$rateDisplay}</td>
                    <td data-label="Status"><span class="badge badge-{$status}">{$statusLabel}</span></td>
                    <td data-label="Review">{$reviewDisplay}</td>
                    <td data-label="Confidence">{$confBadge}</td>
                    <td data-label="Source">{$sourceDisplay}</td>
                    <td data-label="Created" style="font-size: var(--text-xs); color: var(--color-text-muted);">{$load['created_at']}</td>
                    <td data-label="Actions">
                        <div class="cluster" style="gap: var(--space-2);">
                            <a href="/loads/show.php?id={$load['id']}" class="btn btn-ghost btn-icon" aria-label="View load">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
                        </div>
                    </td>
                </tr>
HTML;
    }
}

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Loads</h1>
    <div class="cluster" style="gap: var(--space-2);">
        <a href="/loads/new" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Manual Entry
        </a>
        <a href="/loads/extract" class="btn btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Extract from Text
        </a>
    </div>
</div>

<div class="card">
    <div class="table-wrapper">
        <table class="data-table">
            <caption>Loads for {$companyName}</caption>
            <thead>
                <tr>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Route <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Commodity <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Weight (lbs) <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Rate <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Status <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Review <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Confidence <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Source <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col" role="columnheader"><button class="sort-btn" type="button">Created <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg></button></th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
{$rowsHtml}
            </tbody>
        </table>
    </div>
</div>
HTML;

require __DIR__ . '/includes/layout.php';