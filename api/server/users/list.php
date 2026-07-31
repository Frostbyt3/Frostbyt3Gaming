<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../../pterodactyl.php';

function usersJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    usersJsonResponse(403, ['ok' => false, 'error' => 'Not authenticated.']);
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));

if ($serverIdentifier === '') {
    usersJsonResponse(400, ['ok' => false, 'error' => 'Missing server identifier.']);
}

$allowedServers = array_values(array_filter(array_map('strval', $_SESSION['allowed_servers'] ?? [])));

if (!in_array($serverIdentifier, $allowedServers, true)) {
    usersJsonResponse(403, ['ok' => false, 'error' => 'You do not have access to that server.']);
}

session_write_close();

$result = pteroListSubusers($serverIdentifier);

if (empty($result['ok'])) {
    usersJsonResponse(400, [
        'ok' => false,
        'error' => $result['error'] ?? 'Failed to load users.',
        'status_code' => (int)($result['status'] ?? 0),
    ]);
}

usersJsonResponse(200, [
    'ok' => true,
    'items' => $result['data']['data'] ?? [],
    'permission_catalog' => pteroSubuserPermissionCatalog(),
    'templates' => pteroSubuserPermissionTemplates(),
]);