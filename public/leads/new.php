<?php
declare(strict_types=1);

/**
 * ROUTLAW — Add Lead (Typed Lead Capture + Conservative Dedup)
 * FR-008: create typed leads (carrier/broker/shipper/general/document)
 * FR-009: flag potential duplicates via normalized identifiers
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

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$leadService = new LeadService($db);
$sourceRecordService = new SourceRecordService($db);

$leadTypes = ['carrier', 'broker', 'shipper', 'general', 'document'];
$errors = [];
$formData = [
    'lead_type' => 'general',
    'email' => '',
    'phone' => '',
    'name' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'lead_type' => $_POST['lead_type'] ?? 'general',
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'name' => trim($_POST['name'] ?? '')
    ];

    // Validate lead type
    if (!in_array($formData['lead_type'], $leadTypes, true)) {
        $errors['lead_type'] = 'Invalid lead type.';
    }

    // At least one contact field required
    if ($formData['email'] === '' && $formData['phone'] === '' && $formData['name'] === '') {
        $errors['contact'] = 'At least one of email, phone, or name is required.';
    }

    // Email format
    if ($formData['email'] !== '' && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format.';
    }

    if (empty($errors)) {
        try {
            $contact = [
                'email' => $formData['email'] ?: null,
                'phone' => $formData['phone'] ?: null,
                'name' => $formData['name'] ?: null
            ];

            $leadId = $leadService->createLead($companyId, $formData['lead_type'], $contact, $actorId);

            // Conservative dedup flagging (FR-009)
            $dupOf = $leadService->flagDuplicate($companyId, $leadId, $contact);

            if ($dupOf) {
                header('Location: /leads/resolve.php?id=' . $leadId . '&dup_of=' . $dupOf . '&created=1');
            } else {
                header('Location: /leads/show.php?id=' . $leadId . '&created=1');
            }
            exit;
        } catch (\RuntimeException $e) {
            $errors['form'] = $e->getMessage();
        }
    }
}

$pageTitle = 'Add Lead';

// Pre-compute form values
$leadTypeErrorClass = $errors['lead_type'] ? ' error' : '';
$leadTypeErrorHtml = $errors['lead_type'] ? '<span class="form-error">' . htmlspecialchars($errors['lead_type']) . '</span>' : '';

$emailErrorClass = $errors['email'] ? ' error' : '';
$emailErrorHtml = $errors['email'] ? '<span class="form-error">' . htmlspecialchars($errors['email']) . '</span>' : '';

$contactErrorHtml = $errors['contact'] ? '<div class="form-field"><span class="form-error">' . htmlspecialchars($errors['contact']) . '</span></div>' : '';

$formErrorHtml = $errors['form'] ? '<div class="alert alert-danger" style="margin-bottom: var(--space-4);"><div class="alert-content"><div class="alert-title">Error</div><div class="alert-message">' . htmlspecialchars($errors['form']) . '</div></div></div>' : '';

// Build selected options
$carrierSelected = $formData['lead_type'] === 'carrier' ? ' selected' : '';
$brokerSelected = $formData['lead_type'] === 'broker' ? ' selected' : '';
$shipperSelected = $formData['lead_type'] === 'shipper' ? ' selected' : '';
$generalSelected = $formData['lead_type'] === 'general' ? ' selected' : '';
$documentSelected = $formData['lead_type'] === 'document' ? ' selected' : '';

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Add Lead</h1>
    <a href="/leads" class="btn btn-ghost">Back to Leads</a>
</div>

<div class="card" style="max-width: 640px;">
    <form method="post" action="/leads/new" novalidate>
        <div class="form-field">
            <label for="lead_type" class="form-label">Lead Type <span class="required-indicator">*</span></label>
            <select id="lead_type" name="lead_type" class="form-input{$leadTypeErrorClass}" required>
                <option value="carrier"{$carrierSelected}>Carrier</option>
                <option value="broker"{$brokerSelected}>Broker</option>
                <option value="shipper"{$shipperSelected}>Shipper</option>
                <option value="general"{$generalSelected}>General</option>
                <option value="document"{$documentSelected}>Document</option>
            </select>
            {$leadTypeErrorHtml}
        </div>

        <div class="form-field">
            <label for="name" class="form-label">Name</label>
            <input type="text" id="name" name="name" class="form-input" value="{$formData['name']}" autocomplete="name">
            <span class="form-hint">Contact person or company name</span>
        </div>

        <div class="form-row form-row-2">
            <div class="form-field">
                <label for="email" class="form-label">Email</label>
                <input type="email" id="email" name="email" class="form-input{$emailErrorClass}" value="{$formData['email']}" autocomplete="email">
                {$emailErrorHtml}
            </div>

            <div class="form-field">
                <label for="phone" class="form-label">Phone</label>
                <input type="tel" id="phone" name="phone" class="form-input" value="{$formData['phone']}" autocomplete="tel">
            </div>
        </div>

        {$contactErrorHtml}
        {$formErrorHtml}

        <div class="alert alert-info" style="margin-bottom: var(--space-4);">
            <div class="alert-content">
                <div class="alert-title">Conservative Deduplication (FR-009)</div>
                <div class="alert-message">
                    If this lead matches an existing lead by normalized email, phone, or name, it will be flagged as "Possible Duplicate" for manual review. No automatic merging occurs.
                </div>
            </div>
        </div>

        <div class="form-field" style="margin-top: var(--space-6);">
            <button type="submit" class="btn btn-primary" style="min-width: 180px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Create Lead
            </button>
            <a href="/leads" class="btn btn-secondary" style="margin-left: var(--space-3);">Cancel</a>
        </div>
    </form>
</div>
HTML;

require __DIR__ . '/includes/layout.php';