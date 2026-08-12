<?php
declare(strict_types=1);

$fbgSidebarCurrent = isset($fbgSidebarCurrent) ? (string)$fbgSidebarCurrent : 'dashboard';
$fbgSidebarTitle = isset($fbgSidebarTitle) ? (string)$fbgSidebarTitle : 'Dashboard';
$fbgSidebarEyebrow = isset($fbgSidebarEyebrow) ? (string)$fbgSidebarEyebrow : 'Server Panel';
$fbgSidebarDashboardUrl = isset($dashboardPageUrl) ? (string)$dashboardPageUrl : './page.php?name=dashboard';
$fbgSidebarServersUrl = isset($serversPageUrl) ? (string)$serversPageUrl : './page.php?name=servers';
$fbgSidebarAccountUrl = isset($accountPageUrl) ? (string)$accountPageUrl : './page.php?name=account';
$fbgSidebarCreditUrl = isset($creditPageUrl) ? (string)$creditPageUrl : './page.php?name=credit';
$fbgSidebarDiscordUrl = isset($discordUrl) ? (string)$discordUrl : 'https://frostbyt3gaming.com/discord';
?>
<button
    type="button"
    class="fbg-sidebar-mobile-toggle"
    id="fbg-sidebar-mobile-toggle"
    aria-controls="fbg-shared-sidebar"
    aria-expanded="false"
    aria-label="Open sidebar"
>
    <span aria-hidden="true">&gt;</span>
</button>

<div class="fbg-sidebar-mobile-backdrop" id="fbg-sidebar-mobile-backdrop" hidden></div>

<aside class="fbg-dashboard-sidebar" id="fbg-shared-sidebar" aria-hidden="false">
    <button
        type="button"
        class="fbg-sidebar-mobile-close"
        id="fbg-sidebar-mobile-close"
        aria-label="Close sidebar"
    >
        <span aria-hidden="true">&lt;</span>
    </button>

    <div class="fbg-admin-sidebar-brand">
        <span class="fbg-admin-sidebar-eyebrow"><?php echo htmlspecialchars($fbgSidebarEyebrow, ENT_QUOTES, 'UTF-8'); ?></span>
        <h2><?php echo htmlspecialchars($fbgSidebarTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>

    <nav class="fbg-dashboard-sidebar-nav" aria-label="Sidebar navigation">
        <div class="fbg-dashboard-sidebar-group">
            <span class="fbg-admin-sidebar-eyebrow">Server Panel</span>
            <a href="<?php echo htmlspecialchars($fbgSidebarDashboardUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fbg-admin-nav-link <?php echo $fbgSidebarCurrent === 'dashboard' ? 'is-active' : ''; ?>">
                <i class="fas fa-house"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?php echo htmlspecialchars($fbgSidebarServersUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fbg-admin-nav-link <?php echo $fbgSidebarCurrent === 'servers' ? 'is-active' : ''; ?>">
                <i class="fas fa-server"></i>
                <span>Servers</span>
            </a>
        </div>

        <div class="fbg-dashboard-sidebar-group">
            <span class="fbg-admin-sidebar-eyebrow">Account</span>
            <a href="<?php echo htmlspecialchars($fbgSidebarAccountUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fbg-admin-nav-link <?php echo $fbgSidebarCurrent === 'account' ? 'is-active' : ''; ?>">
                <i class="fas fa-user"></i>
                <span>User Profile</span>
            </a>
            <a href="<?php echo htmlspecialchars($fbgSidebarCreditUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fbg-admin-nav-link <?php echo $fbgSidebarCurrent === 'credit' ? 'is-active' : ''; ?>">
                <i class="fas fa-wallet"></i>
                <span>Manage Balance</span>
            </a>
        </div>
    </nav>

    <section class="fbg-dashboard-help-card" aria-labelledby="sidebar-help-title">
        <h3 id="sidebar-help-title">Need Help?</h3>
        <p>Join our community Discord for support and updates.</p>
        <a href="<?php echo htmlspecialchars($fbgSidebarDiscordUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn fbg-primary-button" target="_blank" rel="noopener noreferrer">
            <i class="fab fa-discord"></i>
            <span>Join Discord</span>
        </a>
    </section>
</aside>

<script>
(() => {
    if (window.__fbgMobileSidebarBound) {
        return;
    }

    window.__fbgMobileSidebarBound = true;

    const body = document.body;
    const toggle = document.getElementById('fbg-sidebar-mobile-toggle');
    const close = document.getElementById('fbg-sidebar-mobile-close');
    const sidebar = document.getElementById('fbg-shared-sidebar');
    const backdrop = document.getElementById('fbg-sidebar-mobile-backdrop');
    const mobileQuery = window.matchMedia('(max-width: 900px)');

    if (!toggle || !close || !sidebar || !backdrop) {
        return;
    }

    const setOpen = (isOpen) => {
        const shouldOpen = Boolean(isOpen) && mobileQuery.matches;

        body.classList.toggle('fbg-sidebar-mobile-open', shouldOpen);
        toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : (mobileQuery.matches ? 'true' : 'false'));
        toggle.hidden = shouldOpen && mobileQuery.matches;
        backdrop.hidden = !shouldOpen;
    };

    const syncDesktopState = () => {
        if (!mobileQuery.matches) {
            body.classList.remove('fbg-sidebar-mobile-open');
            toggle.hidden = true;
            backdrop.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            sidebar.setAttribute('aria-hidden', 'false');
            return;
        }

        toggle.hidden = body.classList.contains('fbg-sidebar-mobile-open');
        backdrop.hidden = !body.classList.contains('fbg-sidebar-mobile-open');
        sidebar.setAttribute('aria-hidden', body.classList.contains('fbg-sidebar-mobile-open') ? 'false' : 'true');
    };

    toggle.addEventListener('click', () => setOpen(true));
    close.addEventListener('click', () => setOpen(false));
    backdrop.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    mobileQuery.addEventListener('change', syncDesktopState);
    syncDesktopState();
})();
</script>
