<?php
declare(strict_types=1);

/**
 * ROUTLAW — Manual Load Entry
 * FR-010: authorized users create loads with required pickup/delivery/commodity/weight
 * Missing required fields flagged for review
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
$actorId = $userId;

if ($companyId === 0) {
    http_response_code(403);
    exit('Forbidden');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$loadService = new LoadService($db);
$sourceRecordService = new SourceRecordService($db);

$requiredFields = ['origin_city', 'origin_state', 'dest_city', 'dest_state', 'commodity', 'weight_lbs'];
$errors = [];
$formData = [
    'origin_city' => '',
    'origin_state' => '',
    'dest_city' => '',
    'dest_state' => '',
    'commodity' => '',
    'weight_lbs' => '',
    'posted_rate' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'origin_city' => trim($_POST['origin_city'] ?? ''),
        'origin_state' => strtoupper(trim($_POST['origin_state'] ?? '')),
        'dest_city' => trim($_POST['dest_city'] ?? ''),
        'dest_state' => strtoupper(trim($_POST['dest_state'] ?? '')),
        'commodity' => trim($_POST['commodity'] ?? ''),
        'weight_lbs' => trim($_POST['weight_lbs'] ?? ''),
        'posted_rate' => trim($_POST['posted_rate'] ?? '')
    ];

    // Validate required fields
    foreach ($requiredFields as $field) {
        if ($formData[$field] === '' || $formData[$field] === '0') {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }

    // Validate weight is numeric
    if ($formData['weight_lbs'] !== '' && !ctype_digit($formData['weight_lbs'])) {
        $errors['weight_lbs'] = 'Weight must be a positive integer.';
    }

    // Validate rate format
    if ($formData['posted_rate'] !== '' && !preg_match('/^\d+(\.\d{1,2})?$/', $formData['posted_rate'])) {
        $errors['posted_rate'] = 'Rate must be a valid number (e.g., 1850.00).';
    }

    // Validate state codes (2 letters)
    foreach (['origin_state', 'dest_state'] as $field) {
        if ($formData[$field] !== '' && !preg_match('/^[A-Z]{2}$/', $formData[$field])) {
            $errors[$field] = 'State must be a 2-letter code (e.g., TX).';
        }
    }

    if (empty($errors)) {
        try {
            // Create source record for manual entry
            $payload = json_encode($formData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $srcId = $sourceRecordService->create(
                $companyId,
                'manual_entry',
                null,
                hash('sha256', $payload),
                'Manual load entry',
                $payload,
                'application/json',
                null
            );

            $loadId = $loadService->createManualLoad($companyId, $formData, $actorId, $srcId);

            header('Location: /loads/show.php?id=' . $loadId . '&created=1');
            exit;
        } catch (\RuntimeException $e) {
            $errors['form'] = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Load (Manual)';

// Pre-compute form values for heredoc
$originCityVal = htmlspecialchars($formData['origin_city']);
$originStateVal = htmlspecialchars($formData['origin_state']);
$destCityVal = htmlspecialchars($formData['dest_city']);
$destStateVal = htmlspecialchars($formData['dest_state']);
$commodityVal = htmlspecialchars($formData['commodity']);
$weightLbsVal = htmlspecialchars($formData['weight_lbs']);
$postedRateVal = htmlspecialchars($formData['posted_rate']);

$originCityErr = $errors['origin_city'] ?? '';
$originStateErr = $errors['origin_state'] ?? '';
$destCityErr = $errors['dest_city'] ?? '';
$destStateErr = $errors['dest_state'] ?? '';
$commodityErr = $errors['commodity'] ?? '';
$weightLbsErr = $errors['weight_lbs'] ?? '';
$postedRateErr = $errors['posted_rate'] ?? '';
$formErr = $errors['form'] ?? '';

$originCityClass = $originCityErr ? ' error' : '';
$originStateClass = $originStateErr ? ' error' : '';
$destCityClass = $destCityErr ? ' error' : '';
$destStateClass = $destStateErr ? ' error' : '';
$commodityClass = $commodityErr ? ' error' : '';
$weightLbsClass = $weightLbsErr ? ' error' : '';
$postedRateClass = $postedRateErr ? ' error' : '';

$originCityErrHtml = $originCityErr ? '<span class="form-error">' . htmlspecialchars($originCityErr) . '</span>' : '';
$originStateErrHtml = $originStateErr ? '<span class="form-error">' . htmlspecialchars($originStateErr) . '</span>' : '';
$destCityErrHtml = $destCityErr ? '<span class="form-error">' . htmlspecialchars($destCityErr) . '</span>' : '';
$destStateErrHtml = $destStateErr ? '<span class="form-error">' . htmlspecialchars($destStateErr) . '</span>' : '';
$commodityErrHtml = $commodityErr ? '<span class="form-error">' . htmlspecialchars($commodityErr) . '</span>' : '';
$weightLbsErrHtml = $weightLbsErr ? '<span class="form-error">' . htmlspecialchars($weightLbsErr) . '</span>' : '';
$postedRateErrHtml = $postedRateErr ? '<span class="form-error">' . htmlspecialchars($postedRateErr) . '</span>' : '';
$formErrHtml = $formErr ? '<div class="alert alert-danger" style="margin-bottom: var(--space-4);"><div class="alert-content"><div class="alert-title">Error</div><div class="alert-message">' . htmlspecialchars($formErr) . '</div></div></div>' : '';

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Create Load (Manual Entry)</h1>
    <a href="/loads" class="btn btn-ghost">Back to Loads</a>
</div>

<div class="card" style="max-width: 800px;">
    <form method="post" action="/loads/new" novalidate>
        <div class="alert alert-info" style="margin-bottom: var(--space-4);">
            <div class="alert-content">
                <div class="alert-title">Required Fields (FR-010)</div>
                <div class="alert-message">
                    All six fields below are required for a minimum viable load. Missing fields will flag the load for review (<code>review_required = 1</code>).
                </div>
            </div>
        </div>

        <div class="form-row form-row-2">
            <div class="form-field">
                <label for="origin_city" class="form-label">Pickup City <span class="required-indicator">*</span></label>
                <input type="text" id="origin_city" name="origin_city" class="form-input{$originCityClass}" value="{$originCityVal}" required autocomplete="off">
                {$originCityErrHtml}
            </div>

            <div class="form-field">
                <label for="origin_state" class="form-label">Pickup State <span class="required-indicator">*</span></label>
                <input type="text" id="origin_state" name="origin_state" class="form-input{$originStateClass}" value="{$originStateVal}" maxlength="2" pattern="[A-Z]{2}" required autocomplete="off" style="text-transform: uppercase;">
                {$originStateErrHtml}
                <span class="form-hint">2-letter code (e.g., TX)</span>
            </div>
        </div>

        <div class="form-row form-row-2">
            <div class="form-field">
                <label for="dest_city" class="form-label">Delivery City <span class="required-indicator">*</span></label>
                <input type="text" id="dest_city" name="dest_city" class="form-input{$destCityClass}" value="{$destCityVal}" required autocomplete="off">
                {$destCityErrHtml}
            </div>

            <div class="form-field">
                <label for="dest_state" class="form-label">Delivery State <span class="required-indicator">*</span></label>
                <input type="text" id="dest_state" name="dest_state" class="form-input{$destStateClass}" value="{$destStateVal}" maxlength="2" pattern="[A-Z]{2}" required autocomplete="off" style="text-transform: uppercase;">
                {$destStateErrHtml}
                <span class="form-hint">2-letter code (e.g., GA)</span>
            </div>
        </div>

        <div class="form-row form-row-2">
            <div class="form-field">
                <label for="commodity" class="form-label">Commodity <span class="required-indicator">*</span></label>
                <input type="text" id="commodity" name="commodity" class="form-input{$commodityClass}" value="{$commodityVal}" required autocomplete="off">
                {$commodityErrHtml}
                <span class="form-hint">e.g., General Freight, Steel, Machinery</span>
            </div>

            <div class="form-field">
                <label for="weight_lbs" class="form-label">Weight (lbs) <span class="required-indicator">*</span></label>
                <input type="number" id="weight_lbs" name="weight_lbs" class="form-input{$weightLbsClass}" value="{$weightLbsVal}" min="1" required autocomplete="off">
                {$weightLbsErrHtml}
            </div>
        </div>

        <div class="form-field">
            <label for="posted_rate" class="form-label">Posted Rate (\$)</label>
            <input type="text" id="posted_rate" name="posted_rate" class="form-input{$postedRateClass}" value="{$postedRateVal}" placeholder="1850.00" autocomplete="off">
            {$postedRateErrHtml}
            <span class="form-hint">Optional. Format: 1850.00</span>
        </div>

        {$formErrHtml}

        <div class="form-field" style="margin-top: var(--space-6);">
            <button type="submit" class="btn btn-primary" style="min-width: 180px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Create Load
            </button>
            <a href="/loads" class="btn btn-secondary" style="margin-left: var(--space-3);">Cancel</a>
        </div>
    </form>
</div>
HTML;

require __DIR__ . '/includes/layout.php';