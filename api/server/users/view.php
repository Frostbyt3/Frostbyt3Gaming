<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$subuserUuid      = trim((string)($_GET['subuser_uuid'] ?? ''));

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($subuserUuid === '') {
    pteroJsonError(422, 'Missing subuser UUID.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'user.read');

    $result = pteroGetSubuser($serverIdentifier, $subuserUuid);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500));
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to load subuser.',
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'item' => $result['data']['attributes'] ?? null,
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to load subuser.');
}