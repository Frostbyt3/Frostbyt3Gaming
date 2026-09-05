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

<button
    type="button"
    class="fbg-admin-sidebar-mobile-toggle fbg-mobile-sidebar-handle"
    id="fbg-admin-sidebar-mobile-toggle"
    aria-controls="fbg-admin-sidebar"
    aria-expanded="false"
    aria-label="Open admin sidebar"
>
    <span class="fbg-mobile-sidebar-icon fbg-mobile-sidebar-icon-closed" aria-hidden="true">
        <i class="fas fa-angle-right"></i>
    </span>
    <span class="fbg-mobile-sidebar-icon fbg-mobile-sidebar-icon-open" aria-hidden="true">
        <i class="fas fa-angle-left"></i>
    </span>
</button>

<div class="fbg-admin-sidebar-mobile-backdrop" id="fbg-admin-sidebar-mobile-backdrop" hidden></div>

<aside class="fbg-admin-sidebar" id="fbg-admin-sidebar" aria-hidden="false">
    <button
        type="button"
        class="fbg-admin-sidebar-mobile-close fbg-mobile-sidebar-handle"
        id="fbg-admin-sidebar-mobile-close"
        aria-label="Close admin sidebar"
    >
        <i class="fas fa-angle-left" aria-hidden="true"></i>
    </button>

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

            <a href="./page.php?name=admin-database-hosts" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-database-hosts', $currentAdminPage) ?>">
                Database Hosts
            </a>

            <a href="./page.php?name=admin-locations" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-locations', $currentAdminPage) ?>">
                Locations
            </a>

            <a href="./page.php?name=admin-nodes" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-nodes', $currentAdminPage) ?>">
                Nodes
            </a>

            <a href="./page.php?name=admin-users" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-users', $currentAdminPage) ?>">
                Users
            </a>

            <a href="./page.php?name=admin-servers" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-servers', $currentAdminPage) ?>">
                Servers
            </a>

            <a href="./page.php?name=admin-registrations" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-registrations', $currentAdminPage) ?>">
                Registrations
            </a>
        </div>

        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">Service Management</span>
            
            <a href="./page.php?name=admin-nests" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-nests', $currentAdminPage) ?>">
                Nests & Eggs
            </a>
        </div>

        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">Receipt</span>

            <a href="./page.php?name=admin-receipt-settings" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-receipt-settings', $currentAdminPage) ?>">
                Receipt Settings
            </a>

            <a href="./page.php?name=admin-receipts" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-receipts', $currentAdminPage) ?>">
                Receipts
            </a>
        </div>

        <!-- Shop -->
        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">Shop Settings</span>

            <a href="./page.php?name=admin-payments" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-payments', $currentAdminPage) ?>">
                Payments
            </a>

            <a href="./page.php?name=admin-shop-categories" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-shop-categories', $currentAdminPage) ?>">
                Categories
            </a>

            <a href="./page.php?name=admin-shop-plans" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-shop-plans', $currentAdminPage) ?>">
                Plans
            </a>

            <a href="./page.php?name=admin-confirmation-backgrounds" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-confirmation-backgrounds', $currentAdminPage) ?>">
                Confirmation Images
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

            <a href="./page.php?name=admin-fbcode" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-fbcode', $currentAdminPage) ?>">
                FBCode Generator
            </a>
        </div>

        <!-- System -->
        <div class="fbg-admin-nav-group">
            <span class="fbg-admin-nav-group-title">System Settings</span>

            <a href="./page.php?name=admin-settings" class="fbg-admin-nav-link<?= fbgAdminNavIsActive('admin-settings', $currentAdminPage) ?>">
                Settings
            </a>

            <a href="./page.php?name=dashboard" class="fbg-admin-nav-link">
                Back to Client Area
            </a>
        </div>

    </nav>
</aside>

<script>
    (() => {
        if (window.__fbgAdminMobileSidebarBound) {
            return;
        }

        window.__fbgAdminMobileSidebarBound = true;

        const body = document.body;
        const toggle = document.getElementById('fbg-admin-sidebar-mobile-toggle');
        const close = document.getElementById('fbg-admin-sidebar-mobile-close');
        const sidebar = document.getElementById('fbg-admin-sidebar');
        const backdrop = document.getElementById('fbg-admin-sidebar-mobile-backdrop');
        const mobileQuery = window.matchMedia('(max-width: 1100px)');
        const openClass = 'fbg-admin-sidebar-mobile-open';
        const draggingClass = 'fbg-admin-sidebar-mobile-dragging';
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
            toggle.setAttribute('aria-label', shouldOpen ? 'Close admin sidebar' : 'Open admin sidebar');
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
                toggle.setAttribute('aria-label', 'Open admin sidebar');
                sidebar.setAttribute('aria-hidden', 'false');
                syncToggleIcon(false);
                return;
            }

            const sidebarIsOpen = isOpen();

            toggle.hidden = false;
            toggle.setAttribute('aria-expanded', sidebarIsOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', sidebarIsOpen ? 'Close admin sidebar' : 'Open admin sidebar');
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
