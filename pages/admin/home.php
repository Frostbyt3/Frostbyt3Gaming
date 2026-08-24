<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/registration.php';
requireLogin();

if (!function_exists('canAccess')) {
    require_once __DIR__ . '/../../includes/functions.php';
}

if (!canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

$adminCards = [
    [
        'title' => 'Service Cards',
        'description' => 'Manage the cards shown on the homepage for your official servers and projects.',
        'link' => '?name=admin-service-manager',
        'button' => 'Manage Cards',
    ],
    [
        'title' => 'Articles',
        'description' => 'Create, edit, and organize site articles and announcements.',
        'link' => '?name=admin-articles',
        'button' => 'Manage Articles',
    ],
    [
        'title' => 'Short Links',
        'description' => 'Create, edit and organize short links.',
        'link' => '?name=admin-link-shortener',
        'button' => 'Mange Short Links',
    ],
    /* [
        'title' => 'Pages',
        'description' => 'Control standalone pages and future CMS-driven site content.',
        'link' => '?name=admin-pages',
        'button' => 'Manage Pages',
    ], */
    [
        'title' => 'Registrations',
        'description' => 'Review pending registrations, resend verification emails, and approve accounts manually.',
        'link' => '?name=admin-registrations',
        'button' => 'Review Registrations',
    ],
    [
        'title' => 'Settings',
        'description' => 'Adjust site-wide settings, configuration, and future integrations.',
        'link' => '?name=admin-settings',
        'button' => 'Open Settings',
    ],
];

$registrationStats = [
    'active' => 0,
    'pending' => 0,
    'verified' => 0,
    'last_24_hours' => 0,
];

try {
    $registrationStats = fbgPendingRegistrationStats();
} catch (Throwable $e) {
    error_log('Admin registration stats failed: ' . $e->getMessage());
}
?>

<?php $currentAdminPage = 'admin-home'; ?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div>
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Dashboard</h1>
                <p class="fbg-admin-subtext">Manage site content, settings, and platform tools from one place.</p>
            </div>
        </header>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-wide">
                <div class="fbg-admin-panel-header">
                    <h2>Quick Actions</h2>
                </div>

                <div class="fbg-admin-card-grid">
                    <?php foreach ($adminCards as $card): ?>
                        <article class="fbg-admin-card">
                            <h3><?= htmlspecialchars((string)$card['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p><?= htmlspecialchars((string)$card['description'], ENT_QUOTES, 'UTF-8') ?></p>
                            <a href="<?= htmlspecialchars((string)$card['link'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm">
                                <?= htmlspecialchars((string)$card['button'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Overview</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Status</span>
                        <span style="color: #7ee787; font-weight: 600;">Online</span>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">CMS</span>
                        <span style="color: #7ee787; font-weight: 600;">Active</span>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Next Step</span>
                        <span style="color: #22aeff; font-weight: 600;">Server Cards Admin</span>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Registrations</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Active</span>
                        <strong><?= number_format($registrationStats['active']) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Pending Email</span>
                        <strong><?= number_format($registrationStats['pending']) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Verified</span>
                        <strong><?= number_format($registrationStats['verified']) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Last 24 Hours</span>
                        <strong><?= number_format($registrationStats['last_24_hours']) ?></strong>
                    </div>
                </div>
            </section>
        </div>
    </div>
</section>