<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function startupJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    startupJsonResponse(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    startupJsonResponse(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));

if ($serverIdentifier === '') {
    startupJsonResponse(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'startup.read');

    /*
    |--------------------------------------------------------------------------
    | Load startup data (normalized helper)
    |--------------------------------------------------------------------------
    */
    $result = pteroGetServerStartup($serverIdentifier);

    session_write_close();

    if (empty($result['ok'])) {
        startupJsonResponse((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to load startup configuration.',
        ]);
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];

    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */
    startupJsonResponse(200, [
        'ok' => true,
        'data' => [
            'meta'      => $data['meta'] ?? [],
            'variables' => $data['data'] ?? [],
            'server'    => $data['server'] ?? [],
            'raw'       => $data,
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    startupJsonResponse(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to load startup configuration.',
    ]);
}