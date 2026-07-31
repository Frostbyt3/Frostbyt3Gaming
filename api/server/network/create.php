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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    networkJsonResponse(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$csrfToken        = (string)($input['csrf_token'] ?? '');
$serverIdentifier = trim((string)($input['id'] ?? ''));

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    networkJsonResponse(403, [
        'ok' => false,
        'error' => 'Security check failed.',
    ]);
}

if ($serverIdentifier === '') {
    networkJsonResponse(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'allocation.create');

    session_write_close();

    $result = pteroCreateServerNetworkAllocation($serverIdentifier);

    if (empty($result['ok'])) {
        networkJsonResponse((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to create allocation.',
        ]);
    }

    networkJsonResponse(200, [
        'ok' => true,
        'message' => 'Allocation created successfully.',
        'data' => [
            'item' => $result['data']['attributes'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    networkJsonResponse(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to create allocation.',
    ]);
}