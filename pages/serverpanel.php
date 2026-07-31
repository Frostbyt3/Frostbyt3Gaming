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

/**
 * Session-first access bootstrap.
 * Only force-refresh if the requested server is missing from session access.
 */
pteroEnsureServerAccessSession(false);

$allowedServers = array_values(array_filter(array_map(
    'strval',
    $_SESSION['allowed_servers'] ?? []
)));

if (!in_array($serverIdentifier, $allowedServers, true)) {
    pteroEnsureServerAccessSession(true);

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

/**
 * If session meta is missing or incomplete, try one forced refresh.
 */
if (empty($selectedServer) || empty($selectedServer['identifier'])) {
    pteroEnsureServerAccessSession(true);
    $selectedServer = pteroGetSessionServerMeta($serverIdentifier);
}

/**
 * Pull fresh server data so sidebar/server meta reflects current allocation state.
 */
$freshServerId = (int)($selectedServer['id'] ?? 0);
$freshServer = $freshServerId > 0 ? pteroGetServer($freshServerId) : ['ok' => false];

if (!empty($freshServer['ok']) && !empty($freshServer['data'])) {
    $freshMeta = pteroSanitizeServerForSite($freshServer['data']);

    if (!empty($freshMeta['identifier'])) {
        $selectedServer = array_merge($selectedServer, $freshMeta);
    }
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

$resources = pteroGetServerResources($serverIdentifier);

$status = 'unknown';

if (is_array($resources) && !empty($resources['status'])) {
    $status = (string)$resources['status'];
}

if ($isInstalling) {
    $status = 'installing';
}

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
            'installing' => 'Installing',
            default      => 'Unknown',
        };
    }
}

if (!function_exists('fbgStatusClass')) {
    function fbgStatusClass(string $status): string
    {
        return in_array($status, ['running', 'offline', 'starting', 'stopping', 'installing'], true)
            ? $status
            : 'unknown';
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

/* =============
   TAB SYSTEM
   ============= */

$availableTabs = [];

if ($hasServerPermission('control.console') || $hasServerPermission('websocket.connect')) {
    $availableTabs['console'] = ['label' => 'Console', 'icon' => 'fas fa-terminal'];
}

if (
    $hasServerPermission('file.read') ||
    $hasServerPermission('file.read-content') ||
    $hasServerPermission('file.update') ||
    $hasServerPermission('file.create')
) {
    $availableTabs['files'] = ['label' => 'Files', 'icon' => 'fas fa-folder-open'];
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
    $resources,
    $status,
    $ramLimit,
    $diskLimit,
    $cpuLimit,
    $serverAddress
): void {
    if ($isInstalling) {
        ?>
        <div class="fbg-tab-placeholder-panel">
            <div class="fbg-server-card-header">
                <div class="fbg-server-heading-installing">
                    <br>
                    <i class="fas fa-screwdriver-wrench"></i>
                    <h1>Installing</h1>
                    <p>This server is currently being crafted.</p>
                    <p>Assuming we didn't forget any materials...</p>
                </div>
            </div>
        </div>
        <?php
        return;
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

<section class="fbg-dashboard fbg-server-panel-page">
    <div class="fbg-dashboard-header">
        <div>
            <a href="./page.php?name=dashboard" class="btn fbg-neutral-button" style="margin-bottom: 12px;">← Back to Dashboard</a>
        </div>
    </div>

    <div class="fbg-server-view-switch">
        <?php foreach ($availableTabs as $tabKey => $tabConfig): ?>
            <?php if ($tabKey === 'admin'): ?>
                <a href="https://panel.frostbyt3gaming.com/server/<?php echo urlencode($selectedServer['identifier']); ?>" target="_blank" class="fbg-server-view-tab" title="Open in Pterodactyl Panel">
                    <i class="<?php echo htmlspecialchars($tabConfig['icon']); ?>"></i>
                    <?php echo htmlspecialchars($tabConfig['label']); ?>
                    <i class="fas fa-up-right-from-square" style="margin-left: 6px; font-size: 0.75em;"></i>
                </a>
            <?php else: ?>
                <a href="./page.php?name=serverpanel&id=<?php echo urlencode($selectedServer['identifier']); ?>&tab=<?php echo urlencode($tabKey); ?>" class="fbg-server-view-tab <?php echo $serverTab === $tabKey ? 'active' : ''; ?>">
                    <i class="<?php echo htmlspecialchars($tabConfig['icon']); ?>"></i>
                    <?php echo htmlspecialchars($tabConfig['label']); ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="fbg-server-shell">
        <div class="fbg-server-main">
            <article class="fbg-server-card <?php echo $serverTab === 'console' ? 'fbg-console-panel' : 'fbg-tab-panel'; ?>" data-server="<?php echo htmlspecialchars($selectedServer['identifier']); ?>">
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

                    <div class="fbg-dashboard-alert" id="power-action-message" style="display:none; margin-top: 16px;"></div>
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
</section>

<script src="<?php echo asset('./backend/js/serverpanel/panel.js'); ?>"></script>