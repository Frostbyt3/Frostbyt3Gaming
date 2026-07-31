<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function activityJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    activityJsonResponse(405, [
        'ok'    => false,
        'error' => 'Method not allowed.',
        'data'  => null,
    ]);
}

if (!isset($_SESSION['user_id'])) {
    activityJsonResponse(403, [
        'ok'    => false,
        'error' => 'Not authenticated.',
        'data'  => null,
    ]);
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$limit = (int)($_GET['limit'] ?? 100);
$limit = max(1, min($limit, 250));

if ($serverIdentifier === '') {
    activityJsonResponse(400, [
        'ok'    => false,
        'error' => 'Missing server identifier.',
        'data'  => null,
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'activity.read');

    $selectedServer = pteroGetSessionServerMeta($serverIdentifier);

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        pteroEnsureServerAccessSession(true);
        $selectedServer = pteroGetSessionServerMeta($serverIdentifier);
    }

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        activityJsonResponse(404, [
            'ok'    => false,
            'error' => 'Server not found.',
            'data'  => null,
        ]);
    }

    $serverId = (int)($selectedServer['id'] ?? 0);

    if ($serverId <= 0) {
        activityJsonResponse(400, [
            'ok'    => false,
            'error' => 'Unable to resolve the panel server ID.',
            'data'  => null,
        ]);
    }

    session_write_close();

    $result = pteroGetServerActivityLogs($serverId, $limit);

    if (empty($result['ok'])) {
        activityJsonResponse((int)($result['status'] ?? 500) ?: 500, [
            'ok'    => false,
            'error' => $result['error'] ?? 'Failed to load activity logs.',
            'data'  => null,
        ]);
    }

    activityJsonResponse(200, [
        'ok'    => true,
        'error' => null,
        'data'  => [
            'server' => [
                'identifier' => $serverIdentifier,
                'id' => $serverId,
            ],
            'items' => $result['data'] ?? [],
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    activityJsonResponse(500, [
        'ok'    => false,
        'error' => $message !== '' ? $message : 'Failed to load activity logs.',
        'data'  => null,
    ]);
}