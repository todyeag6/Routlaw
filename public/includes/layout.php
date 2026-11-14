<?php
declare(strict_types=1);

/**
 * ROUTLAW — Shared Layout Include
 * Usage: require __DIR__ . '/../includes/layout.php'; echo $content;
 * All pages must set $pageTitle before including.
 */

// Ensure required variables
$pageTitle = $pageTitle ?? 'ROUTLAW';
$currentPath = $_SERVER['REQUEST_URI'] ?? '/';

// Navigation items
$navItems = [
    ['href' => '/', 'label' => 'Dashboard', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>'],
    ['href' => '/carriers', 'label' => 'Carriers', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><path d="M6 12V7c0-1.1.9-2 2-2h8a2 2 0 0 1 2 2v5"/><line x1="6" y1="19" x2="6.01" y2="19"/><line x1="18" y1="19" x2="18.01" y2="19"/></svg>'],
    ['href' => '/leads', 'label' => 'Leads', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    ['href' => '/loads', 'label' => 'Loads', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>'],
    ['href' => '/settings', 'label' => 'Settings', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>']
];

// User session data (populated by auth middleware)
$userName = $_SESSION['user_name'] ?? 'User';
$userRole = $_SESSION['user_role'] ?? '';
$companyName = $_SESSION['company_name'] ?? '';

// Determine active nav
$activeNav = '';
foreach ($navItems as $item) {
    if ($currentPath === $item['href'] || ($item['href'] !== '/' && str_starts_with($currentPath, $item['href'] . '/'))) {
        $activeNav = $item['href'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> | ROUTLAW</title>

    <!-- CSP: no inline styles/scripts -->
    <link rel="preload" as="style" href="/assets/css/theme.css">
    <link rel="stylesheet" href="/assets/css/theme.css">
    <link rel="stylesheet" href="/assets/css/reset.css">
    <link rel="stylesheet" href="/assets/css/layout.css">
    <link rel="stylesheet" href="/assets/css/components.css">

    <!-- Theme init script (CSP-safe: external classic script in <head>) -->
    <script src="/assets/js/theme-init.js"></script>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <header class="app-header" role="banner">
        <a href="/" class="brand" aria-label="ROUTLAW Home">
            <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <span class="brand-text">ROUTLAW</span>
        </a>

        <nav class="main-nav" aria-label="Main navigation">
            <button class="nav-toggle" aria-expanded="false" aria-controls="nav-drawer" aria-label="Toggle navigation">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>

            <div class="nav-drawer-overlay" id="nav-drawer-overlay" role="dialog" aria-label="Navigation overlay"></div>

            <div class="nav-drawer" id="nav-drawer" role="dialog" aria-label="Navigation">
                <div class="nav-drawer-header">
                    <span class="nav-drawer-title">Navigation</span>
                    <button class="nav-drawer-close" type="button" aria-label="Close navigation">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                <ul class="nav-list">
                    <?php foreach ($navItems as $item): ?>
                        <li class="nav-item">
                            <a href="<?= htmlspecialchars($item['href']) ?>"
                               class="nav-link"
                               <?= $activeNav === $item['href'] ? 'aria-current="page"' : '' ?>
                               data-nav-item>
                                <?= $item['icon'] ?>
                                <span><?= htmlspecialchars($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="nav-user">
                    <span class="user-name"><?= htmlspecialchars($userName) ?>
                        <?php if ($userRole): ?>
                            <span style="font-size: var(--text-xs); color: var(--color-text-muted); font-weight: var(--font-weight-normal); margin-left: var(--space-2);">
                                (<?= htmlspecialchars($userRole) ?>)
                            </span>
                        <?php endif; ?>
                        <?php if ($companyName): ?>
                            <br><span style="font-size: var(--text-xs); color: var(--color-text-muted); font-weight: var(--font-weight-normal);">
                                <?= htmlspecialchars($companyName) ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <a href="/auth/logout.php" class="btn btn-ghost">Sign out</a>
                </div>
            </div>
        </nav>
    </header>

    <main class="app-main" id="main-content" role="main">
        <?= $content ?? '' ?>
    </main>

    <footer class="app-footer" role="contentinfo">
        <p>&copy; <?= date('Y') ?> ROUTLAW. All rights reserved.</p>
    </footer>

    <!-- Progressive enhancement JS (deferred module) -->
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>