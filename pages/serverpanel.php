<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../api/pterodactyl.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$panelUserId = (int)($_SESSION['user_id'] ?? 0);
$serverIdentifier = trim((string)($_GET['id'] ?? ''));

if ($panelUserId <= 0 || $serverIdentifier === '') {
    http_response_code(400);
    echo '<section class="fbg-dashboard"><div class="fbg-dashboard-alert error is-visible">Invalid server request.</div></section>';
    return;
}

if (!function_exists('canAccess')) {
    require_once __DIR__ . '/../includes/functions.php';
}

$canViewAllServers = function_exists('canAccess') ? canAccess(4) : false;

if ($panelUserId > 0 && $canViewAllServers && isset($_POST['server_scope_toggle'])) {
    $requestedScope = (string)($_POST['server_scope'] ?? 'mine');
    setShowAllServers($requestedScope === 'all');
}

/**
 * Session-first access bootstrap.
 * Only force-refresh if the requested server is missing from session access.
 */
$showAllServers = $canViewAllServers && function_exists('isShowingAllServers') ? isShowingAllServers() : false;
$includeAdminAllServers = $canViewAllServers;
$serverCacheMaxAgeSeconds = $includeAdminAllServers ? 30 : 60;

pteroEnsureServerAccessSession(false, $includeAdminAllServers, $serverCacheMaxAgeSeconds);

$allowedServers = array_values(array_filter(array_map(
    'strval',
    $_SESSION['allowed_servers'] ?? []
)));

if (!in_array($serverIdentifier, $allowedServers, true)) {
    pteroEnsureServerAccessSession(true, $includeAdminAllServers, $serverCacheMaxAgeSeconds);

    $allowedServers = array_values(array_filter(array_map(
        'strval',
        $_SESSION['allowed_servers'] ?? []
    )));
}

if (!in_array($serverIdentifier, $allowedServers, true)) {
    http_response_code(403);
    echo '<section class="fbg-dashboard"><div class="fbg-dashboard-alert error is-visible">You do not have access to that server.</div></section>';
    return;
}

$serverPermissions = array_values(array_filter(array_map(
    'strval',
    $_SESSION['server_permissions'][$serverIdentifier] ?? []
)));

$isServerOwner = !empty($_SESSION['server_is_owner'][$serverIdentifier]);
$isPanelAdmin = !empty($_SESSION['server_is_panel_admin'][$serverIdentifier]);

$selectedServer = pteroGetSessionServerMeta($serverIdentifier);
$serverMeta = is_array($_SESSION['server_meta'] ?? null) ? $_SESSION['server_meta'] : [];
$serverOwnerMap = is_array($_SESSION['server_is_owner'] ?? null) ? $_SESSION['server_is_owner'] : [];

/**
 * If session meta is missing or incomplete, try one forced refresh.
 */
if (empty($selectedServer) || empty($selectedServer['identifier'])) {
    pteroEnsureServerAccessSession(true, $includeAdminAllServers, $serverCacheMaxAgeSeconds);
    $selectedServer = pteroGetSessionServerMeta($serverIdentifier);
    $serverMeta = is_array($_SESSION['server_meta'] ?? null) ? $_SESSION['server_meta'] : [];
    $serverOwnerMap = is_array($_SESSION['server_is_owner'] ?? null) ? $_SESSION['server_is_owner'] : [];
}

if (empty($selectedServer) || empty($selectedServer['identifier'])) {
    http_response_code(503);
    echo '<section class="fbg-dashboard"><div class="fbg-dashboard-alert error is-visible">Server access was confirmed, but server details could not be loaded right now. Please try again.</div></section>';
    return;
}

$hasServerPermission = static function (string $permission) use ($serverPermissions, $isServerOwner, $isPanelAdmin): bool {
    return $isServerOwner || $isPanelAdmin || in_array($permission, $serverPermissions, true);
};

$canRenameServer = $hasServerPermission('settings.rename');
$canStartServer = $hasServerPermission('control.start');
$canStopServer = $hasServerPermission('control.stop');
$canRestartServer = $hasServerPermission('control.restart');

