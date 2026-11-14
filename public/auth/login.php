<?php
declare(strict_types=1);

/**
 * ROUTLAW — Login Page
 */

require __DIR__ . '/../../config/autoload.php';

use Routlaw\Security\Auth;
use Routlaw\Security\Headers;
use Routlaw\Db\Connection;

Headers::emit(false);

// If already logged in, redirect to dashboard
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

$db = Connection::connect($_ENV['RL_DB_NAME'] ?? 'routlaw');
$auth = new Auth($db);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors['form'] = 'Email and password are required.';
    } else {
        $user = $auth->verifyCredentials($email, $password);
        if ($user) {
            $auth->login($user);
            header('Location: /');
            exit;
        } else {
            $errors['form'] = 'Invalid email or password.';
        }
    }
}

$pageTitle = 'Sign In';

// No layout for login page - standalone
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In | ROUTLAW</title>

    <link rel="preload" as="style" href="/assets/css/theme.css">
    <link rel="stylesheet" href="/assets/css/theme.css">
    <link rel="stylesheet" href="/assets/css/reset.css">
    <link rel="stylesheet" href="/assets/css/layout.css">
    <link rel="stylesheet" href="/assets/css/components.css">

    <script src="/assets/js/theme-init.js"></script>
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
</head>
<body style="min-height: 100svh; display: flex; align-items: center; justify-content: center; padding: var(--space-4);">
    <div class="card" style="width: 100%; max-width: 400px;">
        <div style="text-align: center; margin-bottom: var(--space-8);">
            <a href="/" class="brand" style="justify-content: center; font-size: var(--text-2xl);">
                <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                    <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
                <span class="brand-text">ROUTLAW</span>
            </a>
        </div>

        <h1 class="page-title" style="text-align: center; margin-bottom: var(--space-2);">Sign In</h1>
        <p style="text-align: center; color: var(--color-text-muted); margin-bottom: var(--space-6);">Enter your credentials to access the dashboard</p>

        {$errors['form'] ? '<div class="alert alert-danger" style="margin-bottom: var(--space-4);"><div class="alert-content"><div class="alert-title">Error</div><div class="alert-message">' . htmlspecialchars($errors['form']) . '</div></div></div>' : ''}

        <form method="post" action="/auth/login.php" novalidate>
            <div class="form-field">
                <label for="email" class="form-label">Email</label>
                <input type="email" id="email" name="email" class="form-input" required autocomplete="email" autofocus>
            </div>

            <div class="form-field">
                <label for="password" class="form-label">Password</label>
                <input type="password" id="password" name="password" class="form-input" required autocomplete="current-password">
            </div>

            <div class="form-field" style="margin-top: var(--space-6);">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>
                    Sign In
                </button>
            </div>
        </form>

        <p style="text-align: center; color: var(--color-text-muted); font-size: var(--text-sm); margin-top: var(--space-6);">
            No account? Contact your company administrator.
        </p>
    </div>
</body>
</html>