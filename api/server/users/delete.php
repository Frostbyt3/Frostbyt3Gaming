<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    pteroJsonError(400, 'Invalid request body.');
}

$csrfToken        = (string)($input['csrf_token'] ?? '');
$serverIdentifier = trim((string)($input['id'] ?? ''));
$subuserUuid      = trim((string)($input['subuser_uuid'] ?? ''));

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)
) {
    pteroJsonError(403, 'Security check failed.');
}

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($subuserUuid === '') {
    pteroJsonError(422, 'Missing subuser UUID.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'user.delete');

    session_write_close();

    $result = pteroDeleteSubuser($serverIdentifier, $subuserUuid);

    $status = (int)($result['status'] ?? 0);

    if (empty($result['ok']) && $status !== 204) {
        http_response_code($status > 0 ? $status : 500);
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to delete subuser.',
            'data' => null,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => [
            'message' => 'Subuser removed successfully.',
            'subuser_uuid' => $subuserUuid,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to delete subuser.');
}