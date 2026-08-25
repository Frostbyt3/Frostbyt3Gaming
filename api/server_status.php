<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/pterodactyl.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$singleId = trim((string)($_GET['id'] ?? ''));
$rawIds   = trim((string)($_GET['ids'] ?? ''));

$requestedIds = [];

if ($rawIds !== '') {
    $requestedIds = array_values(array_unique(array_filter(array_map(
        static fn($id) => trim((string)$id),
        explode(',', $rawIds)
    ))));
} elseif ($singleId !== '') {
    $requestedIds = [$singleId];
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Missing server identifier(s)']);
    exit;
}

/**
 * Prefer already-loaded session access data for polling.
 * Avoid rebuilding the full access map on every status request.
 */
$allowedServers = $_SESSION['allowed_servers'] ?? [];
$serverMeta = $_SESSION['server_meta'] ?? [];

if (!is_array($allowedServers) || !is_array($serverMeta)) {
    pteroEnsureServerAccessSession(false);

    $allowedServers = $_SESSION['allowed_servers'] ?? [];
    $serverMeta = $_SESSION['server_meta'] ?? [];
}

$allowedServers = array_values(array_filter(array_map(
    'strval',
    is_array($allowedServers) ? $allowedServers : []
)));

$validIds = array_values(array_filter(
    $requestedIds,
    static fn($id) => in_array($id, $allowedServers, true)
));

/**
 * If none matched, try one forced refresh once.
 * This helps if the session is stale, without rebuilding on every poll.
 */
if (empty($validIds)) {
    pteroEnsureServerAccessSession(true);

    $allowedServers = array_values(array_filter(array_map(
        'strval',
        $_SESSION['allowed_servers'] ?? []
    )));

    $serverMeta = $_SESSION['server_meta'] ?? [];

    $validIds = array_values(array_filter(
        $requestedIds,
        static fn($id) => in_array($id, $allowedServers, true)
    ));
}

