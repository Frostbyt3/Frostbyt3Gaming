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

$csrfToken = (string)($input['csrf_token'] ?? '');
$identifier = trim((string)($input['id'] ?? ''));
$field = trim((string)($input['field'] ?? ''));
$value = trim((string)($input['value'] ?? ''));

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)
) {
    pteroJsonError(403, 'Security check failed.');
}

if ($identifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if (!in_array($field, ['name', 'description'], true)) {
    pteroJsonError(400, 'Invalid field.');
}

if ($field === 'name') {
    if ($value === '') {
        pteroJsonError(422, 'Server name cannot be empty.');
    }

    if (mb_strlen($value) > 191) {
        pteroJsonError(422, 'Server name is too long.');
    }
}

if ($field === 'description' && mb_strlen($value) > 191) {
    pteroJsonError(422, 'Description is too long.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($identifier, 'settings.rename');

    $selectedServer = pteroGetSessionServerMeta($identifier);

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        pteroEnsureServerAccessSession(true);
        $selectedServer = pteroGetSessionServerMeta($identifier);
    }

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        pteroJsonError(503, 'Server details could not be loaded right now.');
    }

    $currentName = trim((string)($selectedServer['name'] ?? ''));
    $currentDescription = trim((string)($selectedServer['description'] ?? ''));

    $newName = $field === 'name' ? $value : $currentName;
    $newDescription = $field === 'description' ? $value : $currentDescription;

    session_write_close();

    $result = pteroUpdateServerDetails(
        (int)$selectedServer['id'],
        $newName,
        $newDescription
    );

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500) ?: 500);
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to update server details.',
            'data' => null,
        ]);
        exit;
    }

    if (
        isset($_SESSION['server_meta']) &&
        is_array($_SESSION['server_meta']) &&
        isset($_SESSION['server_meta'][$identifier]) &&
        is_array($_SESSION['server_meta'][$identifier])
    ) {
        $_SESSION['server_meta'][$identifier]['name'] = $newName;
        $_SESSION['server_meta'][$identifier]['description'] = $newDescription;
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => [
            'message' => ucfirst($field) . ' updated successfully.',
            'name' => $newName,
            'description' => $newDescription,
            'field' => $field,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to update server details.');
}