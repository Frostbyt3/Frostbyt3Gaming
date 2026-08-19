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
        $placeholders = [];
        $params = [];

        foreach ($identifiers as $index => $identifier) {
            $key = ':identifier_' . $index;
            $placeholders[] = $key;
            $params[$key] = $identifier;
        }

        try {
            $stmt = fbgPteroDb()->prepare('
                SELECT uuidShort AS identifier, status
                FROM servers
                WHERE uuidShort IN (' . implode(', ', $placeholders) . ')
            ');
            $stmt->execute($params);

            foreach ($stmt->fetchAll() as $row) {
                $identifier = trim((string)($row['identifier'] ?? ''));
                if ($identifier === '') {
                    continue;
                }

                $rawStatus = strtolower(trim((string)($row['status'] ?? '')));
                $serverMeta[$identifier] = is_array($serverMeta[$identifier] ?? null)
                    ? $serverMeta[$identifier]
                    : ['identifier' => $identifier];
                $serverMeta[$identifier]['status'] = $rawStatus;
                $serverMeta[$identifier]['suspended'] = $rawStatus === 'suspended';
                $serverMeta[$identifier]['is_installing'] = in_array($rawStatus, ['installing', 'install_failed'], true);
                $serverMeta[$identifier]['install_status'] = $rawStatus;
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
