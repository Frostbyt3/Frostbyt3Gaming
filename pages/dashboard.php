<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../api/pterodactyl.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$panelUserId = (int)($_SESSION['user_id'] ?? 0);
$canViewAllServers = function_exists('canAccess') ? canAccess(4) : false;

/*
|--------------------------------------------------------------------------
| Handle dashboard server scope toggle BEFORE any output
|--------------------------------------------------------------------------
*/
if ($panelUserId > 0 && $canViewAllServers && isset($_POST['server_scope_toggle'])) {
    $requestedScope = (string)($_POST['server_scope'] ?? 'mine');
    setShowAllServers($requestedScope === 'all');
}

$userServers = [];
$pteroError = null;
$showAllServers = false;

/*
|--------------------------------------------------------------------------
| Load user servers (session-first, admin-aware)
|--------------------------------------------------------------------------
*/
if ($panelUserId <= 0) {
    $pteroError = 'No valid panel user ID was found in your session.';
} else {
    $showAllServers = isShowingAllServers();
    $includeAdminAllServers = $showAllServers && $canViewAllServers;
    $forceServerRefresh = isset($_GET['refresh_servers']) && $_GET['refresh_servers'] === '1';
    $serverCacheMaxAgeSeconds = $includeAdminAllServers ? 30 : 60;

    $accessSession = pteroEnsureServerAccessSession(
        $forceServerRefresh,
        $includeAdminAllServers,
        $serverCacheMaxAgeSeconds
    );
    $accessError = is_array($accessSession) ? trim((string)($accessSession['error'] ?? '')) : '';

    $serverMeta = $_SESSION['server_meta'] ?? [];
    $allowedServers = $_SESSION['allowed_servers'] ?? [];
    $serverPermissionsMap = $_SESSION['server_permissions'] ?? [];
    $serverOwnerMap = $_SESSION['server_is_owner'] ?? [];
    $serverPanelAdminMap = $_SESSION['server_is_panel_admin'] ?? [];

    if (!is_array($serverMeta) || !is_array($allowedServers)) {
        $accessSession = pteroEnsureServerAccessSession(true, $includeAdminAllServers, $serverCacheMaxAgeSeconds);
        $accessError = is_array($accessSession) ? trim((string)($accessSession['error'] ?? '')) : '';

        $serverMeta = $_SESSION['server_meta'] ?? [];
        $allowedServers = $_SESSION['allowed_servers'] ?? [];
        $serverPermissionsMap = $_SESSION['server_permissions'] ?? [];
        $serverOwnerMap = $_SESSION['server_is_owner'] ?? [];
        $serverPanelAdminMap = $_SESSION['server_is_panel_admin'] ?? [];
    }

    if ($accessError !== '') {
        $pteroError = 'Unable to load your server list right now. Please try again.';
    } elseif (!is_array($serverMeta) || empty($serverMeta)) {
        $userServers = [];
    } else {
        $isCurrentUserPanelAdmin = false;

        foreach ($serverPanelAdminMap as $value) {
            if (!empty($value)) {
                $isCurrentUserPanelAdmin = true;
                break;
            }
        }

        $displayServers = [];

        if ($showAllServers && $canViewAllServers) {
            foreach ($serverMeta as $identifier => $server) {
                $identifier = (string)$identifier;

                if ($identifier === '') {
                    continue;
                }

                if (!is_array($server)) {
                    continue;
                }

                $displayServers[] = $server;
            }
        } else {
            foreach ($serverMeta as $identifier => $server) {
                $identifier = (string)$identifier;

                if ($identifier === '') {
                    continue;
                }

                if (!in_array($identifier, (array)$allowedServers, true)) {
                    continue;
                }

                $isOwner = !empty($serverOwnerMap[$identifier]);

                /*
                 * Panel admins in normal mode should only see servers they own.
                 * The explicit "show all" toggle is the only place they should see everything.
                 */
                if ($isCurrentUserPanelAdmin && !$showAllServers && !$isOwner) {
                    continue;
                }

                if (!is_array($server)) {
                    continue;
                }

                $displayServers[] = $server;
            }
        }

        usort($displayServers, static function (array $a, array $b): int {
            return strcasecmp(
                (string)($a['name'] ?? ''),
                (string)($b['name'] ?? '')
            );
        });

        $userServers = array_values($displayServers);
    }
}

