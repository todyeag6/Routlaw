<?php
declare(strict_types=1);

/**
 * ROUTLAW — Load Extraction from Untrusted Text
 * FR-013/BR-005: structured extraction with per-field confidence + missing-field reporting
 * NEVER fabricates — absent fields = null + enumerated in missing
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Headers;
use Routlaw\Loads\LoadService;
use Routlaw\Loads\LoadExtractionService;
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

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$loadService = new LoadService($db);
$extractor = new LoadExtractionService();
$sourceRecordService = new SourceRecordService($db);

$errors = [];
$rawText = '';
$extraction = null;
$previewMode = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawText = trim($_POST['raw_text'] ?? '');

    if ($rawText === '') {
        $errors['raw_text'] = 'Please paste load text to extract.';
    } else {
        try {
            $extraction = $extractor->extract($rawText);

            // Check if this is a preview request (AJAX)
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' && isset($_POST['preview'])) {
                header('Content-Type: application/json');
                echo json_encode($extraction);
                exit;
            }

            // If not preview, this is a confirm submit
            if (isset($_POST['confirm'])) {
                // Create source record for extracted content
                $payload = json_encode([
                    'raw_text' => $rawText,
                    'extraction' => $extraction
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $srcId = $sourceRecordService->create(
                    $companyId,
                    'extraction',
                    null,
                    hash('sha256', $payload),
                    'Extracted from pasted text',
                    $payload,
                    'application/json',
                    null
                );

                $loadId = $loadService->persistExtractedLoad($companyId, $extraction, $actorId, $srcId, 'manual');

                header('Location: /loads/show.php?id=' . $loadId . '&created=1');
                exit;
            }

            // Default: show preview for confirmation
            $previewMode = true;
        } catch (\RuntimeException $e) {
            $errors['form'] = $e->getMessage();
        }
    }
}

$pageTitle = 'Extract Load from Text';

// Pre-compute values for heredoc
$rawTextVal = htmlspecialchars($rawText);
$rawTextErr = $errors['raw_text'] ?? '';
$formErr = $errors['form'] ?? '';

$rawTextClass = $rawTextErr ? ' error' : '';
$rawTextErrHtml = $rawTextErr ? '<span class="form-error">' . htmlspecialchars($rawTextErr) . '</span>' : '';
$formErrHtml = $formErr ? '<div class="alert alert-danger" style="margin-bottom: var(--space-4);"><div class="alert-content"><div class="alert-title">Error</div><div class="alert-message">' . htmlspecialchars($formErr) . '</div></div></div>' : '';

$extractionJson = $previewMode && $extraction ? json_encode($extraction, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null';

$previewHtml = '';
if ($previewMode && $extraction) {
    $previewHtml = <<<HTML
        <script>
            // Render preview immediately on page load
            document.addEventListener("DOMContentLoaded", function() {
                const extraction = {$extractionJson};
                if (window.renderExtractionPreview) {
                    window.renderExtractionPreview(extraction);
                }
                // Show confirm button
                document.getElementById("preview-btn").style.display = "none";
                document.getElementById("confirm-btn").style.display = "inline-flex";
            });
        </script>
HTML;
} else {
    $previewHtml = '<p style="color: var(--color-text-muted); text-align: center; padding: var(--space-8);">Enter text and click "Preview Extraction" to see results.</p>';
}

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Extract Load from Text</h1>
    <a href="/loads" class="btn btn-ghost">Back to Loads</a>
</div>

<div class="grid" style="grid-template-columns: 1fr; gap: var(--space-6);">
    @media (min-width: 1024px) { .grid { grid-template-columns: 1fr 1fr; } }
</style>

    <!-- Input -->
    <div class="card">
        <h2 class="section-title">Paste Load Text</h2>
        <p style="color: var(--color-text-muted); font-size: var(--text-sm); margin-bottom: var(--space-4);">
            Paste an email, load board post, or any text containing load details.
            The extractor will identify pickup/delivery, commodity, weight, and rate.
        </p>

        <form method="post" action="/loads/extract" id="extraction-form" novalidate>
            <div class="form-field">
                <label for="raw_text" class="form-label">Load Text <span class="required-indicator">*</span></label>
                <textarea id="raw_text" name="raw_text" class="form-textarea{$rawTextClass}" rows="15" placeholder="Example:
Load: Dallas, TX to Atlanta, GA
Commodity: General Freight
Weight: 24000 lbs
Rate: \$1850
Pickup: 08:00, Delivery: 14:00
Contact: John at john@example.com / 555-123-4567
MC-123456 DOT-789012
Equipment: Dry Van" required>{$rawTextVal}</textarea>
                {$rawTextErrHtml}
            </div>

            {$formErrHtml}

            <div class="cluster" style="gap: var(--space-3); flex-wrap: wrap;">
                <button type="submit" name="preview" value="1" class="btn btn-primary" id="preview-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    Preview Extraction
                </button>
                <button type="submit" name="confirm" value="1" class="btn btn-secondary" id="confirm-btn" disabled style="display: none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    Create Load
                </button>
                <a href="/loads" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Preview -->
    <div class="card">
        <h2 class="section-title">Extraction Preview</h2>
        <div id="extraction-preview">
            {$previewHtml}
        </div>
    </div>
</div>
HTML;

require __DIR__ . '/includes/layout.php';