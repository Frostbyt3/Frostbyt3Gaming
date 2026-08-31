<?php
    declare(strict_types=1);

    $fbgSidebarPageName = strtolower(trim((string)($_GET['name'] ?? '')));

    if ($fbgSidebarPageName === '') {
        $fbgSidebarPageName = strtolower(pathinfo((string)($_SERVER['SCRIPT_NAME'] ?? ''), PATHINFO_FILENAME));
    }

    if ($fbgSidebarPageName === 'credit') {
        $fbgSidebarPageName = 'wallet';
    }

    $fbgSidebarTitles = [
        'dashboard' => 'Dashboard',
        'servers' => 'Game Servers',
        'account' => 'User Profile',
        'wallet' => 'Manage Wallet',
    ];

    $fbgSidebarCurrent = isset($fbgSidebarCurrent) ? (string)$fbgSidebarCurrent : $fbgSidebarPageName;
    $fbgSidebarTitle = isset($fbgSidebarTitle) ? (string)$fbgSidebarTitle : ($fbgSidebarTitles[$fbgSidebarCurrent] ?? 'Dashboard');
    $fbgSidebarEyebrow = isset($fbgSidebarEyebrow) ? (string)$fbgSidebarEyebrow : 'Server Panel';
    $fbgSidebarDashboardUrl = './page.php?name=dashboard';
    $fbgSidebarServersUrl = './page.php?name=servers';
    $fbgSidebarAccountUrl = './page.php?name=account';
    $fbgSidebarCreditUrl = './page.php?name=wallet';
    $fbgSidebarDiscordUrl = 'https://frostbyt3gaming.com/discord';
?>
<button
    type="button"
    class="fbg-sidebar-mobile-toggle fbg-mobile-sidebar-handle"
    id="fbg-sidebar-mobile-toggle"
    aria-controls="fbg-shared-sidebar"
    aria-expanded="false"
    aria-label="Open sidebar"
>
    <span class="fbg-mobile-sidebar-icon fbg-mobile-sidebar-icon-closed" aria-hidden="true">
        <i class="fas fa-angle-right"></i>
    </span>
    <span class="fbg-mobile-sidebar-icon fbg-mobile-sidebar-icon-open" aria-hidden="true">
        <i class="fas fa-angle-left"></i>
    </span>
</button>

<div class="fbg-sidebar-mobile-backdrop" id="fbg-sidebar-mobile-backdrop" hidden></div>

