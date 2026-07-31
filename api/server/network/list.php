<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function networkJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    networkJsonResponse(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    networkJsonResponse(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));

if ($serverIdentifier === '') {
    networkJsonResponse(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'allocation.read');

    session_write_close();

    $result = pteroGetServerNetworkAllocations($serverIdentifier);

    if (empty($result['ok'])) {
        networkJsonResponse((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to load allocations.',
        ]);
    }

    networkJsonResponse(200, [
        'ok' => true,
        'data' => [
            'items' => $result['data']['data'] ?? [],
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    networkJsonResponse(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to load allocations.',
    ]);
}