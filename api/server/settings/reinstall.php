<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function settingsJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    settingsJsonResponse(405, [
        'ok'    => false,
        'error' => 'Method not allowed.',
        'data'  => null,
    ]);
}

if (!isset($_SESSION['user_id'])) {
    settingsJsonResponse(403, [
        'ok'    => false,
        'error' => 'Not authenticated.',
        'data'  => null,
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    settingsJsonResponse(400, [
        'ok'    => false,
        'error' => 'Invalid request body.',
        'data'  => null,
    ]);
}

$csrfToken        = (string)($input['csrf_token'] ?? '');
$serverIdentifier = trim((string)($input['id'] ?? ''));

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    settingsJsonResponse(403, [
        'ok'    => false,
        'error' => 'Security check failed.',
        'data'  => null,
    ]);
}

if ($serverIdentifier === '') {
    settingsJsonResponse(400, [
        'ok'    => false,
        'error' => 'Missing server identifier.',
        'data'  => null,
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'settings.reinstall');

    $selectedServer = pteroGetSessionServerMeta($serverIdentifier);

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        pteroEnsureServerAccessSession(true);
        $selectedServer = pteroGetSessionServerMeta($serverIdentifier);
    }

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        settingsJsonResponse(404, [
            'ok'    => false,
            'error' => 'Server not found.',
            'data'  => null,
        ]);
    }

    $serverId = (int)($selectedServer['id'] ?? 0);

    if ($serverId <= 0) {
        settingsJsonResponse(400, [
            'ok'    => false,
            'error' => 'Invalid server ID.',
            'data'  => null,
        ]);
    }

    session_write_close();

    $result = pteroReinstallServer($serverId);

    if (empty($result['ok'])) {
        settingsJsonResponse((int)($result['status'] ?? 500), [
            'ok'    => false,
            'error' => $result['error'] ?? 'Failed to reinstall server.',
            'data'  => null,
        ]);
    }

    settingsJsonResponse(200, [
        'ok'      => true,
        'error'   => null,
        'message' => 'Server reinstall has been initiated.',
        'data'    => [
            'message' => 'Server reinstall has been initiated.',
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    settingsJsonResponse(500, [
        'ok'    => false,
        'error' => $message !== '' ? $message : 'Failed to reinstall server.',
        'data'  => null,
    ]);
}