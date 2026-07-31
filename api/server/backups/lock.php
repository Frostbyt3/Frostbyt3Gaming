<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function respondLock(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondLock(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondLock(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    respondLock(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$backupUuid       = trim((string)($input['backup'] ?? ''));
$csrfToken        = (string)($input['csrf_token'] ?? '');
$shouldLock       = !empty($input['lock']);

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    respondLock(403, [
        'ok' => false,
        'error' => 'Security check failed.',
    ]);
}

if ($serverIdentifier === '' || $backupUuid === '') {
    respondLock(400, [
        'ok' => false,
        'error' => 'Missing backup request data.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'backup.delete');

    session_write_close();

    $result = pteroToggleServerBackupLock($serverIdentifier, $backupUuid);

    if (empty($result['ok'])) {
        respondLock((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to update backup lock state.',
        ]);
    }

    respondLock(200, [
        'ok' => true,
        'message' => 'Backup lock state updated.',
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    respondLock(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to update backup lock state.',
    ]);
}