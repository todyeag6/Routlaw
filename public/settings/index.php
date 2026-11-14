<?php
declare(strict_types=1);

/**
 * ROUTLAW — Settings: Users, Roles, Audit Log
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Headers;
use Routlaw\Security\AuditLog;
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
$actorRoleId = (int) ($_SESSION['routlaw_role_id'] ?? 0);
$roleName = $_SESSION['routlaw_role_name'] ?? '—';
$companyName = $_SESSION['routlaw_company_name'] ?? '—';

// Check if user is company_admin or super_admin
$adminRoles = ['super_admin', 'company_admin'];
$isAdmin = false;
$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$stmt = $db->prepare('SELECT slug FROM roles WHERE id = ?');
$stmt->bind_param('i', $actorRoleId);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $isAdmin = in_array($row['slug'], $adminRoles, true);
}
$stmt->close();

$auditLog = new AuditLog($db);

$pageTitle = 'Settings';

// Build users table if admin
$usersHtml = '';
if ($isAdmin) {
    $users = [];
    $stmt = $db->prepare('SELECT u.*, r.name as role_name, r.slug as role_slug FROM users u JOIN roles r ON u.role_id = r.id WHERE u.company_id = ? AND u.deleted_at IS NULL ORDER BY u.created_at');
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    $stmt->close();

    $statusBadges = [
        'pending_verify' => 'badge-new',
        'active' => 'badge-active',
        'suspended' => 'badge-inactive',
        'archived' => 'badge-archived'
    ];

    foreach ($users as $u) {
        $status = $u['status'] ?? 'unknown';
        $badgeClass = $statusBadges[$status] ?? 'badge-new';
        $fullName = $u['full_name'] ?? '—';
        $email = $u['email'] ?? '';
        $roleName = $u['role_name'] ?? '';
        $roleSlug = $u['role_slug'] ?? '';
        $createdAt = $u['created_at'] ?? '';

        $usersHtml .= <<<HTML
                        <tr>
                            <td>{$fullName}</td>
                            <td class="font-mono">{$email}</td>
                            <td>{$roleName} <span class="badge badge-{$roleSlug}\">{$roleSlug}</span></td>
                            <td><span class="badge {$badgeClass}\">{$status}</span></td>
                            <td class="text-sm text-muted">{$createdAt}</td>
                        </tr>
HTML;
    }
    $usersHtml = <<<HTML
    <!-- User Management -->
    <div class="card">
        <h2 class="section-title">Users</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Created</th>
                    </tr>
                </thead>
                <tbody>
{$usersHtml}
                </tbody>
            </table>
        </div>
    </div>
HTML;
}

// Build audit log table
$auditEvents = [];
$stmt = $db->prepare('SELECT * FROM audit_events WHERE company_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $auditEvents[] = $row;
    }
}
$stmt->close();

$auditHtml = '';
if (empty($auditEvents)) {
    $auditHtml = '<tr><td colspan="6" style="text-align: center; padding: var(--space-8); color: var(--color-text-muted);">No audit events yet.</td></tr>';
} else {
    foreach ($auditEvents as $event) {
        $details = $event['details_json'] ? json_decode($event['details_json'], true) : [];
        $detailStr = '';
        if ($details) {
            $detailStr = '<pre style="font-size: 10px; max-height: 100px; overflow: auto;">' . htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }

        $auditHtml .= <<<HTML
                    <tr>
                        <td class="text-sm text-muted">{$event['created_at']}</td>
                        <td><code class="text-sm">{$event['event_type']}</code></td>
                        <td>{$event['actor_type']} #{$event['actor_id']}</td>
                        <td>{$event['action']}</td>
                        <td>{$event['target_type']} #{$event['target_id']}</td>
                        <td>{$detailStr}</td>
                    </tr>
HTML;
    }
}

$adminBadge = $isAdmin ? '<span class="badge badge-success">Admin</span>' : '';

$content = <<<HTML
<div class="page-header">
    <h1 class="page-title">Settings</h1>
</div>

<div class="grid" style="grid-template-columns: 1fr; gap: var(--space-6);">
    @media (min-width: 1024px) { .grid { grid-template-columns: 1fr 1fr; } }
</style>

    <!-- Company Info -->
    <div class="card">
        <h2 class="section-title">Company</h2>
        <div style="font-size: var(--text-sm);">
            <div class="text-xs text-muted" style="text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-bottom: var(--space-1);">Name</div>
            <div class="font-medium">{$companyName}</div>

            <div class="text-xs text-muted" style="text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">Your Role</div>
            <div>{$roleName} {$adminBadge}</div>

            <div class="text-xs text-muted" style="text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">User ID</div>
            <div class="font-mono">{$userId}</div>

            <div class="text-xs text-muted" style="text-transform: uppercase; letter-spacing: var(--letter-spacing-wide); margin-top: var(--space-3); margin-bottom: var(--space-1);">Company ID</div>
            <div class="font-mono">{$companyId}</div>
        </div>
    </div>

    {$usersHtml}

    <!-- Audit Log -->
    <div class="card" style="grid-column: 1 / -1;">
        <h2 class="section-title">Audit Log (Recent)</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Event</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Target</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
{$auditHtml}
                </tbody>
            </table>
        </div>
    </div>
</div>
HTML;

require __DIR__ . '/includes/layout.php';