if (empty($validIds)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (!function_exists('fbgRefreshStatusServerMeta')) {
    function fbgSendServerInstallFinishedNotification(array $server): bool
    {
        $identifier = trim((string)($server['identifier'] ?? ''));
        $serverName = trim((string)($server['name'] ?? ''));
        $ownerEmail = trim((string)($server['owner_email'] ?? ''));

        if ($identifier === '' || $serverName === '' || $ownerEmail === '') {
            return false;
        }

        require_once __DIR__ . '/../includes/mailer.php';

        if (!function_exists('fbgSendServerInstallFinishedEmail')) {
            return false;
        }

        $baseUrl = function_exists('fbgShopBaseUrl')
            ? fbgShopBaseUrl()
            : 'https://frostbyt3gaming.com';

        $emailType = strtolower(trim((string)($server['install_completion_email_type'] ?? 'initial')));
        if (!in_array($emailType, ['initial', 'reinstall', 'modpack'], true)) {
            $emailType = 'initial';
        }

        return fbgSendServerInstallFinishedEmail([
            'type' => $emailType,
            'to_email' => $ownerEmail,
            'first_name' => trim((string)($server['owner_first_name'] ?? '')),
            'server_name' => $serverName,
            'server_panel_url' => rtrim($baseUrl, '/') . '/page.php?name=serverpanel&id=' . rawurlencode($identifier),
        ]);
    }

    function fbgRefreshStatusServerMeta(array $identifiers): array
    {
        $identifiers = array_values(array_unique(array_filter(array_map(
            static fn($identifier) => trim((string)$identifier),
            $identifiers
        ))));

        if (empty($identifiers)) {
            return is_array($_SESSION['server_meta'] ?? null) ? $_SESSION['server_meta'] : [];
        }

        $serverMeta = is_array($_SESSION['server_meta'] ?? null) ? $_SESSION['server_meta'] : [];
        $hasSuspendManualColumn = function_exists('fbgEnsurePteroServersSuspendManualColumn')
            ? fbgEnsurePteroServersSuspendManualColumn()
            : false;
        $hasInstallEmailColumn = function_exists('fbgEnsurePteroServersInstallCompletedEmailColumn')
            ? fbgEnsurePteroServersInstallCompletedEmailColumn()
            : false;
        $hasInstallEmailTypeColumn = function_exists('fbgEnsurePteroServersInstallCompletionEmailTypeColumn')
            ? fbgEnsurePteroServersInstallCompletionEmailTypeColumn()
            : false;
        $suspendManualSelect = $hasSuspendManualColumn ? 's.suspend_manual' : '0 AS suspend_manual';
        $installEmailSelect = $hasInstallEmailColumn
            ? 's.install_completed_email_sent_at'
            : 'NULL AS install_completed_email_sent_at';
        $installEmailTypeSelect = $hasInstallEmailTypeColumn
            ? 's.install_completion_email_type'
            : 'NULL AS install_completion_email_type';
        $placeholders = [];
        $params = [];

        foreach ($identifiers as $index => $identifier) {
            $key = ':identifier_' . $index;
            $placeholders[] = $key;
            $params[$key] = $identifier;
        }

        try {
            $stmt = fbgPteroDb()->prepare('
                SELECT
                    s.id,
                    s.uuidShort AS identifier,
                    s.name,
                    s.status,
                    s.expired_at,
                    ' . $suspendManualSelect . ',
                    ' . $installEmailSelect . ',
                    ' . $installEmailTypeSelect . ',
                    u.email AS owner_email,
                    u.name_first AS owner_first_name
                FROM servers s
                LEFT JOIN users u ON u.id = s.owner_id
                WHERE s.uuidShort IN (' . implode(', ', $placeholders) . ')
            ');
            $stmt->execute($params);

            foreach ($stmt->fetchAll() as $row) {
                $identifier = trim((string)($row['identifier'] ?? ''));
                if ($identifier === '') {
                    continue;
                }

                $rawStatus = strtolower(trim((string)($row['status'] ?? '')));
                $wasInstalling = !empty($serverMeta[$identifier]['is_installing']);
                $isInstalling = in_array($rawStatus, ['installing', 'install_failed'], true);
                $installEmailSentAt = trim((string)($row['install_completed_email_sent_at'] ?? ''));
                $installEmailType = strtolower(trim((string)($row['install_completion_email_type'] ?? '')));
                $serverMeta[$identifier] = is_array($serverMeta[$identifier] ?? null)
                    ? $serverMeta[$identifier]
                    : ['identifier' => $identifier];
                $serverMeta[$identifier]['id'] = (int)($row['id'] ?? 0);
                $serverMeta[$identifier]['name'] = (string)($row['name'] ?? '');
                $serverMeta[$identifier]['status'] = $rawStatus;
                $serverMeta[$identifier]['suspended'] = $rawStatus === 'suspended';
                $serverMeta[$identifier]['suspend_manual'] = !empty($row['suspend_manual']);
                $serverMeta[$identifier]['expired_at'] = (string)($row['expired_at'] ?? '');
                $serverMeta[$identifier]['is_expired'] = !empty($row['expired_at']) && strtotime((string)$row['expired_at']) <= time();
                $serverMeta[$identifier]['is_installing'] = $isInstalling;
                $serverMeta[$identifier]['install_status'] = $rawStatus;
                $serverMeta[$identifier]['install_completed_email_sent_at'] = $installEmailSentAt;
                $serverMeta[$identifier]['install_completion_email_type'] = $installEmailType;
                $serverMeta[$identifier]['owner_email'] = (string)($row['owner_email'] ?? '');
                $serverMeta[$identifier]['owner_first_name'] = (string)($row['owner_first_name'] ?? '');

                if (
                    $hasInstallEmailColumn
                    && $wasInstalling
                    && !$isInstalling
                    && $rawStatus !== 'suspended'
                    && $installEmailSentAt === ''
                ) {
                    try {
                        $sent = fbgSendServerInstallFinishedNotification($serverMeta[$identifier]);

                        if ($sent) {
                            $sentAt = date('Y-m-d H:i:s');
                            $update = fbgPteroDb()->prepare('
                                UPDATE servers
                                SET install_completed_email_sent_at = :sent_at,
                                    install_completion_email_type = NULL
                                WHERE id = :server_id
                            ');
                            $update->execute([
                                'sent_at' => $sentAt,
                                'server_id' => (int)$serverMeta[$identifier]['id'],
                            ]);
                            $serverMeta[$identifier]['install_completed_email_sent_at'] = $sentAt;
                            $serverMeta[$identifier]['install_completion_email_type'] = '';
                        }
                    } catch (Throwable $e) {
                        error_log('Unable to send server install finished notification: ' . $e->getMessage());
                    }
                }
            }

            $_SESSION['server_meta'] = $serverMeta;
        } catch (Throwable $e) {
            error_log('Unable to refresh server status metadata: ' . $e->getMessage());
        }

        return $serverMeta;
    }
}

$serverMeta = fbgRefreshStatusServerMeta($validIds);
session_write_close();

$results = [];
$resourceMap = pteroGetMultipleServerResources($validIds);

foreach ($validIds as $identifier) {
    $resources = is_array($resourceMap[$identifier] ?? null)
        ? $resourceMap[$identifier]
        : pteroEmptyServerResources();

    $meta = is_array($serverMeta[$identifier] ?? null) ? $serverMeta[$identifier] : [];
    $isInstalling = !empty($meta['is_installing']);
    $isSuspended = !empty($meta['suspended']) || strtolower(trim((string)($meta['status'] ?? ''))) === 'suspended';
    $isManualSuspension = !empty($meta['suspend_manual']);
    $isExpiredServer = !empty($meta['is_expired']);
    $canShowSuspendedRenewal = $isSuspended && !$isManualSuspension && $isExpiredServer;

    /**
     * Never turn an upstream resource/API issue into fake "Forbidden".
     * Keep the server in the result set with a safe fallback state.
     */
    $resourceStatus = strtolower(trim((string)($resources['status'] ?? 'unknown'))) ?: 'unknown';

    if (!$isInstalling && $resourceStatus === 'installing') {
        $resourceStatus = 'unknown';
    }

    if (!$isSuspended && $resourceStatus === 'suspended') {
        $resourceStatus = 'unknown';
    }

    $effectiveStatus = $isSuspended ? 'suspended' : ($isInstalling ? 'installing' : $resourceStatus);

    $results[$identifier] = [
        'status' => $effectiveStatus,
        'resource_status' => $resourceStatus,
        'install_status' => (string)($meta['install_status'] ?? ''),
        'is_installing' => $isInstalling,
        'is_suspended' => $isSuspended,
        'suspend_manual' => $isManualSuspension,
        'can_show_suspended_renewal' => $canShowSuspendedRenewal,
        'cpu' => (float)($resources['cpu'] ?? 0),
        'memory_bytes' => (int)($resources['memory_bytes'] ?? 0),
        'disk_bytes' => (int)($resources['disk_bytes'] ?? 0),
        'uptime' => (int)($resources['uptime'] ?? 0),
    ];
}

if ($singleId !== '' && count($requestedIds) === 1) {
    $identifier = $requestedIds[0];

    if (!isset($results[$identifier])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    echo json_encode($results[$identifier]);
    exit;
}

echo json_encode([
    'servers' => $results,
]);