<aside class="fbg-dashboard-sidebar" id="fbg-shared-sidebar" aria-hidden="false">
    <button
        type="button"
        class="fbg-sidebar-mobile-close fbg-mobile-sidebar-handle"
        id="fbg-sidebar-mobile-close"
        aria-label="Close sidebar"
    >
        <i class="fas fa-angle-left" aria-hidden="true"></i>
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
            <a href="<?php echo htmlspecialchars($fbgSidebarCreditUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fbg-admin-nav-link <?php echo $fbgSidebarCurrent === 'wallet' ? 'is-active' : ''; ?>">
                <i class="fas fa-wallet"></i>
                <span>Manage Wallet</span>
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
        const openClass = 'fbg-sidebar-mobile-open';
        const draggingClass = 'fbg-sidebar-mobile-dragging';
        let activePointerId = null;
        let dragStartX = 0;
        let dragStartTime = 0;
        let dragStartVisibleWidth = 0;
        let currentDragVisibleWidth = 0;
        let sidebarWidth = 0;
        let handleWidth = 0;
        let handleInset = 0;
        let isDraggingHandle = false;
        let suppressNextToggleClick = false;

        if (!toggle || !close || !sidebar || !backdrop) {
            return;
        }

        const syncToggleIcon = (isOpen) => {
            toggle.classList.toggle('is-open', isOpen);
        };

        const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
        const isOpen = () => body.classList.contains(openClass);
        const getSidebarWidth = () => sidebar.getBoundingClientRect().width || 320;
        const getHandleInset = () => {
            const inset = Number.parseFloat(window.getComputedStyle(toggle).getPropertyValue('--fbg-mobile-sidebar-handle-inset'));
            return Number.isFinite(inset) ? inset : 0;
        };

        const clearDragStyles = () => {
            sidebar.style.transform = '';
            toggle.style.left = '';
            toggle.style.transform = '';
            body.classList.remove(draggingClass);
        };

        const applyDragPosition = (visibleWidth) => {
            const clampedWidth = clamp(visibleWidth, 0, sidebarWidth);
            const travelDistance = Math.max(0, sidebarWidth - handleWidth - handleInset);
            const handleOffset = sidebarWidth > 0 ? (clampedWidth / sidebarWidth) * travelDistance : 0;

            currentDragVisibleWidth = clampedWidth;
            sidebar.style.transform = `translateX(${clampedWidth - sidebarWidth}px)`;
            toggle.style.left = `${handleInset}px`;
            toggle.style.transform = `translateY(-50%) translateX(${handleOffset}px)`;
            backdrop.hidden = clampedWidth <= 4;
        };

        const setOpen = (isOpen, options = {}) => {
            const shouldOpen = Boolean(isOpen) && mobileQuery.matches;

            body.classList.toggle(openClass, shouldOpen);
            toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', shouldOpen ? 'Close sidebar' : 'Open sidebar');
            sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : (mobileQuery.matches ? 'true' : 'false'));
            toggle.hidden = !mobileQuery.matches;
            backdrop.hidden = !shouldOpen;
            syncToggleIcon(shouldOpen);

            if (!options.preserveDragStyles) {
                clearDragStyles();
            }
        };

        const syncDesktopState = () => {
            clearDragStyles();

            if (!mobileQuery.matches) {
                body.classList.remove(openClass);
                toggle.hidden = true;
                backdrop.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'Open sidebar');
                sidebar.setAttribute('aria-hidden', 'false');
                syncToggleIcon(false);
                return;
            }

            const sidebarIsOpen = isOpen();

            toggle.hidden = false;
            toggle.setAttribute('aria-expanded', sidebarIsOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', sidebarIsOpen ? 'Close sidebar' : 'Open sidebar');
            backdrop.hidden = !sidebarIsOpen;
            sidebar.setAttribute('aria-hidden', sidebarIsOpen ? 'false' : 'true');
            syncToggleIcon(sidebarIsOpen);
        };

        toggle.addEventListener('pointerdown', (event) => {
            if (!mobileQuery.matches) {
                return;
            }

            activePointerId = event.pointerId;
            dragStartX = event.clientX;
            dragStartTime = performance.now();
            sidebarWidth = getSidebarWidth();
            handleWidth = toggle.getBoundingClientRect().width || 30;
            handleInset = getHandleInset();
            dragStartVisibleWidth = isOpen() ? sidebarWidth : 0;
            currentDragVisibleWidth = dragStartVisibleWidth;
            isDraggingHandle = false;
            toggle.setPointerCapture?.(event.pointerId);
        });

        toggle.addEventListener('pointermove', (event) => {
            if (!mobileQuery.matches || activePointerId !== event.pointerId) {
                return;
            }

            const dragDistance = event.clientX - dragStartX;

            if (Math.abs(dragDistance) > 4 || isDraggingHandle) {
                isDraggingHandle = true;
                body.classList.add(draggingClass);
                applyDragPosition(dragStartVisibleWidth + dragDistance);
                event.preventDefault();
            }
        });

        toggle.addEventListener('pointerup', (event) => {
            if (!mobileQuery.matches || activePointerId !== event.pointerId) {
                return;
            }

            const dragDistance = event.clientX - dragStartX;
            const elapsed = Math.max(performance.now() - dragStartTime, 1);
            const velocity = dragDistance / elapsed;

            if (isDraggingHandle) {
                applyDragPosition(dragStartVisibleWidth + dragDistance);

                const progress = sidebarWidth > 0 ? currentDragVisibleWidth / sidebarWidth : 0;
                const shouldOpen = Math.abs(velocity) > 0.45 ? velocity > 0 : progress >= 0.5;

                body.classList.remove(draggingClass);
                setOpen(shouldOpen, { preserveDragStyles: true });

                suppressNextToggleClick = true;
                window.setTimeout(() => {
                    suppressNextToggleClick = false;
                }, 0);

                requestAnimationFrame(clearDragStyles);
            }

            activePointerId = null;
            dragStartX = 0;
            isDraggingHandle = false;
        });

        toggle.addEventListener('pointercancel', () => {
            if (isDraggingHandle) {
                body.classList.remove(draggingClass);
                setOpen(isOpen(), { preserveDragStyles: true });
                requestAnimationFrame(clearDragStyles);
            }

            activePointerId = null;
            dragStartX = 0;
            isDraggingHandle = false;
        });

        toggle.addEventListener('click', () => {
            if (suppressNextToggleClick) {
                return;
            }

            setOpen(!isOpen());
        });
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
