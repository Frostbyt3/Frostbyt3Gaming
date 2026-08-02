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

session_write_close();
?>

<section class="fbg-dashboard">
    <?php if ($canViewAllServers): ?>
        <div class="fbg-dashboard-header fbg-dashboard-header--compact">
            <form method="post" class="fbg-server-scope-form">
                <input type="hidden" name="server_scope_toggle" value="1">

                <label class="fbg-server-scope-toggle">
                    <span class="fbg-server-scope-label">
                        <?php echo $showAllServers ? 'Showing all servers' : 'Showing your servers'; ?>
                    </span>

                    <input
                        type="checkbox"
                        name="server_scope"
                        value="all"
                        <?php echo $showAllServers ? 'checked' : ''; ?>
                        onchange="this.form.submit()"
                    >
                    <span class="fbg-server-scope-slider" aria-hidden="true"></span>
                </label>
            </form>
        </div>
    <?php endif; ?>

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

    <?php if (empty($userServers) && !$pteroError): ?>
        <div class="fbg-dashboard-empty-wrap">
            <section class="fbg-server-card fbg-dashboard-empty-card" aria-labelledby="dashboard-empty-title">
                <div class="fbg-dashboard-empty-icon" aria-hidden="true">
                    <i class="fas fa-gamepad"></i>
                </div>

                <h1 id="dashboard-empty-title">No adventures have begun yet.</h1>

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
        <div class="fbg-dashboard-server-grid">
            <?php foreach ($userServers as $server): ?>
                <?php
                $serverId = (string)($server['identifier'] ?? '');
                if ($serverId === '') {
                    continue;
                }

                $isInstalling = !empty($server['is_installing']);
                $statusClass = $isInstalling ? 'installing' : 'unknown';
                $statusText = $isInstalling ? 'Installing' : 'Loading...';

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

                $isSuspended = !empty($server['suspended']);

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

                $ramLimit = ((int)($server['memory'] ?? 0) === 0)
                    ? 'Unlimited'
                    : round(((int)$server['memory']) / 1024, 1) . ' GiB';

                $diskLimit = ((int)($server['disk'] ?? 0) === 0)
                    ? 'Unlimited'
                    : round(((int)$server['disk'] ?? 0) / 1024, 1) . ' GiB';

                $cpuLimit = ((int)($server['cpu'] ?? 0) === 0)
                    ? 'Unlimited'
                    : number_format((int)$server['cpu']) . '%';

                $nodeDisplay = (string)(($server['node_name'] ?? '') ?: ('Node ID: ' . (string)($server['node_id'] ?? 'Unknown')));
                $serverName = (string)($server['name'] ?? 'Unnamed Server');
                $serverDescription = (string)($server['description'] ?? '');
                $ownerUsername = (string)($server['owner_username'] ?? '');
                ?>
                <article
                    class="fbg-server-card fbg-dashboard-server-card"
                    data-server="<?php echo htmlspecialchars($serverId); ?>"
                    data-href="./page.php?name=serverpanel&id=<?php echo urlencode($serverId); ?>"
                >
                    <?php if ($debug): ?>
                        <pre><?php
                            echo 'egg_name: ';
                            var_dump($server['egg_name'] ?? null);
                            echo 'server_name: ';
                            var_dump($server['name'] ?? null);
                            echo 'identifier/install: ';
                            var_dump($serverId, $server['is_installing'] ?? null, $server['install_status'] ?? null);
                        ?></pre>
                    <?php endif; ?>

                    <div class="fbg-dashboard-server-main">
                        <div class="fbg-dashboard-server-identity">
                            <div class="fbg-dashboard-server-icon-wrap">
                                <img src="<?php echo htmlspecialchars($icon); ?>" class="fbg-dashboard-game-icon" alt="">
                            </div>

                            <div class="fbg-dashboard-server-title-wrap">
                                <h2 class="fbg-dashboard-server-title">
                                    <?php echo htmlspecialchars($serverName); ?>
                                </h2>

                                <p class="fbg-dashboard-server-description <?php echo $serverDescription === '' ? 'dim' : ''; ?>">
                                    <?php echo htmlspecialchars($serverDescription !== '' ? $serverDescription : 'No description'); ?>
                                </p>

                                <?php if ($showAllServers && $ownerUsername !== ''): ?>
                                    <p class="fbg-dashboard-server-owner">
                                        Owner: <?php echo htmlspecialchars($ownerUsername); ?>
                                    </p>
                                <?php endif; ?>

                                <?php if ($isPanelAdmin && !$isOwner): ?>
                                    <p class="fbg-dashboard-server-owner">
                                        Access: Admin
                                    </p>
                                <?php elseif ($isOwner): ?>
                                    <p class="fbg-dashboard-server-owner">
                                        Access: Owner
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="fbg-dashboard-server-address">
                            <i class="fas fa-ethernet"></i>
                            <span><?php echo htmlspecialchars($serverAddress); ?></span>
                        </div>

                        <div class="fbg-dashboard-server-stat">
                            <i class="fas fa-microchip"></i>
                            <div class="fbg-dashboard-server-stat-content">
                                <div class="fbg-dashboard-server-stat-value">
                                    <span class="stat-cpu-usage">0.00%</span>
                                </div>
                                <div class="fbg-dashboard-server-stat-sub">of <?php echo htmlspecialchars($cpuLimit); ?></div>
                            </div>
                        </div>

                        <div class="fbg-dashboard-server-stat">
                            <i class="fas fa-memory"></i>
                            <div class="fbg-dashboard-server-stat-content">
                                <div class="fbg-dashboard-server-stat-value">
                                    <span class="stat-ram-usage">0 Bytes</span>
                                </div>
                                <div class="fbg-dashboard-server-stat-sub">of <?php echo htmlspecialchars($ramLimit); ?></div>
                            </div>
                        </div>

                        <div class="fbg-dashboard-server-stat">
                            <i class="fas fa-hard-drive"></i>
                            <div class="fbg-dashboard-server-stat-content">
                                <div class="fbg-dashboard-server-stat-value">
                                    <span class="stat-disk-usage">0 Bytes</span>
                                </div>
                                <div class="fbg-dashboard-server-stat-sub">of <?php echo htmlspecialchars($diskLimit); ?></div>
                            </div>
                        </div>

                        <div class="fbg-dashboard-server-status-col">
                            <span class="fbg-status-badge <?php echo htmlspecialchars($statusClass); ?> server-status">
                                <?php echo htmlspecialchars($statusText); ?>
                            </span>
                        </div>
                    </div>

                    <div class="fbg-dashboard-server-footer">
                        <div class="fbg-dashboard-server-footer-left">
                            <span class="fbg-dashboard-server-node">
                                <?php echo htmlspecialchars($nodeDisplay); ?>
                            </span>

                            <?php if (!empty($server['expired_at'])): ?>
                                <span class="fbg-server-flag expired">
                                    Expires <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$server['expired_at']))); ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($isSuspended): ?>
                                <span class="fbg-server-flag suspended">Suspended</span>
                            <?php endif; ?>
                        </div>

                        <div class="fbg-dashboard-server-actions-wrap">
                            <button
                                type="button"
                                class="btn btn-sm fbg-neutral-button"
                                onclick="event.stopPropagation(); navigator.clipboard.writeText('<?php echo htmlspecialchars($serverAddress, ENT_QUOTES); ?>')"
                            >
                                Copy IP
                            </button>

                            <?php if (!$isSuspended && ($canStart || $canStop || $canRestart)): ?>
                                <div class="fbg-dashboard-server-actions fbg-server-actions" data-server="<?php echo htmlspecialchars($serverId); ?>">
                                    <?php if ($canStart): ?>
                                        <button type="button" class="btn btn-sm fbg-neutral-button btn-start" onclick="event.stopPropagation();" <?php echo $disableStart ? 'disabled' : ''; ?>>
                                            Start
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canStop): ?>
                                        <button type="button" class="btn btn-sm danger-action btn-stop" onclick="event.stopPropagation();" <?php echo $disableStop ? 'disabled' : ''; ?>>
                                            Stop
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canRestart): ?>
                                        <button type="button" class="btn btn-sm warn-action btn-restart" onclick="event.stopPropagation();" <?php echo $disableRestart ? 'disabled' : ''; ?>>
                                            Restart
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($canOpenPanel): ?>
                                <a href="./page.php?name=serverpanel&id=<?php echo urlencode($serverId); ?>" class="btn btn-sm fbg-neutral-button" onclick="event.stopPropagation();">
                                    Panel
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$isSuspended && ($canStart || $canStop || $canRestart)): ?>
                        <div class="fbg-dashboard-alert power-msg" style="display:none; margin-top:10px;"></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
window.FBG_DASHBOARD = {
    csrfToken: <?php echo json_encode($csrfTokenForJs); ?>
};
</script>

<script src="<?php echo asset('./backend/js/dashboard.js'); ?>"></script>
