<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../pterodactyl.php';

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

$csrfToken  = (string)($input['csrf_token'] ?? '');
$identifier = trim((string)($input['id'] ?? ''));
$action     = trim((string)($input['action'] ?? ''));

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)
) {
    pteroJsonError(403, 'Security check failed.');
}

if ($identifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

$allowedActions = ['start', 'stop', 'restart', 'kill'];

if (!in_array($action, $allowedActions, true)) {
    pteroJsonError(422, 'Invalid power action.');
}

$requiredPermissionMap = [
    'start'   => 'control.start',
    'stop'    => 'control.stop',
    'restart' => 'control.restart',
    'kill'    => 'control.stop',
];

$requiredPermission = $requiredPermissionMap[$action] ?? null;

if ($requiredPermission === null) {
    pteroJsonError(500, 'No permission mapping exists for that action.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($identifier, $requiredPermission);

    session_write_close();

    $result = pteroSendPowerAction($identifier, $action);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500) ?: 500);
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to send power action.',
            'data' => null,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => [
            'message' => ucfirst($action) . ' signal sent successfully.',
            'status_code' => (int)($result['status'] ?? 0),
            'action' => $action,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to send power action.');
}