<?php
declare(strict_types=1);

/**
 * ROUTLAW — Carrier Signup (Self-Registration)
 * FR-004: carrier self-registers with validated fields, starts at 'new' status
 * FR-005: lifecycle states enforced
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
$actorId = $userId;

if ($companyId === 0) {
    http_response_code(403);
    exit('Forbidden');
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$carrierService = new CarrierService($db);

$errors = [];
$formData = [
    'legal_name' => '',
    'doing_business_as' => '',
    'dot_number' => '',
    'mc_number' => '',
    'ein' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'legal_name' => trim($_POST['legal_name'] ?? ''),
        'doing_business_as' => trim($_POST['doing_business_as'] ?? ''),
        'dot_number' => trim($_POST['dot_number'] ?? ''),
        'mc_number' => trim($_POST['mc_number'] ?? ''),
        'ein' => trim($_POST['ein'] ?? '')
    ];

    // Server-side validation (FR-004)
    if ($formData['legal_name'] === '') {
        $errors['legal_name'] = 'Legal name is required (FR-004).';
    }

    if (empty($errors)) {
        try {
            $carrierId = $carrierService->signup(
                $companyId,
                $formData['legal_name'],
                $formData['doing_business_as'],
                $formData['dot_number'],
                $formData['mc_number'],
                $formData['ein']
            );

            // Redirect to carrier detail
            header('Location: /carriers/show.php?id=' . $carrierId . '&created=1');
            exit;
        } catch (\RuntimeException $e) {
            $errors['form'] = $e->getMessage();
        }
    }
}

$pageTitle = 'Add Carrier';

// Pre-compute form field classes and error messages
$legalNameErrorClass = $errors['legal_name'] ? ' error' : '';
$legalNameErrorHtml = $errors['legal_name'] ? '<span class="form-error">' . htmlspecialchars($errors['legal_name']) . '</span>' : '';

$dotNumberErrorClass = $errors['dot_number'] ? ' error' : '';
$dotNumberErrorHtml = $errors['dot_number'] ? '<span class="form-error">' . htmlspecialchars($errors['dot_number']) . '</span>' : '';

$mcNumberErrorClass = $errors['mc_number'] ? ' error' : '';
$mcNumberErrorHtml = $errors['mc_number'] ? '<span class="form-error">' . htmlspecialchars($errors['mc_number']) . '</span>' : '';

$formErrorHtml = $errors['form'] ? '<div class="alert alert-danger" style="margin-bottom: var(--space-4);"><div class="alert-content"><div class="alert-title">Error</div><div class="alert-message">' . htmlspecialchars($errors['form']) . '</div></div></div>' : '';

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Add Carrier</h1>
    <a href="/carriers" class="btn btn-ghost">Back to Carriers</a>
</div>

<div class="card" style="max-width: 640px;">
    <form method="post" action="/carriers/new" novalidate>
        <div class="form-field">
            <label for="legal_name" class="form-label">Legal Name <span class="required-indicator">*</span></label>
            <input type="text" id="legal_name" name="legal_name" class="form-input{$legalNameErrorClass}" value="{$formData['legal_name']}" required autocomplete="organization">
            {$legalNameErrorHtml}
            <span class="form-hint">Registered legal entity name</span>
        </div>

        <div class="form-field">
            <label for="doing_business_as" class="form-label">Doing Business As (DBA)</label>
            <input type="text" id="doing_business_as" name="doing_business_as" class="form-input" value="{$formData['doing_business_as']}" autocomplete="organization">
            <span class="form-hint">Trade name (if different from legal name)</span>
        </div>

        <div class="form-row form-row-2">
            <div class="form-field">
                <label for="dot_number" class="form-label">DOT Number</label>
                <input type="text" id="dot_number" name="dot_number" class="form-input{$dotNumberErrorClass}" value="{$formData['dot_number']}" pattern="[A-Za-z0-9\-]*" autocomplete="off">
                {$dotNumberErrorHtml}
                <span class="form-hint">Unique per tenant (FR-004)</span>
            </div>

            <div class="form-field">
                <label for="mc_number" class="form-label">MC Number</label>
                <input type="text" id="mc_number" name="mc_number" class="form-input{$mcNumberErrorClass}" value="{$formData['mc_number']}" pattern="[A-Za-z0-9\-]*" autocomplete="off">
                {$mcNumberErrorHtml}
                <span class="form-hint">Unique per tenant (FR-004)</span>
            </div>
        </div>

        <div class="form-field">
            <label for="ein" class="form-label">EIN</label>
            <input type="text" id="ein" name="ein" class="form-input" value="{$formData['ein']}" pattern="\d{2}-\d{7}" autocomplete="off">
            <span class="form-hint">Employer Identification Number (XX-XXXXXXX)</span>
        </div>

        {$formErrorHtml}

        <div class="form-field" style="margin-top: var(--space-6);">
            <button type="submit" class="btn btn-primary" style="min-width: 180px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Create Carrier
            </button>
            <a href="/carriers" class="btn btn-secondary" style="margin-left: var(--space-3);">Cancel</a>
        </div>
    </form>
</div>
HTML;

require __DIR__ . '/includes/layout.php';