<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$serverId = (int)($selectedServer['id'] ?? 0);
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');

$canRenameSettings = $hasServerPermission('settings.rename');
$canReinstallServer = $hasServerPermission('settings.reinstall');
$canUseSftp = $hasServerPermission('file.sftp');

$currentUsername = trim((string)($_SESSION['username'] ?? $selectedServer['owner_username'] ?? 'user'));

$nodeId = (int)($selectedServer['node_id'] ?? 0);
$nodeName = strtolower(trim((string)($selectedServer['node_name'] ?? '')));
$nodeFqdn = trim((string)($selectedServer['node_fqdn'] ?? ''));
$allocationAlias = trim((string)($selectedServer['allocation_alias'] ?? ''));
$allocationIp = trim((string)($selectedServer['allocation_ip'] ?? ''));
$nodeDaemonSftp = trim((string)($selectedServer['node_daemon_sftp'] ?? ''));

if ($nodeId > 0 && ($nodeFqdn === '' || $nodeDaemonSftp === '')) {
    $nodeResult = pteroGetNode($nodeId);

    if (!empty($nodeResult['ok'])) {
        $nodeAttributes = $nodeResult['data']['attributes'] ?? $nodeResult['attributes'] ?? [];

        if (is_array($nodeAttributes) && !empty($nodeAttributes)) {
            if ($nodeFqdn === '') {
                $nodeFqdn = trim((string)($nodeAttributes['fqdn'] ?? ''));
            }

            if ($nodeDaemonSftp === '') {
                $nodeDaemonSftp = trim((string)($nodeAttributes['daemon_sftp'] ?? ''));
            }
        }
    }
}

// Public-facing node host overrides
$publicNodeHosts = [
    6 => 'node1.frostbyt3gaming.com',
    7 => 'node2.frostbyt3gaming.com',
];

// Optional fallback by node name if needed later
$publicNodeHostsByName = [
    'node 1' => 'node1.frostbyt3gaming.com',
    'node 2' => 'node2.frostbyt3gaming.com',
    'gameserver001' => 'node1.frostbyt3gaming.com',
    'gameserver002' => 'node2.frostbyt3gaming.com',
];

$preferredNodeHost = $publicNodeHosts[$nodeId]
    ?? $publicNodeHostsByName[$nodeName]
    ?? $nodeFqdn;

// Final host priority: explicit public node host > node fqdn > allocation alias > allocation ip
$sftpHost = $preferredNodeHost !== ''
    ? $preferredNodeHost
    : ($allocationAlias !== '' ? $allocationAlias : $allocationIp);

$sftpPort = $nodeDaemonSftp;

$sftpAddress = ($sftpHost !== '' && $sftpPort !== '')
    ? "sftp://{$sftpHost}:{$sftpPort}"
    : 'Unavailable';

$sftpUsername = ($currentUsername !== '' && $serverIdentifier !== '')
    ? "{$currentUsername}.{$serverIdentifier}"
    : 'Unavailable';

$launchSftpUrl = ($sftpHost !== '' && $sftpPort !== '')
    ? "sftp://{$sftpHost}:{$sftpPort}"
    : '#';

$shopCurrency = 'USD';
$userBalance = 0.00;
$renewPrice = 0.00;
$canRenewServer = false;
$renewDisabledReason = 'Renewal information is unavailable for this server. Please contact support.';
$hasValidRenewData = false;
$expiryRaw = $selectedServer['expired_at'] ?? null;
$expiryDisplay = $expiryRaw ? date('M j, Y g:i A', strtotime((string)$expiryRaw)) : null;