$debug = false;

if (!function_exists('getGameIcon')) {
    function getGameIcon(array $server): string
    {
        $eggName = strtolower(trim((string)($server['egg_name'] ?? '')));
        $serverName = strtolower(trim((string)($server['name'] ?? '')));
        $source = $eggName !== '' && $eggName !== 'unknown' ? $eggName : $serverName;

        if (str_contains($source, 'neoforge'))          return '/backend/img/icons/mc-neoforge.png';
        if (str_contains($source, 'forge'))             return '/backend/img/icons/mc-forge.png';
        if (str_contains($source, 'fabric'))            return '/backend/img/icons/mc-fabric.png';
        if (str_contains($source, 'quilt'))             return '/backend/img/icons/mc-quilt.svg';
        if (str_contains($source, 'sponge'))            return '/backend/img/icons/mc-sponge.png';
        if (str_contains($source, 'paper'))             return '/backend/img/icons/mc-paper.svg';
        if (str_contains($source, 'spigot'))            return '/backend/img/icons/minecraft.png';
        if (str_contains($source, 'bukkit'))            return '/backend/img/icons/minecraft.png';
        if (str_contains($source, 'purpur'))            return '/backend/img/icons/minecraft.png';
        if (str_contains($source, 'vanilla minecraft')) return '/backend/img/icons/minecraft.png';
        if (str_contains($source, 'modpack installer')) return '/backend/img/icons/minecraftmodpack.png';

        if (str_contains($source, 'palworld'))          return '/backend/img/icons/palworld.png';
        if (str_contains($source, 'rust'))              return '/backend/img/icons/rust.png';
        if (str_contains($source, 'ark'))               return '/backend/img/icons/ase.png';
        if (str_contains($source, 'icarus'))            return '/backend/img/icons/icarus.png';
        if (str_contains($source, 'tshock'))            return '/backend/img/icons/tshock.png';
        if (str_contains($source, 'terraria'))          return '/backend/img/icons/terraria.png';
        if (str_contains($source, 'starrupture'))       return '/backend/img/icons/starrupture.jpg';
        if (str_contains($source, 'fivem'))             return '/backend/img/icons/fivem.png';
        if (str_contains($source, 'factorio'))          return '/backend/img/icons/factorio.png';
        if (str_contains($source, 'enshrouded'))        return '/backend/img/icons/enshrouded.png';

        return '/backend/img/icons/default.png';
    }
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/
$actionMessage = $_SESSION['dashboard_flash_success'] ?? null;
$actionError = $_SESSION['dashboard_flash_error'] ?? null;

unset($_SESSION['dashboard_flash_success'], $_SESSION['dashboard_flash_error']);

$csrfTokenForJs = (string)($_SESSION['csrf_token'] ?? '');
$serversPageUrl = './page.php?name=servers';
$accountPageUrl = './page.php?name=account';
$creditPageUrl = './page.php?name=credit';
$discordUrl = 'https://frostbyt3gaming.com/discord';
$fbgSidebarCurrent = 'dashboard';
$fbgSidebarTitle = 'Dashboard';

$totalServers = count($userServers);
$dashboardNodeKeys = [];
$initialRunningCount = 0;
$initialStoppedCount = 0;
$initialStartingCount = 0;

foreach ($userServers as $dashboardServer) {
    $dashboardNodeKey = (string)(($dashboardServer['node_name'] ?? '') ?: ('node-' . (string)($dashboardServer['node_id'] ?? 'unknown')));
    $dashboardNodeKeys[$dashboardNodeKey] = true;

    $dashboardIsSuspended = !empty($dashboardServer['suspended']) || strtolower(trim((string)($dashboardServer['status'] ?? ''))) === 'suspended';
    $dashboardIsInstalling = !empty($dashboardServer['is_installing']);

    if ($dashboardIsSuspended) {
        $initialStoppedCount++;
    } elseif ($dashboardIsInstalling) {
        $initialStartingCount++;
    }
}

$totalNodes = count($dashboardNodeKeys);

session_write_close();
?>

<section class="fbg-dashboard-shell" data-dashboard-view="list">
    <div class="fbg-dashboard-layout">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div class="fbg-dashboard-main">
            <?php if ($actionMessage): ?>
                <div class="fbg-dashboard-alert success">
                    <?php echo htmlspecialchars((string)$actionMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($actionError): ?>
                <div class="fbg-dashboard-alert error">
                    <?php echo htmlspecialchars((string)$actionError); ?>
                </div>
            <?php endif; ?>

            <?php if ($pteroError): ?>
                <div class="fbg-dashboard-alert error">
                    <?php echo htmlspecialchars((string)$pteroError); ?>
                </div>
            <?php endif; ?>

            <div class="fbg-dashboard-topbar">
                <div class="fbg-dashboard-topbar-row">
                    <div class="fbg-dashboard-title-block">
                        <h1>Dashboard</h1>
                    </div>

                    <?php if (!empty($userServers)): ?>
                        <?php if ($canViewAllServers): ?>
                            <div class="fbg-dashboard-topbar-center">
                                <form method="post" class="fbg-server-scope-form">
                                    <input type="hidden" name="server_scope_toggle" value="1">

                                    <div class="fbg-dashboard-scope-switch" role="tablist" aria-label="Server scope">
                                        <button
                                            type="submit"
                                            name="server_scope"
                                            value="mine"
                                            class="fbg-dashboard-scope-tab <?php echo !$showAllServers ? 'active' : ''; ?>"
                                            role="tab"
                                            aria-selected="<?php echo !$showAllServers ? 'true' : 'false'; ?>"
                                        >
                                            Personal Servers
                                        </button>

                                        <button
                                            type="submit"
                                            name="server_scope"
                                            value="all"
                                            class="fbg-dashboard-scope-tab <?php echo $showAllServers ? 'active' : ''; ?>"
                                            role="tab"
                                            aria-selected="<?php echo $showAllServers ? 'true' : 'false'; ?>"
                                        >
                                            All Servers
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <div class="fbg-dashboard-topbar-actions">
                            <div class="fbg-dashboard-control-strip">
                                <label class="fbg-dashboard-search-wrap" for="fbg-dashboard-search">
                                    <input id="fbg-dashboard-search" type="search" placeholder="Search servers..." autocomplete="off">
                                    <i class="fas fa-search" aria-hidden="true"></i>
                                </label>

                                <div class="fbg-dashboard-view-toggle" role="group" aria-label="Dashboard view mode">
                                    <button type="button" class="fbg-dashboard-view-button is-active" data-view-mode="list" aria-pressed="true" title="List view">
                                        <i class="fas fa-list"></i>
                                    </button>
                                    <button type="button" class="fbg-dashboard-view-button" data-view-mode="cards" aria-pressed="false" title="Card view">
                                        <i class="fas fa-border-all"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="fbg-dashboard-topbar-subtitle">Overview of all your game servers.</p>
            </div>

            <?php if (empty($userServers) && !$pteroError): ?>
                <div class="fbg-dashboard-empty-wrap">
                    <section class="fbg-server-card fbg-dashboard-empty-card" aria-labelledby="dashboard-empty-title">
                        <div class="fbg-dashboard-empty-icon" aria-hidden="true">
                            <i class="fas fa-gamepad"></i>
                        </div>

                        <h2 id="dashboard-empty-title">No adventures have begun yet.</h2>

                        <p>
                            Your server dashboard is waiting for its first deployment.
                            Visit the
                            <a href="<?php echo htmlspecialchars($serversPageUrl, ENT_QUOTES, 'UTF-8'); ?>">Servers</a>
                            page to get started.
                        </p>

                        <a href="<?php echo htmlspecialchars($serversPageUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn fbg-primary-button">
                            🚀 Start Your Adventure
                        </a>
                    </section>
                </div>
            <?php endif; ?>

            <?php if (!empty($userServers)): ?>
                <section class="fbg-dashboard-summary-grid" aria-label="Server summary">
                    <article class="fbg-dashboard-summary-card">
                        <div class="fbg-dashboard-summary-icon is-blue">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="fbg-dashboard-summary-copy">
                            <span class="fbg-dashboard-summary-label">Total Servers</span>
                            <strong class="fbg-dashboard-summary-value" data-summary="total-servers"><?php echo number_format($totalServers); ?></strong>
                            <span class="fbg-dashboard-summary-sub">Across <?php echo number_format($totalNodes); ?> <?php echo $totalNodes === 1 ? 'node' : 'nodes'; ?></span>
                        </div>
                    </article>

                    <article class="fbg-dashboard-summary-card">
                        <div class="fbg-dashboard-summary-icon is-green">
                            <i class="fas fa-power-off"></i>
                        </div>
                        <div class="fbg-dashboard-summary-copy">
                            <span class="fbg-dashboard-summary-label">Running</span>
                            <strong class="fbg-dashboard-summary-value" data-summary="running"><?php echo number_format($initialRunningCount); ?></strong>
                            <span class="fbg-dashboard-summary-sub is-green" data-summary-percent="running">0% of total</span>
                        </div>
                    </article>

                    <article class="fbg-dashboard-summary-card">
                        <div class="fbg-dashboard-summary-icon is-red">
                            <i class="far fa-stop-circle"></i>
                        </div>
                        <div class="fbg-dashboard-summary-copy">
                            <span class="fbg-dashboard-summary-label">Stopped</span>
                            <strong class="fbg-dashboard-summary-value" data-summary="stopped"><?php echo number_format($initialStoppedCount); ?></strong>
                            <span class="fbg-dashboard-summary-sub is-red" data-summary-percent="stopped"><?php echo $totalServers > 0 ? number_format(($initialStoppedCount / $totalServers) * 100, 1) : '0.0'; ?>% of total</span>
                        </div>
                    </article>

                    <article class="fbg-dashboard-summary-card">
                        <div class="fbg-dashboard-summary-icon is-amber">
                            <i class="fas fa-rotate"></i>
                        </div>
                        <div class="fbg-dashboard-summary-copy">
                            <span class="fbg-dashboard-summary-label">Starting</span>
                            <strong class="fbg-dashboard-summary-value" data-summary="starting"><?php echo number_format($initialStartingCount); ?></strong>
                            <span class="fbg-dashboard-summary-sub is-amber" data-summary-percent="starting"><?php echo $totalServers > 0 ? number_format(($initialStartingCount / $totalServers) * 100, 1) : '0.0'; ?>% of total</span>
                        </div>
                    </article>

                    <article class="fbg-dashboard-summary-card">
                        <div class="fbg-dashboard-summary-icon is-purple">
                            <i class="fas fa-memory"></i>
                        </div>
                        <div class="fbg-dashboard-summary-copy">
                            <span class="fbg-dashboard-summary-label">Total Memory</span>
                            <strong class="fbg-dashboard-summary-value" data-summary="memory-total">0 Bytes</strong>
                        </div>
                    </article>

                    <article class="fbg-dashboard-summary-card">
                        <div class="fbg-dashboard-summary-icon is-gold">
                            <i class="fas fa-microchip"></i>
                        </div>
                        <div class="fbg-dashboard-summary-copy">
                            <span class="fbg-dashboard-summary-label">Total CPU</span>
                            <strong class="fbg-dashboard-summary-value" data-summary="cpu-total">0.00%</strong>
                        </div>
                    </article>
                </section>

                <div class="fbg-dashboard-servers-wrap">
                    <div class="fbg-dashboard-servers-list" data-dashboard-collection>
                        <?php foreach ($userServers as $server): ?>
                            <?php
                            $serverId = (string)($server['identifier'] ?? '');
                            if ($serverId === '') {
                                continue;
                            }

                            $isInstalling = !empty($server['is_installing']);
                            $isSuspended = !empty($server['suspended']) || strtolower(trim((string)($server['status'] ?? ''))) === 'suspended';

                            $statusClass = $isSuspended ? 'suspended' : ($isInstalling ? 'installing' : 'unknown');
                            $statusText = $isSuspended ? 'Suspended' : ($isInstalling ? 'Installing' : 'Loading...');

                            $disableStart = $isInstalling;
                            $disableStop = $isInstalling;
                            $disableRestart = $isInstalling;

                            $allocationHost = trim((string)($server['allocation_alias'] ?? ''));
                            if ($allocationHost === '') {
                                $allocationHost = trim((string)($server['allocation_ip'] ?? ''));
                            }

                            $allocationPort = trim((string)($server['allocation_port'] ?? ''));
                            $serverAddress = ($allocationHost !== '' && $allocationPort !== '')
                                ? $allocationHost . ':' . $allocationPort
                                : 'Unavailable';

                            $cardPermissions = $serverPermissionsMap[$serverId] ?? [];
                            $isOwner = !empty($serverOwnerMap[$serverId]);
                            $isPanelAdmin = !empty($serverPanelAdminMap[$serverId]);

                            $can = static function (string $permission) use ($cardPermissions, $isOwner, $isPanelAdmin): bool {
                                return $isOwner || $isPanelAdmin || in_array($permission, (array)$cardPermissions, true);
                            };

                            $canStart = $can('control.start');
                            $canStop = $can('control.stop');
                            $canRestart = $can('control.restart');

                            $canOpenPanel =
                                $can('control.console') ||
                                $can('websocket.connect') ||
                                $can('file.read') ||
                                $can('file.read-content') ||
                                $can('file.update') ||
                                $can('file.create') ||
                                $can('schedule.read') ||
                                $can('user.read') ||
                                $can('startup.read') ||
                                $can('database.read') ||
                                $can('backup.read') ||
                                $can('activity.read') ||
                                $can('settings.rename') ||
                                $isOwner ||
                                $isPanelAdmin;

                            $icon = getGameIcon($server);
                            $memoryLimitMiB = (int)($server['memory'] ?? 0);
                            $diskLimitMiB = (int)($server['disk'] ?? 0);
                            $cpuLimitValue = (int)($server['cpu'] ?? 0);

                            $ramLimit = $memoryLimitMiB === 0
                                ? 'Unlimited'
                                : round($memoryLimitMiB / 1024, 2) . ' GiB';

                            $diskLimit = $diskLimitMiB === 0
                                ? 'Unlimited'
                                : round($diskLimitMiB / 1024, 2) . ' GiB';

                            $cpuLimit = $cpuLimitValue === 0
                                ? 'Unlimited'
                                : number_format($cpuLimitValue) . '%';

                            $nodeDisplay = (string)(($server['node_name'] ?? '') ?: ('Node ID: ' . (string)($server['node_id'] ?? 'Unknown')));
                            $serverName = (string)($server['name'] ?? 'Unnamed Server');
                            $serverDescription = trim((string)($server['description'] ?? ''));
                            $ownerUsername = (string)($server['owner_username'] ?? '');
                            $accessLabel = $isPanelAdmin && !$isOwner ? 'Admin' : ($isOwner ? 'Owner' : 'Shared');

                            $searchPieces = array_filter([
                                $serverName,
                                $serverDescription,
                                $ownerUsername,
                                $nodeDisplay,
                                $serverAddress,
                                $accessLabel,
                                (string)($server['egg_name'] ?? ''),
                            ], static fn($value) => trim((string)$value) !== '');

                            $searchText = strtolower(implode(' ', $searchPieces));
                            $serverPanelUrl = './page.php?name=serverpanel&id=' . urlencode($serverId);
                            ?>
                            <article
                                class="fbg-server-card fbg-dashboard-item"
                                data-server="<?php echo htmlspecialchars($serverId, ENT_QUOTES, 'UTF-8'); ?>"
                                data-href="<?php echo htmlspecialchars($serverPanelUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>"
                                data-node="<?php echo htmlspecialchars($nodeDisplay, ENT_QUOTES, 'UTF-8'); ?>"
                                data-status="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"
                                data-memory-limit-mib="<?php echo $memoryLimitMiB; ?>"
                                data-disk-limit-mib="<?php echo $diskLimitMiB; ?>"
                                data-cpu-limit="<?php echo $cpuLimitValue; ?>"
                            >
                                <div class="fbg-dashboard-item-main">
                                    <div class="fbg-dashboard-item-top">
                                        <div class="fbg-dashboard-item-identity">
                                            <div class="fbg-dashboard-item-icon-wrap">
                                                <img src="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>" class="fbg-dashboard-game-icon" alt="">
                                            </div>

                                            <div class="fbg-dashboard-item-title-wrap">
                                                <div class="fbg-dashboard-item-heading-row">
                                                    <h2 class="fbg-dashboard-item-title"><?php echo htmlspecialchars($serverName); ?></h2>
                                                    <span class="fbg-status-badge <?php echo htmlspecialchars($statusClass); ?> server-status">
                                                        <?php echo htmlspecialchars($statusText); ?>
                                                    </span>
                                                </div>

                                                <p class="fbg-dashboard-item-description <?php echo $serverDescription === '' ? 'is-empty' : ''; ?>">
                                                    <?php echo htmlspecialchars($serverDescription !== '' ? $serverDescription : 'No description'); ?>
                                                </p>
                                            </div>

                                            <div class="fbg-dashboard-item-meta">
                                                <span class="fbg-dashboard-item-meta-line">
                                                    <i class="fas fa-ethernet"></i>
                                                    <span><?php echo htmlspecialchars($serverAddress); ?></span>
                                                </span>

                                                <span class="fbg-dashboard-item-meta-line">
                                                    <i class="fas fa-server"></i>
                                                    <span><?php echo htmlspecialchars($nodeDisplay); ?></span>
                                                </span>

                                                <span class="fbg-dashboard-item-meta-line">
                                                    <i class="fas fa-user-shield"></i>
                                                    <span>Access: <?php echo htmlspecialchars($accessLabel); ?></span>
                                                </span>

                                                <?php if ($showAllServers && $ownerUsername !== ''): ?>
                                                    <span class="fbg-dashboard-item-meta-line">
                                                        <i class="fas fa-user"></i>
                                                        <span>Owner: <?php echo htmlspecialchars($ownerUsername); ?></span>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!empty($server['expired_at'])): ?>
                                                    <span class="fbg-server-flag expired">
                                                        Expires <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$server['expired_at']))); ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($isSuspended): ?>
                                                    <span class="fbg-server-flag suspended">Suspended</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="fbg-dashboard-item-stats">
                                        <div class="fbg-dashboard-metric-card">
                                            <div class="fbg-dashboard-metric-header">
                                                <span class="fbg-dashboard-metric-label">CPU</span>
                                                <span class="fbg-dashboard-metric-value stat-cpu-usage">0.00%</span>
                                            </div>
                                            <div class="fbg-dashboard-metric-sub">of <?php echo htmlspecialchars($cpuLimit); ?></div>
                                            <div class="fbg-dashboard-metric-bar">
                                                <span class="fbg-dashboard-metric-fill is-cpu stat-cpu-fill" style="width: <?php echo $cpuLimitValue === 0 ? '100%' : '0%'; ?>"></span>
                                            </div>
                                        </div>

                                        <div class="fbg-dashboard-metric-card">
                                            <div class="fbg-dashboard-metric-header">
                                                <span class="fbg-dashboard-metric-label">Memory</span>
                                                <span class="fbg-dashboard-metric-value stat-ram-usage">0 Bytes</span>
                                            </div>
                                            <div class="fbg-dashboard-metric-sub">of <?php echo htmlspecialchars($ramLimit); ?></div>
                                            <div class="fbg-dashboard-metric-bar">
                                                <span class="fbg-dashboard-metric-fill is-memory stat-ram-fill" style="width: <?php echo $memoryLimitMiB === 0 ? '100%' : '0%'; ?>"></span>
                                            </div>
                                        </div>

                                        <div class="fbg-dashboard-metric-card">
                                            <div class="fbg-dashboard-metric-header">
                                                <span class="fbg-dashboard-metric-label">Disk</span>
                                                <span class="fbg-dashboard-metric-value stat-disk-usage">0 Bytes</span>
                                            </div>
                                            <div class="fbg-dashboard-metric-sub">of <?php echo htmlspecialchars($diskLimit); ?></div>
                                            <div class="fbg-dashboard-metric-bar">
                                                <span class="fbg-dashboard-metric-fill is-disk stat-disk-fill" style="width: <?php echo $diskLimitMiB === 0 ? '100%' : '0%'; ?>"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="fbg-dashboard-item-actions-row">
                                        <div class="fbg-dashboard-server-actions-wrap">
                                            <?php if ($canOpenPanel): ?>
                                                <a href="<?php echo htmlspecialchars($serverPanelUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm fbg-primary-button" onclick="event.stopPropagation();">
                                                    <span>Panel</span>
                                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!$isSuspended && ($canStart || $canStop || $canRestart)): ?>
                                                <div class="fbg-dashboard-server-actions fbg-server-actions" data-server="<?php echo htmlspecialchars($serverId, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php if ($canStart): ?>
                                                        <button type="button" class="btn btn-sm fbg-neutral-button btn-start" onclick="event.stopPropagation();" <?php echo $disableStart ? 'disabled' : ''; ?>>
                                                            Start
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($canRestart): ?>
                                                        <button type="button" class="btn btn-sm warn-action btn-restart" onclick="event.stopPropagation();" <?php echo $disableRestart ? 'disabled' : ''; ?>>
                                                            Restart
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($canStop): ?>
                                                        <button type="button" class="btn btn-sm danger-action btn-stop" onclick="event.stopPropagation();" <?php echo $disableStop ? 'disabled' : ''; ?>>
                                                            Stop
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <button
                                                type="button"
                                                class="btn btn-sm fbg-neutral-button"
                                                onclick="event.stopPropagation(); navigator.clipboard.writeText('<?php echo htmlspecialchars($serverAddress, ENT_QUOTES); ?>')"
                                            >
                                                <span>Copy IP</span>
                                                <i class="far fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!$isSuspended && ($canStart || $canStop || $canRestart)): ?>
                                    <div class="fbg-dashboard-alert power-msg" style="display:none;"></div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="fbg-dashboard-footer-note">
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
window.FBG_DASHBOARD = {
    csrfToken: <?php echo json_encode($csrfTokenForJs); ?>,
    totalServers: <?php echo json_encode($totalServers); ?>,
    totalNodes: <?php echo json_encode($totalNodes); ?>
};
</script>

<script src="<?php echo asset('./backend/js/dashboard.js'); ?>"></script>
