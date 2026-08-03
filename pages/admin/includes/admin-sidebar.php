<?php
declare(strict_types=1);

$currentAdminPage = $currentAdminPage ?? '';

if (!function_exists('fbgAdminNavIsActive')) {
    function fbgAdminNavIsActive(string $page, string $currentAdminPage): string
    {
        return $page === $currentAdminPage ? ' is-active' : '';
    }
}
?>

<?php
$fbgAdminScript = '/backend/js/admin.js';
if (function_exists('asset')) {
    $fbgAdminScript = asset($fbgAdminScript);
}
?>
<script src="<?= htmlspecialchars($fbgAdminScript, ENT_QUOTES, 'UTF-8') ?>"></script>

<aside class="fbg-admin-sidebar">
    <div class="fbg-admin-sidebar-brand">
        <span class="fbg-admin-sidebar-eyebrow">Frostbyt3 Gaming</span>
        <h2>Admin Panel</h2>
    </div>

    <nav class="fbg-admin-nav" aria-label="Admin navigation">

        <!-- Dashboard -->
        <a href="./page.php?name=admin-home" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-home', $currentAdminPage) ?>">
            Dashboard
        </a>

        <!-- Content -->
        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">Content</span>

            <a href="./page.php?name=admin-articles" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-articles', $currentAdminPage) ?>">
                Articles
            </a>

            <a href="./page.php?name=admin-link-shortener" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-link-shortener', $currentAdminPage) ?>">
                Short Links
            </a>

            <a href="./page.php?name=admin-service-manager" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-service-manager', $currentAdminPage) ?>">
                Service Cards
            </a>    

        </div>

        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">Management</span>

            <a href="./page.php?name=admin-users" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-users', $currentAdminPage) ?>">
                Users
            </a>

            <a href="./page.php?name=admin-registrations" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-registrations', $currentAdminPage) ?>">
                Registrations
            </a>
        </div>

        <!-- Shop -->
        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">Shop</span>

            <a href="./page.php?name=admin-payments" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-payments', $currentAdminPage) ?>">
                Payments
            </a>

            <a href="./page.php?name=admin-shop-categories" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-shop-categories', $currentAdminPage) ?>">
                Categories
            </a>

            <a href="./page.php?name=admin-shop-plans" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-shop-plans', $currentAdminPage) ?>">
                Plans
            </a>
        </div>

        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">Tools</span>

            <a href="./page.php?name=admin-file-upload" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-file-upload', $currentAdminPage) ?>">
                File Upload
            </a>

            <a href="./page.php?name=admin-image-upload" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-image-upload', $currentAdminPage) ?>">
                Image Upload
            </a>

            <a href="./page.php?name=admin-webp-png" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-webp-png', $currentAdminPage) ?>">
                WEBP to PNG Converter
            </a>
        </div>

        <!-- System -->
        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">System</span>

            <a href="./page.php?name=admin-settings" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-settings', $currentAdminPage) ?>">
                Settings
            </a>

            <a href="./page.php?name=dashboard" class="fbg-admin-nav-link">
                Back to Client Area
            </a>
        </div>

    </nav>
</aside>