try {
    $pdo = fbgPteroDb();

    $userStmt = $pdo->prepare('
        SELECT credit
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $userStmt->execute([
        ':id' => (int)($_SESSION['user_id'] ?? 0),
    ]);
    $userRow = $userStmt->fetch();

    if ($userRow && isset($userRow['credit'])) {
        $userBalance = (float)$userRow['credit'];
    }

    $settingsStmt = $pdo->prepare("
        SELECT value
        FROM settings
        WHERE `key` = 'settings::shop::currency'
        LIMIT 1
    ");
    $settingsStmt->execute();
    $currencyRow = $settingsStmt->fetch();

    if ($currencyRow && !empty($currencyRow['value'])) {
        $shopCurrency = (string)$currencyRow['value'];
    }

    $renewStmt = $pdo->prepare('
        SELECT s.product_id, s.expired_at, g.price
        FROM servers s
        LEFT JOIN games g ON g.id = s.product_id
        WHERE s.id = :server_id
        LIMIT 1
    ');
    $renewStmt->execute([
        ':server_id' => $serverId,
    ]);
    $renewRow = $renewStmt->fetch();

    if ($renewRow) {
        if (empty($renewRow['product_id'])) {
            $repairedRenewRow = fbgRepairShopServerMetadataFromDefaultName($serverId);

            if ($repairedRenewRow) {
                $renewRow['product_id'] = $repairedRenewRow['product_id'];
                $renewRow['expired_at'] = $repairedRenewRow['expired_at'];
                $renewRow['price'] = $repairedRenewRow['price'];
            }
        }

        if (!empty($renewRow['expired_at'])) {
            $expiryRaw = (string)$renewRow['expired_at'];
            $expiryDisplay = date('M j, Y g:i A', strtotime($expiryRaw));
        }

        if (!empty($renewRow['product_id']) && empty($renewRow['expired_at'])) {
            $renewDisabledReason = 'This server is missing expiration information and cannot be renewed. Please contact support.';
        } elseif (!empty($renewRow['product_id']) && isset($renewRow['price'])) {
            $renewPrice = (float)$renewRow['price'];

            if ($renewPrice > 0) {
                $hasValidRenewData = true;

                if ($userBalance >= $renewPrice) {
                    $canRenewServer = true;
                    $renewDisabledReason = '';
                } else {
                    $renewDisabledReason = 'You do not have enough balance to renew this server.';
                }
            } else {
                $renewDisabledReason = 'Invalid renew price.';
            }
        } else {
            $renewDisabledReason = 'Renewal information is unavailable for this server. Please contact support.';
        }
    }
} catch (Throwable $e) {
    // Leave defaults in place.
}
?>

<div
    class="fbg-settings-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier); ?>"
    data-server-db-id="<?php echo (int)$serverId; ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>"
    data-can-rename="<?php echo $canRenameSettings ? '1' : '0'; ?>"
    data-can-reinstall="<?php echo $canReinstallServer ? '1' : '0'; ?>"
    data-can-renew="<?php echo $canRenewServer ? '1' : '0'; ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-cog"></i> Settings</h2>
            <p>Manage SFTP access, server details, reinstall actions, and shop renewal options.</p>
        </div>
    </div>

    <div class="fbg-dashboard-alert" id="settings-message" style="display:none; margin-top: 16px;"></div>

    <div class="fbg-settings-grid">
        <div class="fbg-settings-column">
            <?php if ($canUseSftp): ?>
                <section class="fbg-settings-section">
                    <div class="fbg-settings-section-header">
                        <h3>SFTP Details</h3>
                    </div>

                    <div class="fbg-settings-field-grid">
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label">Server Address</label>
                            <input
                                type="text"
                                class="fbg-text-input"
                                value="<?php echo htmlspecialchars($sftpAddress); ?>"
                                readonly
                            >
                        </div>

                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label">Username</label>
                            <input
                                type="text"
                                class="fbg-text-input"
                                value="<?php echo htmlspecialchars($sftpUsername); ?>"
                                readonly
                            >
                        </div>
                    </div>

                    <div class="fbg-settings-section-footer">
                        <p class="fbg-settings-note">
                            Your SFTP password is the same as the password you use to access this panel.
                        </p>

                        <a
                            href="<?php echo htmlspecialchars($launchSftpUrl); ?>"
                            class="btn fbg-primary-button"
                        >
                            Launch SFTP
                        </a>
                    </div>
                </section>
            <?php endif; ?>

            <section class="fbg-settings-section">
                <div class="fbg-settings-section-header">
                    <h3>Debug Information</h3>
                </div>

                <div class="fbg-settings-debug-grid">
                    <div class="fbg-settings-debug-row">
                        <span class="fbg-meta-label">Node</span>
                        <code><?php echo htmlspecialchars($selectedServer['node_name'] ?: ('Node ID: ' . $selectedServer['node_id'])); ?></code>
                    </div>

                    <div class="fbg-settings-debug-row">
                        <span class="fbg-meta-label">Server ID</span>
                        <code><?php echo htmlspecialchars((string)$selectedServer['uuid']); ?></code>
                    </div>
                </div>
            </section>

            <section class="fbg-settings-section" id="renew">
                <div class="fbg-settings-section-header">
                    <h3>Renew Server</h3>
                </div>

                <div class="fbg-settings-balance-row">
                    <span>Available Balance</span>
                    <code id="settings-balance-value">
                        <?php echo htmlspecialchars(number_format($userBalance, 2) . ' ' . $shopCurrency); ?>
                    </code>
                </div>

                <?php if ($expiryDisplay): ?>
                    <div class="fbg-settings-balance-row" id="settings-expiration-row">
                        <span>Expiration Date</span>
                        <code id="settings-expiration-value">
                            <?php echo htmlspecialchars($expiryDisplay); ?>
                        </code>
                    </div>
                <?php else: ?>
                    <div class="fbg-settings-balance-row" id="settings-expiration-row" style="display: none;">
                        <span>Expiration Date</span>
                        <code id="settings-expiration-value"></code>
                    </div>
                <?php endif; ?>

                <p class="fbg-settings-note">
                    Your server will be renewed for an additional 30 days and the cost will be deducted from your balance.
                </p>

                <?php if (!$canRenewServer && $renewDisabledReason !== ''): ?>
                    <p class="fbg-settings-renew-warning" id="settings-renew-warning">
                        <?php echo htmlspecialchars($renewDisabledReason); ?>
                    </p>
                <?php else: ?>
                    <p class="fbg-settings-renew-warning" id="settings-renew-warning" style="display: none;"></p>
                <?php endif; ?>

                <div class="fbg-settings-section-footer">
                    <div></div>
                    <button
                        type="button"
                        class="btn fbg-neutral-button"
                        id="settings-renew-button"
                        data-renew-price="<?php echo htmlspecialchars(number_format($renewPrice, 2, '.', '')); ?>"
                        data-currency="<?php echo htmlspecialchars($shopCurrency); ?>"
                        <?php echo ($canRenewServer && $hasValidRenewData) ? '' : 'disabled'; ?>
                    >
                        Renew Server - 
                        <?php echo $hasValidRenewData 
                            ? htmlspecialchars(number_format($renewPrice, 2) . ' ' . $shopCurrency) 
                            : 'Unavailable'; ?>
                    </button>
                </div>
            </section>
        </div>

        <div class="fbg-settings-column">
            <?php if ($canRenameSettings): ?>
                <section class="fbg-settings-section">
                    <div class="fbg-settings-section-header">
                        <h3>Change Server Details</h3>
                    </div>

                    <form id="settings-details-form">
                        <div class="fbg-settings-field-grid">
                            <div class="fbg-settings-field">
                                <label class="fbg-meta-label" for="settings-server-name">Server Name</label>
                                <input
                                    type="text"
                                    id="settings-server-name"
                                    class="fbg-text-input"
                                    maxlength="191"
                                    value="<?php echo htmlspecialchars((string)$selectedServer['name']); ?>"
                                >
                            </div>

                            <div class="fbg-settings-field">
                                <label class="fbg-meta-label" for="settings-server-description">Server Description</label>
                                <textarea
                                    id="settings-server-description"
                                    class="fbg-text-input fbg-settings-textarea"
                                    maxlength="191"
                                    rows="4"
                                ><?php echo htmlspecialchars((string)$selectedServer['description']); ?></textarea>
                            </div>
                        </div>

                        <div class="fbg-settings-section-footer">
                            <div></div>
                            <button type="submit" class="btn fbg-primary-button" id="settings-save-button">
                                Save
                            </button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($canReinstallServer): ?>
                <section class="fbg-settings-section">
                    <div class="fbg-settings-section-header">
                        <h3>Reinstall Server</h3>
                    </div>

                    <p class="fbg-settings-note">
                        Reinstalling your server will stop it, then re-run the installation script that initially set it up.
                        Some files may be deleted or modified during this process, so back up your data before continuing.
                    </p>

                    <div class="fbg-settings-section-footer">
                        <div></div>
                        <button type="button" class="btn danger-action" id="settings-reinstall-button">
                            Reinstall Server
                        </button>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/settings.js'); ?>"></script>