$isInstalling = !empty($selectedServer['is_installing']);
$isSuspended = strtolower(trim((string)($selectedServer['status'] ?? ''))) === 'suspended' || !empty($selectedServer['suspended']);
$isManualSuspension = !empty($selectedServer['suspend_manual']);
$expiredAtRaw = trim((string)($selectedServer['expired_at'] ?? ''));
$isExpiredServer = $expiredAtRaw !== '' && strtotime($expiredAtRaw) !== false && strtotime($expiredAtRaw) <= time();
$canShowSuspendedRenewal = $isSuspended && !$isManualSuspension && $isExpiredServer;

$resources = [
    'status' => $isSuspended ? 'suspended' : ($isInstalling ? 'installing' : 'unknown'),
    'cpu' => 0,
    'memory_bytes' => 0,
    'disk_bytes' => 0,
    'uptime' => 0,
];
$status = $canShowSuspendedRenewal ? 'expired' : (string)$resources['status'];

if (!function_exists('fbgFormatBytesToGb')) {
    function fbgFormatBytesToGb(int $bytes): string
    {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
}

if (!function_exists('fbgStatusText')) {
    function fbgStatusText(string $status): string
    {
        return match ($status) {
            'running'    => 'Running',
            'offline'    => 'Stopped',
            'starting'   => 'Starting',
            'stopping'   => 'Stopping',
            'suspended'  => 'Suspended',
            'expired'    => 'Expired',
            'installing' => 'Installing',
            default      => 'Unknown',
        };
    }
}

if (!function_exists('fbgStatusClass')) {
    function fbgStatusClass(string $status): string
    {
        return in_array($status, ['running', 'offline', 'starting', 'stopping', 'installing', 'suspended', 'expired'], true)
            ? $status
            : 'unknown';
    }
}

if (!function_exists('getGameIcon')) {
    function getGameIcon(array $server): string
    {
        $eggName = strtolower(trim((string)($server['egg_name'] ?? '')));
        $serverName = strtolower(trim((string)($server['name'] ?? '')));
        $source = $eggName !== '' && $eggName !== 'unknown' ? $eggName : $serverName;

        if (str_contains($source, 'neoforge')) return '/backend/img/icons/mc-neoforge.png';
        if (str_contains($source, 'forge')) return '/backend/img/icons/mc-forge.png';
        if (str_contains($source, 'fabric')) return '/backend/img/icons/mc-fabric.png';
        if (str_contains($source, 'quilt')) return '/backend/img/icons/mc-quilt.svg';
        if (str_contains($source, 'sponge')) return '/backend/img/icons/mc-sponge.png';
        if (str_contains($source, 'paper')) return '/backend/img/icons/mc-paper.svg';
        if (str_contains($source, 'spigot')) return '/backend/img/icons/minecraft.png';
        if (str_contains($source, 'bukkit')) return '/backend/img/icons/minecraft.png';
        if (str_contains($source, 'purpur')) return '/backend/img/icons/minecraft.png';
        if (str_contains($source, 'vanilla minecraft')) return '/backend/img/icons/minecraft.png';
        if (str_contains($source, 'modpack installer')) return '/backend/img/icons/minecraftmodpack.png';
        if (str_contains($source, 'palworld')) return '/backend/img/icons/palworld.png';
        if (str_contains($source, 'rust')) return '/backend/img/icons/rust.png';
        if (str_contains($source, 'ark')) return '/backend/img/icons/ase.png';
        if (str_contains($source, 'icarus')) return '/backend/img/icons/icarus.png';
        if (str_contains($source, 'tshock')) return '/backend/img/icons/tshock.png';
        if (str_contains($source, 'terraria')) return '/backend/img/icons/terraria.png';
        if (str_contains($source, 'starrupture')) return '/backend/img/icons/starrupture.jpg';
        if (str_contains($source, 'fivem')) return '/backend/img/icons/fivem.png';
        if (str_contains($source, 'factorio')) return '/backend/img/icons/factorio.png';
        if (str_contains($source, 'enshrouded')) return '/backend/img/icons/enshrouded.png';

        return '/backend/img/icons/default.png';
    }
}

if (!function_exists('fbgServerSupportsModpacks')) {
    function fbgServerSupportsModpacks(array $server): bool
    {
        $source = strtolower(trim(
            (string)($server['egg_name'] ?? '') . ' ' .
            (string)($server['name'] ?? '') . ' ' .
            (string)($server['description'] ?? '')
        ));

        foreach (['minecraft', 'modpack', 'forge', 'fabric', 'neoforge', 'quilt', 'paper', 'spigot', 'bukkit', 'purpur', 'sponge'] as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('fbgServerRailInitialStatus')) {
    function fbgServerRailInitialStatus(array $server, string $identifier, string $selectedIdentifier, bool $selectedInstalling): string
    {
        if ($identifier === $selectedIdentifier) {
            if (!empty($server['suspended']) || strtolower(trim((string)($server['status'] ?? ''))) === 'suspended') {
                $expiredAt = trim((string)($server['expired_at'] ?? ''));
                $isExpired = $expiredAt !== '' && strtotime($expiredAt) !== false && strtotime($expiredAt) <= time();

                if (empty($server['suspend_manual']) && $isExpired) {
                    return 'expired';
                }

                return 'suspended';
            }

            return $selectedInstalling ? 'installing' : 'unknown';
        }

        if (!empty($server['is_installing'])) {
            return 'installing';
        }

        if (!empty($server['suspended']) || strtolower(trim((string)($server['status'] ?? ''))) === 'suspended') {
            $expiredAt = trim((string)($server['expired_at'] ?? ''));
            $isExpired = $expiredAt !== '' && strtotime($expiredAt) !== false && strtotime($expiredAt) <= time();

            if (empty($server['suspend_manual']) && $isExpired) {
                return 'expired';
            }

            return 'suspended';
        }

        return 'unknown';
    }
}

$ramLimit  = ((int)($selectedServer['memory'] ?? 0) === 0)
    ? '∞'
    : round(((int)$selectedServer['memory']) / 1024, 1) . ' GB';

$diskLimit = ((int)($selectedServer['disk'] ?? 0) === 0)
    ? '∞'
    : round(((int)$selectedServer['disk']) / 1024, 1) . ' GB';

$cpuLimit  = ((int)($selectedServer['cpu'] ?? 0) === 0)
    ? '∞'
    : number_format((int)$selectedServer['cpu']) . '%';

$serverAddress = pteroGetCurrentServerAddress($serverIdentifier);

$railServers = [];

foreach ($serverMeta as $identifier => $server) {
    $identifier = trim((string)$identifier);

    if ($identifier === '' || !is_array($server)) {
        continue;
    }

    $isOwner = !empty($serverOwnerMap[$identifier]);

    if ($canViewAllServers && !$showAllServers && !$isOwner && $identifier !== $serverIdentifier) {
        continue;
    }

    $railServers[] = $server;
}

usort($railServers, static function (array $a, array $b): int {
    return strcasecmp(
        (string)($a['name'] ?? ''),
        (string)($b['name'] ?? '')
    );
});

$hasSelectedServerInRail = false;

foreach ($railServers as $server) {
    if ((string)($server['identifier'] ?? '') === $serverIdentifier) {
        $hasSelectedServerInRail = true;
        break;
    }
}

if (!$hasSelectedServerInRail) {
    array_unshift($railServers, $selectedServer);
}

/* =============
   TAB SYSTEM
   ============= */

$availableTabs = [];

if ($hasServerPermission('control.console') || $hasServerPermission('websocket.connect')) {
    $availableTabs['console'] = ['label' => 'Console', 'icon' => 'fas fa-terminal'];
}

if (fbgServerSupportsModpacks($selectedServer) && $hasServerPermission('file.create')) {
    $availableTabs['mc-modpacks'] = ['label' => 'Modpacks', 'icon' => 'fas fa-cubes'];
}

if (
    $hasServerPermission('file.read') ||
    $hasServerPermission('file.read-content') ||
    $hasServerPermission('file.update') ||
    $hasServerPermission('file.create')
) {
    $availableTabs['files'] = ['label' => 'Files', 'icon' => 'fas fa-folder-open'];
}

$databaseLimit = (int)($selectedServer['feature_databases'] ?? 0);
if (
    $databaseLimit > 0 &&
    $hasServerPermission('database.read')
) {
    $availableTabs['databases'] = ['label' => 'Databases', 'icon' => 'fas fa-database'];
}

if ($hasServerPermission('schedule.read')) {
    $availableTabs['schedules'] = ['label' => 'Schedules', 'icon' => 'fas fa-clock'];
}

if ($hasServerPermission('user.read')) {
    $availableTabs['users'] = ['label' => 'Users', 'icon' => 'fas fa-user-shield'];
}

if ($hasServerPermission('backup.read')) {
    $availableTabs['backups'] = ['label' => 'Backups', 'icon' => 'fas fa-box-archive'];
}

if ($hasServerPermission('allocation.read')) {
    $availableTabs['network'] = ['label' => 'Network', 'icon' => 'fas fa-network-wired'];
}

if ($hasServerPermission('startup.read')) {
    $availableTabs['startup'] = ['label' => 'Startup', 'icon' => 'fas fa-sliders-h'];
}

if (
    $hasServerPermission('settings.rename') ||
    $hasServerPermission('settings.reinstall') ||
    $hasServerPermission('file.sftp')
) {
    $availableTabs['settings'] = ['label' => 'Settings', 'icon' => 'fas fa-cog'];
}

if ($hasServerPermission('activity.read')) {
    $availableTabs['activity'] = ['label' => 'Activity', 'icon' => 'fas fa-paperclip'];
}

if (function_exists('canAccess') && canAccess(4)) {
    $availableTabs['admin'] = ['label' => 'Admin', 'icon' => 'fas fa-users-gear'];
}

$serverTab = strtolower(trim((string)($_GET['tab'] ?? 'console')));

if (empty($availableTabs)) {
    http_response_code(403);
    echo '<section class="fbg-dashboard"><div class="fbg-dashboard-alert error is-visible">You do not have permission to view any parts of this server.</div></section>';
    return;
}

if (!array_key_exists($serverTab, $availableTabs)) {
    $fallbackTab = array_key_first($availableTabs);

    if ($fallbackTab !== null) {
        $redirectUrl = './page.php?name=serverpanel&id=' . urlencode($serverIdentifier) . '&tab=' . urlencode($fallbackTab);

        echo '<script>window.location.replace(' . json_encode($redirectUrl) . ');</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

if (!function_exists('fbgRenderTabPlaceholder')) {
    function fbgRenderTabPlaceholder(string $tabKey, array $tabConfig): void
    {
        $title = $tabConfig['label'] ?? ucfirst($tabKey);
        $icon = $tabConfig['icon'] ?? 'fas fa-circle';
        ?>
        <div class="fbg-tab-placeholder-panel">
            <div class="fbg-server-card-header">
                <div class="fbg-server-heading">
                    <h2><i class="<?php echo htmlspecialchars($icon); ?>"></i> <?php echo htmlspecialchars($title); ?></h2>
                    <p>It seems like we're missing some content here... This page hasn't been built yet.</p>
                </div>
            </div>

            <div class="fbg-tab-placeholder-body">
                <div class="fbg-dashboard-alert" style="display:block; margin: 0;">
                    <?php echo htmlspecialchars($title); ?> tab placeholder.
                </div>
            </div>
        </div>
        <?php
    }
}

$renderServerTabContent = function () use (
    $isInstalling,
    $isSuspended,
    $serverTab,
    $availableTabs,
    $selectedServer,
    $serverPermissions,
    $isServerOwner,
    $isPanelAdmin,
    $hasServerPermission,
    $canRenameServer,
    $canStartServer,
    $canStopServer,
    $canRestartServer,
    $serverIdentifier,
    $canShowSuspendedRenewal,
    $resources,
    $status,
    $ramLimit,
    $diskLimit,
    $cpuLimit,
    $serverAddress
): void {
    $isSettingsTab = ($serverTab === 'settings');
    $renderSpecialStateBanner = function (string $mode) use ($serverIdentifier, $canShowSuspendedRenewal): void {
        if ($mode === 'suspended') {
            $renewUrl = './page.php?name=serverpanel&id=' . rawurlencode($serverIdentifier) . '&tab=settings#renew';
            ?>
            <div class="fbg-tab-placeholder-panel">
                <div class="fbg-server-card-header">
                    <div class="fbg-server-heading-installing fbg-server-heading-suspended">
                        <br>
                        <?php if ($canShowSuspendedRenewal): ?>
                            <img
                                src="/backend/img/expired.png"
                                alt=""
                                class="fbg-server-installing-art fbg-server-expired-art"
                                aria-hidden="true"
                            >
                            <h1>Looks like your server has expired.</h1>
                            <p>No worries! Renew your server to restore access and pick up where you left off.</p>
                            <div class="fbg-settings-section-footer fbg-server-expired-action">
                                <a href="<?php echo htmlspecialchars($renewUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn fbg-neutral-button">
                                    Renew Server
                                </a>
                            </div>
                            <hr class="fbg-server-installing-divider fbg-server-expired-divider">
                            <div class="fbg-server-installing-info fbg-server-expired-info">
                                <i class="fas fa-circle-info" aria-hidden="true"></i>
                                <div>
                                    <p><strong>Your server data is still here.</strong></p>
                                    <p>Renew your server to regain access before it is scheduled for deletion.</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <img
                                src="/backend/img/prohibited.png"
                                alt=""
                                class="fbg-server-installing-art fbg-server-suspended-art"
                                aria-hidden="true"
                            >
                            <h1>SUSPENDED</h1>
                            <p>This server is currently suspended.</p>
                            <hr class="fbg-server-installing-divider fbg-server-suspended-divider">
                            <p>Please contact support if you believe this suspension is incorrect.</p>
                            <div class="fbg-server-installing-info fbg-server-suspended-info">
                                <i class="fas fa-circle-info" aria-hidden="true"></i>
                                <div>
                                    <p>Suspensions are typically due to a violation of our Terms of Service.</p>
                                    <p>For more information, please reach out to our support team.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        ?>
        <div class="fbg-tab-placeholder-panel">
            <div class="fbg-server-card-header">
                <div class="fbg-server-heading-installing">
                    <img
                        src="/backend/img/construction.png"
                        alt=""
                        class="fbg-server-installing-art"
                        aria-hidden="true"
                    >
                    <h1>Your server is being crafted!</h1>
                    <p>Hang tight while we get everything ready.</p>
                    <hr class="fbg-server-installing-divider">
                    <p>Assuming we didn't forget any materials...</p>
                    <div class="fbg-server-installing-info">
                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                        <div>
                            <p>This may take a few minutes depending on install size.</p>
                            <p>You'll get access as soon as everything is set up.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    };

    if ($isSuspended) {
        $useSettingsRenewalControls = $isSettingsTab && $canShowSuspendedRenewal;

        if (!$useSettingsRenewalControls) {
            $renderSpecialStateBanner('suspended');
            return;
        }
    }

    if ($isInstalling && !($isPanelAdmin && $serverTab === 'console')) {
        $renderSpecialStateBanner('installing');

        if (!$isSettingsTab) {
            return;
        }
    }

    $tabFile = __DIR__ . '/serverpanel/' . $serverTab . '.php';

    if (file_exists($tabFile)) {
        include $tabFile;
    } else {
        fbgRenderTabPlaceholder($serverTab, $availableTabs[$serverTab]);
    }
};

$isTabPartialRequest = isset($_GET['partial']) && $_GET['partial'] === 'tab';

if ($isTabPartialRequest) {
    $renderServerTabContent();
    exit;
}

$csrfTokenForJs = (string)$_SESSION['csrf_token'];

session_write_close();
?>

<section class="fbg-admin-shell fbg-server-panel-shell">
        <aside class="fbg-admin-sidebar fbg-server-rail">
            <div class="fbg-admin-sidebar-brand fbg-server-rail-brand">
                <span class="fbg-admin-sidebar-eyebrow">Server Panel</span>
                <h2>Servers</h2>
            </div>

            <?php if ($canViewAllServers): ?>
                <form method="post" class="fbg-server-rail-toggle-form">
                    <input type="hidden" name="server_scope_toggle" value="1">

                    <div class="fbg-server-rail-scope-switch" role="tablist" aria-label="Server scope">
                        <button type="submit" name="server_scope" value="mine" class="fbg-server-rail-scope-tab <?php echo !$showAllServers ? 'active' : ''; ?>" role="tab" aria-selected="<?php echo !$showAllServers ? 'true' : 'false'; ?>">
                            Personal
                        </button>

                        <button type="submit" name="server_scope" value="all" class="fbg-server-rail-scope-tab <?php echo $showAllServers ? 'active' : ''; ?>" role="tab" aria-selected="<?php echo $showAllServers ? 'true' : 'false'; ?>">
                            All
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="fbg-server-rail-dashboard-link-wrap">
                <a href="./page.php?name=dashboard" class="fbg-server-rail-item fbg-server-rail-dashboard-link">
                    <span class="fbg-server-rail-icon-wrap fbg-server-rail-dashboard-icon" aria-hidden="true">
                        <i class="fas fa-arrow-left"></i>
                    </span>
                    <span class="fbg-server-rail-name">Back to Dashboard</span>
                    <span class="fbg-server-rail-dashboard-spacer" aria-hidden="true"></span>
                </a>
            </div>

            <nav class="fbg-server-rail-list" aria-label="Servers">
                <?php foreach ($railServers as $server): ?>
                    <?php
                    $railIdentifier = (string)($server['identifier'] ?? '');
                    $railName = (string)($server['name'] ?? 'Unnamed Server');
                    $railIcon = getGameIcon($server);
                    $railStatus = fbgServerRailInitialStatus($server, $railIdentifier, $serverIdentifier, $isInstalling);
                    $isActiveRailServer = $railIdentifier === $serverIdentifier;
                    ?>
                    <a
                        href="./page.php?name=serverpanel&id=<?php echo urlencode($railIdentifier); ?>&tab=<?php echo urlencode($serverTab); ?>"
                        class="fbg-server-rail-item <?php echo $isActiveRailServer ? 'active' : ''; ?>"
                        data-server="<?php echo htmlspecialchars($railIdentifier); ?>"
                    >
                        <span class="fbg-server-rail-icon-wrap" aria-hidden="true">
                            <img src="<?php echo htmlspecialchars($railIcon); ?>" alt="" class="fbg-dashboard-game-icon">
                        </span>
                        <span class="fbg-server-rail-name" title="<?php echo htmlspecialchars($railName); ?>">
                            <?php echo htmlspecialchars($railName); ?>
                        </span>
                        <span
                            class="fbg-server-rail-status-dot <?php echo htmlspecialchars(fbgStatusClass($railStatus)); ?>"
                            title="<?php echo htmlspecialchars(fbgStatusText($railStatus)); ?>"
                            aria-label="<?php echo htmlspecialchars(fbgStatusText($railStatus)); ?>"
                        ></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div class="fbg-admin-main fbg-server-panel-content">
            <div class="fbg-server-shell">
                <div class="fbg-server-main">
                    <article class="fbg-server-card <?php echo $serverTab === 'console' ? 'fbg-console-panel' : 'fbg-tab-panel'; ?>" data-server="<?php echo htmlspecialchars($selectedServer['identifier']); ?>">
                <div class="fbg-server-card-tabs">
                    <nav class="fbg-server-view-switch" aria-label="Server tabs">
                        <?php foreach ($availableTabs as $tabKey => $tabConfig): ?>
                            <?php if ($tabKey === 'admin'): ?>
                                <a href="./page.php?name=admin-servers&edit=<?php echo (int)($selectedServer['id'] ?? 0); ?>" class="fbg-server-view-tab fbg-server-view-tab-admin" title="Open server in admin panel">
                                    <i class="<?php echo htmlspecialchars($tabConfig['icon']); ?>"></i>
                                    <span><?php echo htmlspecialchars($tabConfig['label']); ?></span>
                                    <i class="fas fa-up-right-from-square fbg-server-view-tab-external" aria-hidden="true"></i>
                                </a>
                            <?php else: ?>
                                <a href="./page.php?name=serverpanel&id=<?php echo urlencode($selectedServer['identifier']); ?>&tab=<?php echo urlencode($tabKey); ?>" class="fbg-server-view-tab <?php echo $serverTab === $tabKey ? 'active' : ''; ?>">
                                    <i class="<?php echo htmlspecialchars($tabConfig['icon']); ?>"></i>
                                    <span><?php echo htmlspecialchars($tabConfig['label']); ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                </div>
                <div class="fbg-server-card-header">
                    <div class="fbg-server-heading">
                        <div class="fbg-editable-row" data-field="name">
                            <div class="fbg-editable-display">
                                <h1 id="server-name-text"><?php echo htmlspecialchars($selectedServer['name'] ?? 'Unnamed Server'); ?></h1>
                                <?php if ($canRenameServer): ?>
                                    <button type="button" class="btn fbg-neutral-button btn-sm fbg-edit-toggle" data-field="name" aria-label="Edit server name">
                                        <i class="fas fa-pen-to-square"></i>
                                    </button>
                                <?php endif; ?>
                            </div>

                            <?php if ($canRenameServer): ?>
                                <div class="fbg-editable-editor" data-editor="name" style="display:none;">
                                    <input
                                        type="text"
                                        id="server-name-input"
                                        class="fbg-text-input"
                                        maxlength="191"
                                        value="<?php echo htmlspecialchars($selectedServer['name'] ?? ''); ?>"
                                    >
                                    <div class="fbg-edit-actions">
                                        <button type="button" class="btn fbg-neutral-button btn-sm fbg-save-edit" data-field="name">Save</button>
                                        <button type="button" class="btn fbg-neutral-button btn-sm fbg-cancel-edit" data-field="name">Cancel</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="fbg-editable-row" data-field="description">
                            <div class="fbg-editable-display">
                                <p id="server-description-text"><?php echo htmlspecialchars(($selectedServer['description'] ?? '') ?: 'No description'); ?></p>
                                <?php if ($canRenameServer): ?>
                                    <button type="button" class="btn fbg-neutral-button btn-sm fbg-edit-toggle" data-field="description" aria-label="Edit server description">
                                        <i class="fas fa-pen-to-square"></i>
                                    </button>
                                <?php endif; ?>
                            </div>

                            <?php if ($canRenameServer): ?>
                                <div class="fbg-editable-editor" data-editor="description" style="display:none;">
                                    <input
                                        type="text"
                                        id="server-description-input"
                                        class="fbg-text-input"
                                        maxlength="191"
                                        value="<?php echo htmlspecialchars($selectedServer['description'] ?? ''); ?>"
                                        placeholder="No description"
                                    >
                                    <div class="fbg-edit-actions">
                                        <button type="button" class="btn fbg-neutral-button btn-sm fbg-save-edit" data-field="description">Save</button>
                                        <button type="button" class="btn fbg-neutral-button btn-sm fbg-cancel-edit" data-field="description">Cancel</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="fbg-dashboard-alert" id="server-details-message" style="display:none; margin-top: 12px;"></div>
                    </div>
                </div>

                <script>
                    window.FBG_SERVER_PANEL = {
                        identifier: <?php echo json_encode($selectedServer['identifier']); ?>,
                        csrfToken: <?php echo json_encode($csrfTokenForJs); ?>,
                        isInstalling: <?php echo json_encode($isInstalling); ?>,
                        isSuspended: <?php echo json_encode($isSuspended); ?>,
                        canShowSuspendedRenewal: <?php echo json_encode($canShowSuspendedRenewal); ?>,
                        allowConsoleWhileInstalling: <?php echo json_encode($isPanelAdmin && $serverTab === 'console'); ?>,
                        currentTab: <?php echo json_encode($serverTab); ?>
                    };
                </script>

                <div id="server-tab-content">
                    <?php $renderServerTabContent(); ?>
                </div>
                    </article>
                </div>

                <aside class="fbg-server-sidebar">
                    <article class="fbg-server-card fbg-sidebar-card">
                <?php if ($canStartServer || $canRestartServer || $canStopServer): ?>
                    <div class="fbg-sidebar-actions-wrap" style="position:relative;">
                        <div class="fbg-sidebar-actions">
                        <?php if ($canStartServer): ?>
                            <button type="button" class="btn fbg-neutral-button btn-start">Start</button>
                        <?php endif; ?>

                        <?php if ($canRestartServer): ?>
                            <button type="button" class="btn warn-action btn-restart">Restart</button>
                        <?php endif; ?>

                        <?php if ($canStopServer): ?>
                            <button type="button" class="btn danger-action btn-stop">Stop</button>
                        <?php endif; ?>

                        </div>

                        <div class="fbg-dashboard-alert fbg-power-action-message" id="power-action-message" style="display:none; position:absolute; left:50%; top:calc(100% + 10px); transform:translate(-50%, -6px); z-index:30;"></div>
                    </div>
                <?php endif; ?>

                <div class="fbg-sidebar-stats">
                    <div class="fbg-sidebar-stat">
                        <div class="fbg-sidebar-stat-icon">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <div class="fbg-sidebar-stat-content">
                            <span class="fbg-meta-label">Address</span>
                            <div id="server-address-text" class="fbg-meta-value fbg-copyable" data-copy="<?php echo htmlspecialchars($serverAddress); ?>">
                                <?php echo htmlspecialchars($serverAddress); ?>
                            </div>
                        </div>
                    </div>

                    <div class="fbg-sidebar-stat">
                        <div class="fbg-sidebar-stat-icon fbg-sidebar-stat-icon-status <?php echo htmlspecialchars(fbgStatusClass($status)); ?>">
                            <i class="fas fa-power-off"></i>
                        </div>
                        <div class="fbg-sidebar-stat-content">
                            <span class="fbg-meta-label">Status</span>
                            <div class="fbg-meta-value">
                                <span class="fbg-status-badge <?php echo htmlspecialchars(fbgStatusClass($status)); ?> server-status">
                                    <?php echo htmlspecialchars(fbgStatusText($status)); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="fbg-sidebar-stat">
                        <div class="fbg-sidebar-stat-icon">
                            <i class="fas fa-microchip"></i>
                        </div>
                        <div class="fbg-sidebar-stat-content">
                            <span class="fbg-meta-label">CPU</span>
                            <div class="fbg-meta-value">
                                <span class="stat-cpu-usage"><?php echo htmlspecialchars(number_format((float)($resources['cpu'] ?? 0), 2) . '%'); ?></span>
                                /
                                <span class="stat-cpu-limit"><?php echo htmlspecialchars($cpuLimit); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="fbg-sidebar-stat">
                        <div class="fbg-sidebar-stat-icon">
                            <i class="fas fa-memory"></i>
                        </div>
                        <div class="fbg-sidebar-stat-content">
                            <span class="fbg-meta-label">Memory</span>
                            <div class="fbg-meta-value">
                                <span class="stat-ram-usage"><?php echo htmlspecialchars(fbgFormatBytesToGb((int)($resources['memory_bytes'] ?? 0))); ?></span>
                                /
                                <span class="stat-ram-limit"><?php echo htmlspecialchars($ramLimit); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="fbg-sidebar-stat">
                        <div class="fbg-sidebar-stat-icon">
                            <i class="fas fa-compact-disc"></i>
                        </div>
                        <div class="fbg-sidebar-stat-content">
                            <span class="fbg-meta-label">Disk</span>
                            <div class="fbg-meta-value">
                                <span class="stat-disk-usage"><?php echo htmlspecialchars(fbgFormatBytesToGb((int)($resources['disk_bytes'] ?? 0))); ?></span>
                                /
                                <span class="stat-disk-limit"><?php echo htmlspecialchars($diskLimit); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="fbg-sidebar-stat">
                        <div class="fbg-sidebar-stat-icon">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="fbg-sidebar-stat-content">
                            <span class="fbg-meta-label">Node</span>
                            <div class="fbg-meta-value"><?php echo htmlspecialchars(($selectedServer['node_name'] ?? '') ?: ('Node ID: ' . (string)($selectedServer['node_id'] ?? 'Unknown'))); ?></div>
                        </div>
                    </div>

                    <div class="fbg-sidebar-stat">
                        <div class="fbg-sidebar-stat-icon">
                            <i class="fas fa-cube"></i>
                        </div>
                        <div class="fbg-sidebar-stat-content">
                            <span class="fbg-meta-label">Egg</span>
                            <div class="fbg-meta-value"><?php echo htmlspecialchars(($selectedServer['egg_name'] ?? '') ?: 'Unknown'); ?></div>
                        </div>
                    </div>

                    <div class="fbg-sidebar-stat">
                        <div class="fbg-sidebar-stat-icon">
                            <i class="fas fa-fingerprint"></i>
                        </div>
                        <div class="fbg-sidebar-stat-content">
                            <span class="fbg-meta-label">Identifier</span>
                            <div class="fbg-meta-value"><?php echo htmlspecialchars($selectedServer['identifier']); ?></div>
                        </div>
                    </div>
                </div>
                    </article>
                </aside>
            </div>
        </div>
</section>

<script src="<?php echo asset('./backend/js/serverpanel/panel.js'); ?>"></script>