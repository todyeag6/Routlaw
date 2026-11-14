<?php
declare(strict_types=1);

/**
 * ROUTLAW — Email-Derived Candidate Load (Read-Only Review)
 * FR-011: ingested approved email creates a reviewable candidate load
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Headers;
use Routlaw\Loads\LoadService;
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

if ($companyId === 0) {
    http_response_code(403);
    exit('Forbidden');
}

$sourceId = (int) ($_GET['source_id'] ?? 0);
if ($sourceId === 0) {
    http_response_code(400);
    exit('Missing source_id');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$loadService = new LoadService($db);
$sourceRecordService = new SourceRecordService($db);

// Fetch source record (the email)
$sourceRecord = $sourceRecordService->getForCompany($companyId, $sourceId);
if (!$sourceRecord) {
    http_response_code(404);
    exit('Source record not found');
}

// Fetch loads linked to this source
$loads = [];
$stmt = $db->prepare('SELECT * FROM loads WHERE source_record_id = ? AND company_id = ? AND deleted_at IS NULL ORDER BY id DESC');
$stmt->bind_param('ii', $sourceId, $companyId);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $loads[] = $row;
    }
}
$stmt->close();

$pageTitle = 'Email Load: ' . htmlspecialchars($sourceRecord['external_id'] ?? 'Unknown');

// Build loads HTML
$loadsHtml = '';
if (empty($loads)) {
    $loadsHtml = '<p style="color: var(--color-text-muted);">No candidate loads created from this email yet.</p>';
} else {
    foreach ($loads as $load) {
        $reviewRequired = ($load['review_required'] ?? 0) === 1;
        $conf = $load['extraction_confidence'] ?? '—';
        if ($conf !== '—') {
            $confColor = $conf === 'high' ? 'success' : ($conf === 'medium' ? 'warning' : 'danger');
            $confBadge = '<span class="badge" style="background-color: var(--color-' . $confColor . '-subtle); color: var(--color-' . $confColor . ');">' . ucfirst($conf) . '</span>';
        } else {
            $confBadge = '<span style="color: var(--color-text-muted);">—</span>';
        }

        $rateDisplay = $load['posted_rate'] ? '$' . number_format($load['posted_rate'], 2) : 'No rate';
        $status = $load['status'] ?? 'new';
        $reviewDisplay = $reviewRequired ? '<span class="badge badge-warning">Review Required</span>' : '<span class="badge badge-success">Ready</span>';

        $loadsHtml .= <<<HTML
            <div class="card" style="margin-bottom: var(--space-4);">
                <div class="grid grid-2" style="gap: var(--space-4);">
                    <div>
                        <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-2);">
                            <span class="badge badge-{$status}">{$status}</span>
                            {$reviewDisplay}
                            {$confBadge}
                        </div>
                        <div style="font-size: var(--text-sm);">
                            <strong>{$load['origin_city']}, {$load['origin_state']}</strong> → {$load['dest_city']}, {$load['dest_state']}<br>
                            {$load['commodity']} • {$load['weight_lbs']} lbs • {$rateDisplay}
                        </div>
                    </div>
                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: var(--space-2);">
                        <a href="/loads/show.php?id={$load['id']}" class="btn btn-primary" style="width: 100%; justify-content: center;">Review</a>
                    </div>
                </div>
            </div>
HTML;
    }
}

// Build source record fields
$externalId = htmlspecialchars($sourceRecord['external_id'] ?? '—');
$receivedAt = htmlspecialchars($sourceRecord['received_at'] ?? '—');
$contentType = htmlspecialchars($sourceRecord['content_type'] ?? '—');
$canonicalPayload = htmlspecialchars($sourceRecord['canonical_payload'] ?? '—');

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Email Load Candidate</h1>
    <div class="cluster">
        <span style="color: var(--color-text-muted); font-size: var(--text-sm);">Source: {$externalId}</span>
        <a href="/loads" class="btn btn-ghost">Back to Loads</a>
    </div>
</div>

<div class="grid" style="grid-template-columns: 1fr; gap: var(--space-6);">
    @media (min-width: 1024px) { .grid { grid-template-columns: 2fr 1fr; } }
</style>

    <!-- Candidate Loads -->
    <div class="card">
        <h2 class="section-title">Candidate Loads (Review Required)</h2>
        <p style="color: var(--color-text-muted); font-size: var(--text-sm); margin-bottom: var(--space-4);">
            These loads were created from an approved email. They are <strong>not auto-published</strong> — review each before accepting.
        </p>

        {$loadsHtml}
    </div>

    <!-- Email Source -->
    <div class="card">
        <h2 class="section-title">Original Email</h2>
        <div style="font-size: var(--text-sm);">
            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">External ID</div>
            <div style="font-family: var(--font-mono); font-size: var(--text-xs);">{$externalId}</div>

            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">Received</div>
            <div style="font-size: var(--text-xs); color: var(--color-text-muted);">{$receivedAt}</div>

            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">Content Type</div>
            <div>{$contentType}</div>

            <div style="color: var(--color-text-muted); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">Original Content</div>
            <pre style="background: var(--color-primary); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); overflow-x: auto; max-height: 400px; font-size: var(--text-xs);">{$canonicalPayload}</pre>
        </div>
    </div>
</div>
HTML;

require __DIR__ . '/includes/layout.php';